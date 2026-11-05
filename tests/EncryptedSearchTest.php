<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Odden\Core\Areas\AreaAccess;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\DataArea;
use Odden\Core\Encryption\EncryptedSearch;
use Odden\Core\Encryption\Index\TooManyToFilter;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Tests\Fixtures\User;

function personHoldingNoAreas(): void
{
    app()->instance(AreaAccess::class, new class implements AreaAccess
    {
        public function allows(DataArea $area, int|string|null $userId = null): bool
        {
            return false;
        }

        public function mayReveal(DataArea $area, int|string|null $userId = null): bool
        {
            return false;
        }
    });
    app()->forgetInstance(Areas::class);
    test()->actingAs(User::query()->create(['id' => 1, 'name' => 'User 1', 'email' => 'u1@example.com', 'password' => 'x']));
}

/**
 * @param  Builder<Contact>  $query
 * @return list<int>
 */
function searchedContactIds(Builder $query, string $search): array
{
    return EncryptedSearch::contacts()($query, $search)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
}

it('finds contacts by the start of a name through the search index', function (): void {
    $ann = Contact::factory()->create(['first_name' => 'Annie', 'last_name' => 'Lee']);
    Contact::factory()->create(['first_name' => 'Bob', 'last_name' => 'Leeds']);

    expect(searchedContactIds(Contact::query(), 'ann'))->toBe([$ann->id]);
});

it('finds companies by the start of a name or by domain', function (): void {
    $acme = Company::factory()->create(['name' => 'Acme Tools', 'domain' => 'acme.test']);
    Company::factory()->create(['name' => 'Other Co', 'domain' => 'other.test']);

    expect(EncryptedSearch::companies()(Company::query(), 'acm')->pluck('id')->all())->toBe([$acme->id])
        ->and(EncryptedSearch::companies()(Company::query(), 'acme.test')->pluck('id')->all())->toBe([$acme->id]);
});

it('finds nothing when the person lacks the Identity area', function (): void {
    Contact::factory()->create(['first_name' => 'Annie']);
    Company::factory()->create(['name' => 'Acme Tools']);
    personHoldingNoAreas();

    expect(EncryptedSearch::lacks(DataArea::Identity))->toBeTrue()
        ->and(searchedContactIds(Contact::query(), 'ann'))->toBe([])
        ->and(EncryptedSearch::companies()(Company::query(), 'acm')->count())->toBe(0);
});

it('lacks the Identity area for a given user id who does not hold it, and not for nobody signed in', function (): void {
    Contact::factory()->create(['first_name' => 'Annie']);
    personHoldingNoAreas();
    auth()->logout();

    expect(EncryptedSearch::lacks(DataArea::Identity))->toBeFalse()
        ->and(EncryptedSearch::lacks(DataArea::Identity, 1))->toBeTrue()
        ->and(EncryptedSearch::contacts()(Contact::query(), 'ann')->count())->toBe(1);
});

it('sorts by decrypted columns in both directions', function (): void {
    $baker = Contact::factory()->create(['last_name' => 'Baker', 'first_name' => 'Ann']);
    $adams = Contact::factory()->create(['last_name' => 'Adams', 'first_name' => 'Zoe']);
    $clark = Contact::factory()->create(['last_name' => 'clark', 'first_name' => 'Bob']);

    $sortBy = EncryptedSearch::sortBy(['last_name', 'first_name']);

    expect($sortBy(Contact::query(), 'asc')->pluck('id')->all())->toBe([$adams->id, $baker->id, $clark->id])
        ->and($sortBy(Contact::query(), 'desc')->pluck('id')->all())->toBe([$clark->id, $baker->id, $adams->id]);
});

it('calls the callback and keeps the query unchanged above the fallback limit', function (): void {
    Contact::factory()->count(3)->create();
    config(['odden-core.encryption.fallback_limit' => 2]);
    $caught = [];

    $query = Contact::query();
    $result = EncryptedSearch::sortBy(['last_name'], function (TooManyToFilter $e) use (&$caught): void {
        $caught[] = $e;
    })($query, 'asc');

    expect($result)->toBe($query)
        ->and($caught)->toHaveCount(1)
        ->and($result->getQuery()->orders)->toBeNull();
});

it('limits first, then orders the few matches by decrypted columns', function (): void {
    $zed = Contact::factory()->create(['last_name' => 'Zed', 'first_name' => 'A']);
    $abe = Contact::factory()->create(['last_name' => 'abe', 'first_name' => 'B']);
    Contact::factory()->create(['last_name' => 'Aaron', 'first_name' => 'C']);

    $sorted = EncryptedSearch::limitedAndSorted(Contact::query(), ['last_name', 'first_name'], 2);

    expect($sorted->pluck('id')->all())->toBe([$abe->id, $zed->id])
        ->and($sorted->keys()->all())->toBe([0, 1]);
});
