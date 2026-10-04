<?php

declare(strict_types=1);

namespace Odden\Core;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Odden\Core\Actions\SummarizeTimelineAction;
use Odden\Core\Contracts\SummarizesTimeline;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Support\Enrichment\EnrichmentManager;
use Odden\Core\Support\LifecycleStateMachine;
use Odden\Core\Support\ModelRegistry;
use Odden\Core\Support\NullTenantContext;

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

        // The briefing is rule-based unless an application or add-on rebinds the contract to another implementation.
        $this->app->bindIf(SummarizesTimeline::class, SummarizeTimelineAction::class);

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

        if ($this->app->runningInConsole()) {
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
