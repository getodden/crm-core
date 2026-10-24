<?php

declare(strict_types=1);

use Odden\Core\Demo\DemoManager;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Core\Models\DemoRecord;
use Odden\Core\Support\UserModel;

it('loads sample companies and contacts that all sit on the reserved example domain', function (): void {
    $this->artisan('odden:demo')->assertSuccessful();

    expect(Company::query()->count())->toBe(5)
        ->and(Contact::query()->count())->toBe(8)
        ->and(Activity::query()->count())->toBeGreaterThan(10)
        ->and(CrmList::query()->count())->toBe(2)
        ->and(Contact::query()->get()->reject(fn ($contact) => str_ends_with($contact->email, '.example'))->count())->toBe(0)
        ->and(Company::query()->get()->reject(fn ($company) => str_ends_with($company->domain, '.example'))->count())->toBe(0);
});

it('marks every sample record, so the list covers what was made', function (): void {
    $this->artisan('odden:demo')->assertSuccessful();

    $contact = Contact::query()->firstOrFail();

    expect(DemoRecord::isDemo($contact))->toBeTrue()
        ->and(DemoRecord::isDemo(Activity::query()->firstOrFail()))->toBeTrue();
});

it('removes the sample data and leaves your own records alone', function (): void {
    $mine = Contact::factory()->create(['email' => 'real.person@acme-actual.com']);
    $myCompany = Company::factory()->create();
    $mine->associateWith($myCompany);
    $mine->logNote('A note I wrote');

    $this->artisan('odden:demo')->assertSuccessful();
    $this->artisan('odden:demo', ['--remove' => true])->assertSuccessful();

    expect(Contact::query()->pluck('email')->all())->toBe(['real.person@acme-actual.com'])
        ->and(Company::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(1)
        ->and(CrmList::query()->count())->toBe(0)
        ->and(DemoRecord::exist())->toBeFalse()
        ->and($mine->fresh()->companies()->count())->toBe(1);
});

it('leaves one set of sample data when it is loaded twice', function (): void {
    $this->artisan('odden:demo')->assertSuccessful();
    $this->artisan('odden:demo')->assertSuccessful();

    expect(Company::query()->count())->toBe(5)
        ->and(Contact::query()->count())->toBe(8)
        ->and(CrmList::query()->count())->toBe(2);
});

it('never creates a user, and gives the first existing user the sample records', function (): void {
    $user = UserModel::className()::query()->create(['name' => 'Owner', 'email' => 'owner@acme-actual.com', 'password' => 'secret-secret']);
    $before = UserModel::className()::query()->count();

    app(DemoManager::class)->load();

    expect(UserModel::className()::query()->count())->toBe($before)
        ->and(Contact::query()->firstOrFail()->owner_id)->toBe($user->getKey());
});

it('says so when there is nothing to remove', function (): void {
    $this->artisan('odden:demo', ['--remove' => true])
        ->expectsOutputToContain('no sample data to remove')
        ->assertSuccessful();
});
