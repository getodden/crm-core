<?php

declare(strict_types=1);

namespace Odden\Core\Tests;

use Odden\Core\Contracts\MailRouteResolver;
use Odden\Core\CoreServiceProvider;
use Odden\Core\Tests\Fixtures\FakeMailRouteResolver;
use Odden\Core\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

use function Orchestra\Testbench\after_resolving;
use function Orchestra\Testbench\default_migration_path;

abstract class TestCase extends Orchestra
{
    /**
     * Only this package is booted.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            CoreServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('auth.providers.users.model', User::class);

        // The suite never touches real DNS. Domains ending in .invalid have no mail server.
        $app->bind(MailRouteResolver::class, FakeMailRouteResolver::class);
    }

    /**
     * Laravel's own migrations (users, cache, jobs). Registered on the migrator rather than
     * run and rolled back per test: RefreshDatabase owns the schema, and rolling back
     * users fails on databases that enforce foreign keys (PostgreSQL, MySQL).
     */
    protected function defineDatabaseMigrations(): void
    {
        after_resolving($this->app, 'migrator', static function ($migrator): void {
            $migrator->path(default_migration_path());
            $migrator->path(__DIR__.'/Fixtures/migrations');
        });
    }
}
