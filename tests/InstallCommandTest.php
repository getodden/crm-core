<?php

declare(strict_types=1);

use Odden\Core\Models\Contact;
use Odden\Core\Models\DemoRecord;

it('offers the sample data on a fresh install and loads it on yes', function (): void {
    $this->artisan('odden:install', ['--skip-migrate' => true, '--no-schedule' => true])
        ->expectsConfirmation('Load sample data so you can look around? It can be removed again with odden:demo --remove.', 'yes')
        ->assertSuccessful();

    expect(DemoRecord::exist())->toBeTrue();
});

it('loads nothing on no', function (): void {
    $this->artisan('odden:install', ['--skip-migrate' => true, '--no-schedule' => true])
        ->expectsConfirmation('Load sample data so you can look around? It can be removed again with odden:demo --remove.', 'no')
        ->assertSuccessful();

    expect(DemoRecord::exist())->toBeFalse()->and(Contact::query()->count())->toBe(0);
});

it('takes the sample data with a flag and never asks', function (): void {
    $this->artisan('odden:install', ['--skip-migrate' => true, '--no-schedule' => true, '--demo' => true])->assertSuccessful();

    expect(DemoRecord::exist())->toBeTrue();
});

it('does not offer the sample data when told not to', function (): void {
    $this->artisan('odden:install', ['--skip-migrate' => true, '--no-schedule' => true, '--no-demo' => true])->assertSuccessful();

    expect(DemoRecord::exist())->toBeFalse();
});

it('can be run again without loading the sample data twice', function (): void {
    $this->artisan('odden:install', ['--skip-migrate' => true, '--no-schedule' => true, '--demo' => true])->assertSuccessful();
    $this->artisan('odden:install', ['--skip-migrate' => true, '--no-schedule' => true])
        ->expectsOutputToContain('already loaded')
        ->assertSuccessful();

    expect(Contact::query()->count())->toBe(8);
});

it('warns when the queue is synchronous', function (): void {
    config(['queue.default' => 'sync']);

    $this->artisan('odden:install', ['--skip-migrate' => true, '--no-schedule' => true, '--no-demo' => true])
        ->expectsOutputToContain('QUEUE_CONNECTION is "sync"')
        ->assertSuccessful();
});
