<?php

declare(strict_types=1);

namespace Odden\Core;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Odden\Core\Actions\SummarizeTimelineAction;
use Odden\Core\Console\Commands\DemoCommand;
use Odden\Core\Console\Commands\EncryptExistingCommand;
use Odden\Core\Console\Commands\EncryptionKeyCommand;
use Odden\Core\Console\Commands\InstallCommand;
use Odden\Core\Console\InstallSteps;
use Odden\Core\Contracts\KeyProvider;
use Odden\Core\Contracts\MailRouteResolver;
use Odden\Core\Contracts\SummarizesTimeline;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Demo\CoreDemoSeeder;
use Odden\Core\Demo\DemoRegistry;
use Odden\Core\Encryption\ColumnConverter;
use Odden\Core\Encryption\EncryptedColumns;
use Odden\Core\Encryption\EnvironmentKeyProvider;
use Odden\Core\Encryption\FieldEncrypter;
use Odden\Core\Encryption\Index\DecryptedFilter;
use Odden\Core\Encryption\Index\FallbackLimit;
use Odden\Core\Encryption\Index\SearchIndex;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Registry\ActionExecutor;
use Odden\Core\Registry\ActionGate;
use Odden\Core\Registry\ActionRegistry;
use Odden\Core\Registry\Actions;
use Odden\Core\Registry\AllowAllActionGate;
use Odden\Core\Support\ContactLookup;
use Odden\Core\Support\Enrichment\EnrichmentManager;
use Odden\Core\Support\LifecycleStateMachine;
use Odden\Core\Support\ModelRegistry;
use Odden\Core\Support\NullTenantContext;
use Odden\Core\Support\SystemMailRouteResolver;

class CoreServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/odden-core.php', 'odden-core');

        $this->app->singleton(LifecycleStateMachine::class, function (): LifecycleStateMachine {
            return new LifecycleStateMachine;
        });

        $this->app->singleton(EnrichmentManager::class, function (): EnrichmentManager {
            return new EnrichmentManager;
        });

        // Odden is single-tenant unless a multi-tenant host rebinds the tenant context.
        $this->app->singletonIf(TenantContext::class, NullTenantContext::class);

        // Personal data is encrypted with a key from the environment unless a multi-tenant host rebinds the key provider to one
        // key per tenant. Scoped, so nothing derived from a key outlives the request or the queued job.
        $this->app->scopedIf(KeyProvider::class, EnvironmentKeyProvider::class);
        $this->app->scoped(FieldEncrypter::class);
        $this->app->scoped(ColumnConverter::class);
        $this->app->singleton(EncryptedColumns::class);
        $this->app->scoped(SearchIndex::class);
        $this->app->bindIf(FallbackLimit::class, FallbackLimit::class);
        $this->app->scoped(DecryptedFilter::class);

        // The briefing is rule-based unless an application or add-on rebinds the contract to another implementation.
        $this->app->bindIf(SummarizesTimeline::class, SummarizeTimelineAction::class);

        // Whether a domain accepts mail comes from DNS unless an application rebinds the contract.
        $this->app->bindIf(MailRouteResolver::class, SystemMailRouteResolver::class);

        // Modules add their own checks to `odden:install` here.
        $this->app->singleton(InstallSteps::class);

        // Each module adds its sample-data seeder to this list; `odden:demo` runs them in order.
        $this->app->singleton(DemoRegistry::class);
        $this->callAfterResolving(DemoRegistry::class, fn (DemoRegistry $registry) => $registry->register(CoreDemoSeeder::class));

        // Every package registers the actions it offers (people, the assistant and the MCP server all run them) here. A self-hosted
        // install lets everyone do everything; a host application rebinds the gate to its own permission checks.
        $this->app->singleton(ActionRegistry::class);
        $this->callAfterResolving(ActionRegistry::class, fn (ActionRegistry $registry) => $registry->register(
            Actions\SearchContacts::class,
            Actions\CreateContact::class,
            Actions\UpdateContact::class,
            Actions\AddNote::class,
            Actions\CreateTask::class,
        ));
        $this->app->singletonIf(ActionGate::class, AllowAllActionGate::class);
        $this->app->singleton(ActionExecutor::class);

        // Every package registers its models here; resolved lazily so provider order doesn't matter.
        $this->app->singleton(ModelRegistry::class);
        $this->callAfterResolving(ModelRegistry::class, function (ModelRegistry $registry): void {
            $registry->discover(__DIR__.'/Models', 'Odden\\Core\\Models');
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Limiters for Odden's public routes: "odden-public" for browser-facing submissions
        // (forms, chat, portal replies), "odden-poll" for read endpoints that clients call
        // repeatedly (chat polling, article suggestions), "odden-api" for token-authenticated
        // server-to-server calls (webhooks, sending APIs). Per IP, per minute. Each module
        // (the first two segments of the route name, e.g. "odden.service") counts separately,
        // so a visitor using chat is not throttled by also submitting a marketing form.
        RateLimiter::for('odden-public', fn (Request $request): Limit => Limit::perMinute((int) config('odden-core.rate_limits.public', 30))->by(self::rateLimitKey($request)));
        RateLimiter::for('odden-poll', fn (Request $request): Limit => Limit::perMinute((int) config('odden-core.rate_limits.poll', 120))->by(self::rateLimitKey($request)));
        RateLimiter::for('odden-api', fn (Request $request): Limit => Limit::perMinute((int) config('odden-core.rate_limits.api', 600))->by(self::rateLimitKey($request)));

        $columns = $this->app->make(EncryptedColumns::class);
        $columns->add(fn (): string => config('odden-core.tables.contacts', 'odden_contacts'), 'date_of_birth', 'contacts.date_of_birth', fn (string $value): string => Date::parse($value)->toDateString());
        foreach (['first_name', 'last_name', 'job_title', 'phone', 'linkedin_url', 'timezone'] as $column) {
            $columns->add(fn (): string => config('odden-core.tables.contacts', 'odden_contacts'), $column, 'contacts.'.$column);
        }
        $columns->add(fn (): string => config('odden-core.tables.contacts', 'odden_contacts'), 'email', 'contacts.email', fn (string $value): string => ContactLookup::normalizeEmail($value));
        foreach (['name', 'domain', 'phone'] as $column) {
            $columns->add(fn (): string => config('odden-core.tables.companies', 'odden_companies'), $column, 'companies.'.$column);
        }
        $columns->addIndexed(Contact::class);
        $columns->addIndexed(Company::class);
        $columns->add(fn (): string => config('odden-core.tables.activities', 'odden_activities'), 'title', 'activities.title');
        $columns->add(fn (): string => config('odden-core.tables.activities', 'odden_activities'), 'body', 'activities.body');
        $columns->add(fn (): string => config('odden-core.tables.activities', 'odden_activities'), 'metadata', 'activities.metadata');
        $columns->add(fn (): string => config('odden-core.tables.property_history', 'odden_property_history'), 'old_value', 'property_history.old_value');
        $columns->add(fn (): string => config('odden-core.tables.property_history', 'odden_property_history'), 'new_value', 'property_history.new_value');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, DemoCommand::class, EncryptionKeyCommand::class, EncryptExistingCommand::class]);

            $this->publishes([
                __DIR__.'/../config/odden-core.php' => config_path('odden-core.php'),
            ], 'odden-core-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'odden-core-migrations');
        }
    }

    /**
     * Rate limit bucket: the client IP plus the route's module, so modules don't share a counter.
     */
    protected static function rateLimitKey(Request $request): string
    {
        $name = (string) $request->route()?->getName();
        $module = implode('.', array_slice(explode('.', $name), 0, 2));

        return $request->ip().'|'.$module;
    }
}
