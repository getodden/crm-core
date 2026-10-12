<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Actions\MergeCompaniesAction;
use Odden\Core\Actions\MergeContactsAction;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Exceptions\CrossTenantException;
use Odden\Core\Models\Association;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\TenantGuard;

function inTenant(int|string|null $tenant): void
{
    app()->instance(TenantContext::class, new class($tenant) implements TenantContext
    {
        public function __construct(private int|string|null $tenant) {}

        public function id(): int|string|null
        {
            return $this->tenant;
        }

        public function scopeUsers(Builder $users): Builder
        {
            return $users;
        }
    });
    app()->forgetInstance(TenantGuard::class);
}

// The tenancy add-on adds a `tenant_id` column; here the attribute is set in memory, which is all
// the guard reads.
function ownedBy(Model $record, int|string|null $tenant): Model
{
    return $record->setAttribute('tenant_id', $tenant);
}

it('does nothing on a single-tenant install', function (): void {
    $contact = Contact::factory()->create();
    $company = Company::factory()->create();

    expect($contact->associateWith($company))->toBeInstanceOf(Association::class);

    $primary = Contact::factory()->create();
    $secondary = Contact::factory()->create();
    expect(app(MergeContactsAction::class)->execute($primary, $secondary)->is($primary))->toBeTrue();
});

it('links records of the same tenant', function (): void {
    inTenant(7);
    $contact = ownedBy(Contact::factory()->create(), 7);
    $company = ownedBy(Company::factory()->create(), '7');

    expect($contact->associateWith($company))->toBeInstanceOf(Association::class);
});

it('refuses to associate records of different tenants', function (): void {
    $contact = ownedBy(Contact::factory()->create(), 1);
    $company = ownedBy(Company::factory()->create(), 2);

    $contact->associateWith($company);
})->throws(CrossTenantException::class, 'different tenants');

it('refuses to associate a tenant record with an unowned one', function (): void {
    $contact = ownedBy(Contact::factory()->create(), 1);
    $company = Company::factory()->create();

    $contact->associateWith($company);
})->throws(CrossTenantException::class);

it('refuses records outside the active tenant even when they match each other', function (): void {
    inTenant(1);
    $contact = ownedBy(Contact::factory()->create(), 2);
    $company = ownedBy(Company::factory()->create(), 2);

    $contact->associateWith($company);
})->throws(CrossTenantException::class, 'active tenant');

it('writes nothing when the guard refuses', function (): void {
    $contact = ownedBy(Contact::factory()->create(), 1);
    $company = ownedBy(Company::factory()->create(), 2);

    try {
        $contact->associateWith($company);
    } catch (CrossTenantException) {
    }

    expect(Association::query()->count())->toBe(0);
});

it('refuses to merge contacts of different tenants and leaves both untouched', function (): void {
    $primary = ownedBy(Contact::factory()->create(['first_name' => 'Keep']), 1);
    $secondary = ownedBy(Contact::factory()->create(), 2);

    expect(fn () => app(MergeContactsAction::class)->execute($primary, $secondary))->toThrow(CrossTenantException::class);

    expect(Contact::query()->whereKey($secondary->getKey())->exists())->toBeTrue()
        ->and(Contact::onlyTrashed()->count())->toBe(0);
});

it('refuses to merge companies of different tenants', function (): void {
    $primary = ownedBy(Company::factory()->create(), 1);
    $secondary = ownedBy(Company::factory()->create(), 2);

    app(MergeCompaniesAction::class)->execute($primary, $secondary);
})->throws(CrossTenantException::class);

it('reads null for models without a tenant column', function (): void {
    expect(app(TenantGuard::class)->tenantOf(Contact::factory()->create()))->toBeNull();
});

it('only applies association types from the active tenant', function (): void {
    Schema::table('odden_association_types', fn (Blueprint $table) => $table->unsignedBigInteger('tenant_id')->nullable());
    DB::table('odden_association_types')->insert([
        'name' => 'reseller', 'label' => 'Reseller', 'cardinality' => 'one_to_one', 'tenant_id' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);

    inTenant(1);
    $company = ownedBy(Company::factory()->create(), 1);
    $contact = ownedBy(Contact::factory()->create(), 1);

    try {
        expect($company->associateWith($contact, 'reseller')->association_type_id)->toBeNull();
    } finally {
        // MySQL commits on DDL, so don't leave the column for later tests.
        DB::table('odden_association_types')->where('name', 'reseller')->delete();
        Schema::table('odden_association_types', fn (Blueprint $table) => $table->dropColumn('tenant_id'));
    }
});
