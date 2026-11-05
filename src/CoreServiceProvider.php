<?php

declare(strict_types=1);

namespace Odden\Core;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Odden\Core\Actions\SummarizeTimelineAction;
use Odden\Core\Areas\AllowAllAreaAccess;
use Odden\Core\Areas\AreaAccess;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\DataArea;
use Odden\Core\Areas\DataAreas;
use Odden\Core\Console\Commands\ConvertBusinessDataCommand;
use Odden\Core\Console\Commands\ConvertSensitivePropertiesCommand;
use Odden\Core\Console\Commands\DemoCommand;
use Odden\Core\Console\Commands\EncryptExistingCommand;
use Odden\Core\Console\Commands\EncryptionKeyCommand;
use Odden\Core\Console\Commands\InstallCommand;
use Odden\Core\Console\InstallSteps;
use Odden\Core\Contracts\BusinessData;
use Odden\Core\Contracts\KeyProvider;
use Odden\Core\Contracts\MailRouteResolver;
use Odden\Core\Contracts\SummarizesTimeline;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Demo\CoreDemoSeeder;
use Odden\Core\Demo\DemoRegistry;
use Odden\Core\Encryption\BusinessDataColumns;
use Odden\Core\Encryption\BusinessDataConverter;
use Odden\Core\Encryption\ColumnConverter;
use Odden\Core\Encryption\ConfigBusinessData;
use Odden\Core\Encryption\EncryptedAmounts;
use Odden\Core\Encryption\EncryptedColumns;
use Odden\Core\Encryption\EnvironmentKeyProvider;
use Odden\Core\Encryption\FieldEncrypter;
use Odden\Core\Encryption\Index\DecryptedFilter;
use Odden\Core\Encryption\Index\FallbackLimit;
use Odden\Core\Encryption\Index\SearchIndex;
use Odden\Core\Encryption\SensitiveProperties;
use Odden\Core\Encryption\SensitivePropertyConverter;
use Odden\Core\Logging\LogScrubber;
use Odden\Core\Logging\ScrubLogs;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Registry\ActionExecutor;
use Odden\Core\Registry\ActionGate;
use Odden\Core\Registry\ActionRegistry;
use Odden\Core\Registry\Actions;
use Odden\Core\Registry\AllowAllActionGate;
use Odden\Core\Screens\CoreScreens;
use Odden\Core\Screens\NoScreenTenantParameters;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Core\Screens\ScreenTenantParameters;
use Odden\Core\Support\ContactLookup;
use Odden\Core\Support\Enrichment\EnrichmentManager;
use Odden\Core\Support\LifecycleStateMachine;
use Odden\Core\Support\ModelRegistry;
use Odden\Core\Support\NullTenantContext;
use Odden\Core\Support\SystemMailRouteResolver;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/odden-core.php', 'odden-core');

        $this->app->singleton(LifecycleStateMachine::class, function (): LifecycleStateMachine {
            return new LifecycleStateMachine;
        });

        $this->app->singleton(EnrichmentManager::class, function (): EnrichmentManager {
            return new EnrichmentManager;
        });

        $this->app->singletonIf(TenantContext::class, NullTenantContext::class);

        $this->app->scopedIf(KeyProvider::class, EnvironmentKeyProvider::class);
        $this->app->scoped(FieldEncrypter::class);
        $this->app->scoped(ColumnConverter::class);
        $this->app->singleton(EncryptedColumns::class);
        $this->app->scoped(SearchIndex::class);
        $this->app->scoped(SensitiveProperties::class);
        $this->app->scoped(SensitivePropertyConverter::class);

        $this->app->singletonIf(BusinessData::class, ConfigBusinessData::class);
        $this->app->singleton(BusinessDataColumns::class);
        $this->app->scoped(BusinessDataConverter::class);
        $this->app->scoped(EncryptedAmounts::class);
        $this->app->bindIf(FallbackLimit::class, FallbackLimit::class);
        $this->app->scoped(DecryptedFilter::class);

        $this->app->singleton(DataAreas::class);
        $this->app->singletonIf(AreaAccess::class, AllowAllAreaAccess::class);
        $this->app->scoped(Areas::class);
        $this->callAfterResolving(DataAreas::class, function (DataAreas $areas): void {
            $areas->fields([
                'contacts.first_name' => DataArea::Identity, 'contacts.last_name' => DataArea::Identity, 'contacts.email' => DataArea::Identity,
                'contacts.phone' => DataArea::Identity, 'contacts.job_title' => DataArea::Identity, 'contacts.linkedin_url' => DataArea::Identity,
                'contacts.timezone' => DataArea::Identity, 'contacts.date_of_birth' => DataArea::Sensitive,
                'companies.name' => DataArea::Identity, 'companies.domain' => DataArea::Identity, 'companies.phone' => DataArea::Identity,
                'activities.title' => DataArea::Sales, 'activities.body' => DataArea::Sales, 'activities.metadata' => DataArea::Sales,
                'property_history.old_value' => DataArea::Identity, 'property_history.new_value' => DataArea::Identity,
                'action_log.input' => DataArea::Sales, 'action_log.error' => DataArea::Sales,
                'proposed_actions.input' => DataArea::Sales, 'proposed_actions.error' => DataArea::Sales,
                'lists.criteria' => DataArea::Identity,
                'custom_object_records.name' => DataArea::Identity, 'custom_object_records.properties' => DataArea::Identity,
            ]);
            $areas->tables(self::tableAreas());
        });

        $this->app->bindIf(SummarizesTimeline::class, SummarizeTimelineAction::class);

        $this->app->bindIf(MailRouteResolver::class, SystemMailRouteResolver::class);

        $this->app->singleton(InstallSteps::class);

        $this->app->singleton(DemoRegistry::class);
        $this->callAfterResolving(DemoRegistry::class, fn (DemoRegistry $registry) => $registry->register(CoreDemoSeeder::class));

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

        $this->app->singletonIf(ScreenTenantParameters::class, NoScreenTenantParameters::class);
        $this->app->singleton(ScreenRegistry::class);
        $this->callAfterResolving(ScreenRegistry::class, fn (ScreenRegistry $screens) => CoreScreens::register($screens));

        $this->app->singleton(ModelRegistry::class);
        $this->callAfterResolving(ModelRegistry::class, function (ModelRegistry $registry): void {
            $registry->discover(__DIR__.'/Models', 'Odden\\Core\\Models');
            $registry->registerTable(config('odden-core.tables.search_index', 'odden_search_index'));
        });
    }

    private function scrubLogs(): void
    {
        if (! config('odden-core.logging.scrub', true)) {
            return;
        }

        foreach (array_keys((array) config('logging.channels', [])) as $channel) {
            $taps = (array) config("logging.channels.{$channel}.tap", []);

            if (! in_array(ScrubLogs::class, $taps, true)) {
                config(["logging.channels.{$channel}.tap" => [...$taps, ScrubLogs::class]]);
            }
        }

        $events = $this->app->make('events');
        $events->listen(RequestHandled::class, LogScrubber::flush(...));
        $events->listen(JobProcessing::class, LogScrubber::flush(...));
        $events->listen(JobProcessed::class, LogScrubber::flush(...));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->scrubLogs();

        $this->app->booted(function (): void {
            $this->app->afterResolving(ScreenRegistry::class, fn (ScreenRegistry $screens) => $screens->freeze());
            $this->app->make(ScreenRegistry::class)->freeze();
        });

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
        $columns->add(fn (): string => config('odden-core.tables.action_log', 'odden_action_log'), 'input', 'action_log.input');
        $columns->add(fn (): string => config('odden-core.tables.action_log', 'odden_action_log'), 'error', 'action_log.error');
        $columns->add(fn (): string => config('odden-core.tables.proposed_actions', 'odden_proposed_actions'), 'input', 'proposed_actions.input', function (string $value): string {
            try {
                return Crypt::decryptString($value);
            } catch (DecryptException) {
                return $value;
            }
        });
        $columns->add(fn (): string => config('odden-core.tables.proposed_actions', 'odden_proposed_actions'), 'error', 'proposed_actions.error');
        $columns->add(fn (): string => config('odden-core.tables.lists', 'odden_lists'), 'criteria', 'lists.criteria');
        $columns->add(fn (): string => config('odden-core.tables.custom_object_records', 'odden_custom_object_records'), 'name', 'custom_object_records.name');
        $columns->add(fn (): string => config('odden-core.tables.custom_object_records', 'odden_custom_object_records'), 'properties', 'custom_object_records.properties');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, DemoCommand::class, EncryptionKeyCommand::class, EncryptExistingCommand::class, ConvertSensitivePropertiesCommand::class, ConvertBusinessDataCommand::class]);

            $this->publishes([
                __DIR__.'/../config/odden-core.php' => config_path('odden-core.php'),
            ], 'odden-core-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'odden-core-migrations');
        }
    }

    /**
     * @return array<string, DataArea>
     */
    private static function tableAreas(): array
    {
        $identity = ['contacts', 'companies', 'search_index', 'associations', 'association_types', 'lists', 'list_memberships', 'properties', 'property_history',
            'custom_object_definitions', 'custom_object_records', 'lifecycle_stage_transitions', 'demo_records'];
        $sales = ['activities', 'mentions', 'action_log', 'proposed_actions'];

        return [
            ...array_combine(array_map(fn (string $t): string => 'odden_'.$t, $identity), array_fill(0, count($identity), DataArea::Identity)),
            ...array_combine(array_map(fn (string $t): string => 'odden_'.$t, $sales), array_fill(0, count($sales), DataArea::Sales)),
        ];
    }

    protected static function rateLimitKey(Request $request): string
    {
        $name = (string) $request->route()?->getName();
        $module = implode('.', array_slice(explode('.', $name), 0, 2));

        return $request->ip().'|'.$module;
    }
}
