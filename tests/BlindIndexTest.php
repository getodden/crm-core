<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Odden\Core\Actions\FindDuplicateCompaniesAction;
use Odden\Core\Actions\FindDuplicateContactsAction;
use Odden\Core\Encryption\ColumnConverter;
use Odden\Core\Encryption\EncryptedColumns;
use Odden\Core\Encryption\FieldEncrypter;
use Odden\Core\Encryption\Index\DecryptedFilter;
use Odden\Core\Encryption\Index\FallbackLimit;
use Odden\Core\Encryption\Index\IndexText;
use Odden\Core\Encryption\Index\SearchIndex;
use Odden\Core\Encryption\Index\TooManyToFilter;
use Odden\Core\Enums\ListType;
use Odden\Core\Exceptions\InvalidListCriteriaException;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Core\Support\ContactLookup;

function indexedRows(string $field, int $recordId): int
{
    return DB::table('odden_search_index')->where('field', $field)->where('record_id', $recordId)->count();
}

function listOf(array $criteria, string $entity = 'contact'): CrmList
{
    $list = CrmList::create(['name' => 'L'.uniqid(), 'entity_type' => $entity, 'type' => ListType::Active, 'criteria' => $criteria]);
    $list->syncActiveMembers();

    return $list;
}

function memberIds(CrmList $list): array
{
    return $list->memberships()->pluck('member_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
}

it('reduces values the way the index expects', function (): void {
    expect(IndexText::normalize(IndexText::EMAIL, '  Ann@Example.COM '))->toBe('ann@example.com')
        ->and(IndexText::normalize(IndexText::DIGITS, '+1 (555) 010-1234'))->toBe('15550101234')
        ->and(IndexText::words("Anne-Marie O'Neil"))->toBe(['anne', 'marie', 'o', 'neil'])
        ->and(IndexText::prefixes('Lee'))->toBe(['le', 'lee'])
        ->and(IndexText::searchTerm('a'))->toBe(['word', 'a'])
        ->and(IndexText::searchTerm('an'))->toBe(['prefix', 'an'])
        ->and(IndexText::searchTerm(str_repeat('x', 25)))->toBe(['word', str_repeat('x', 25)]);
});

it('keeps the index in step with the record: written on create, replaced on change, removed on a permanent delete', function (): void {
    $contact = Contact::factory()->create(['email' => 'First@Example.com', 'first_name' => 'Annie', 'last_name' => 'Lee']);

    expect(Contact::query()->whereEmail('first@example.com')->pluck('id')->all())->toBe([$contact->id])
        ->and(indexedRows('contacts.email', $contact->id))->toBe(1);

    $contact->update(['email' => 'second@example.com']);

    expect(Contact::query()->whereEmail('first@example.com')->exists())->toBeFalse()
        ->and(Contact::query()->whereEmail('SECOND@example.com')->pluck('id')->all())->toBe([$contact->id])
        ->and(indexedRows('contacts.email', $contact->id))->toBe(1);

    // A save that changes nothing indexed leaves the rows alone.
    $before = DB::table('odden_search_index')->count();
    $contact->update(['lead_score' => 5]);
    expect(DB::table('odden_search_index')->count())->toBe($before);

    // Soft delete keeps the rows (the model hides the record); force delete removes them.
    $contact->delete();
    expect(Contact::query()->whereEmail('second@example.com')->exists())->toBeFalse()
        ->and(DB::table('odden_search_index')->where('record_id', $contact->id)->where('field', 'like', 'contacts.%')->exists())->toBeTrue();

    $contact->forceDelete();
    expect(DB::table('odden_search_index')->where('record_id', $contact->id)->where('field', 'like', 'contacts.%')->exists())->toBeFalse();
});

it('finds a phone number whatever its punctuation, and a name by word and by the start of a word', function (): void {
    $ann = Contact::factory()->create(['first_name' => 'Annie', 'last_name' => 'Lee-Smith', 'phone' => '(555) 010-1234']);
    $bob = Contact::factory()->create(['first_name' => 'Bob', 'last_name' => 'Leeds', 'phone' => null]);

    expect(Contact::query()->whereBlindExact('phone', '555.010.1234')->pluck('id')->all())->toBe([$ann->id])
        ->and(Contact::query()->whereNameStartsWith('ann')->pluck('id')->all())->toBe([$ann->id])
        ->and(Contact::query()->whereNameStartsWith('ann le')->pluck('id')->all())->toBe([$ann->id])
        ->and(Contact::query()->whereNameStartsWith('lee')->pluck('id')->sort()->values()->all())->toBe([$ann->id, $bob->id])
        ->and(Contact::query()->whereNameStartsWith('smith')->pluck('id')->all())->toBe([$ann->id])
        ->and(Contact::query()->whereNameStartsWith('mith')->exists())->toBeFalse()
        ->and(Contact::query()->whereBlindWord('last_name', 'Lee')->pluck('id')->all())->toBe([$ann->id])
        ->and(Contact::query()->whereNameStartsWith('')->exists())->toBeFalse();
});

it('does not match across fields or tenants: the same text hashes differently', function (): void {
    $index = app(SearchIndex::class);

    expect($index->hash('contacts.email', 'exact', 'a@b.co'))->not->toBe($index->hash('contacts.phone', 'exact', 'a@b.co'))
        ->and($index->hash('contacts.email', 'exact', 'a@b.co'))->not->toBe($index->hash('contacts.email', 'word', 'a@b.co'));
});

it('stores only keyed hashes in the index, never a value', function (): void {
    Contact::factory()->create(['email' => 'secret.person@example.com', 'first_name' => 'Zinnia', 'last_name' => 'Quartz']);

    $dump = DB::table('odden_search_index')->get()->map(fn ($row): string => implode(' ', (array) $row))->implode(' ');

    expect($dump)->not->toContain('zinnia')->not->toContain('Zinnia')->not->toContain('quartz')->not->toContain('secret');
});

it('finds duplicates by email, phone and name without decrypting, and not across the visible scope', function (): void {
    $a = Contact::factory()->create(['email' => 'one@example.com', 'phone' => '555 0101', 'first_name' => 'Pat', 'last_name' => 'Kay']);
    $b = Contact::factory()->create(['email' => 'ONE@example.com', 'phone' => null, 'first_name' => 'X', 'last_name' => 'Y']);
    $c = Contact::factory()->create(['email' => 'three@example.com', 'phone' => '(555) 0101', 'first_name' => 'Pat', 'last_name' => 'Kay']);
    Contact::factory()->create(['email' => 'four@example.com', 'phone' => null, 'first_name' => 'Solo', 'last_name' => 'Person']);

    $found = collect(app(FindDuplicateContactsAction::class)->execute())->mapWithKeys(fn (array $g): array => [$g['match_field'] => [$g['match_value'], $g['contacts']->pluck('id')->all()]]);

    expect($found['email'])->toBe(['one@example.com', [$a->id, $b->id]])
        // The phone group {a, c} is also the name group {a, c}: it is reported once, under the first field.
        ->and($found['phone'])->toBe(['5550101', [$a->id, $c->id]])
        ->and($found)->not->toHaveKey('name');

    $b->delete();
    expect(collect(app(FindDuplicateContactsAction::class)->execute())->pluck('match_field')->all())->toBe(['phone']);
});

it('finds duplicate companies by domain and by name, ignoring case', function (): void {
    $a = Company::factory()->create(['name' => 'Acme', 'domain' => 'acme.com']);
    $b = Company::factory()->create(['name' => 'ACME ', 'domain' => 'other.com']);
    $c = Company::factory()->create(['name' => 'Globex', 'domain' => 'ACME.com']);

    $found = collect(app(FindDuplicateCompaniesAction::class)->execute())->mapWithKeys(fn (array $g): array => [$g['match_field'] => $g['companies']->pluck('id')->all()]);

    expect($found['domain'])->toBe([$a->id, $c->id])
        ->and($found['name'])->toBe([$a->id, $b->id])
        ->and(Company::query()->whereDomain('https://www.Acme.com/about')->pluck('id')->all())->toBe([$a->id, $c->id])
        ->and(Company::query()->whereNameStartsWith('acm')->pluck('id')->all())->toBe([$a->id, $b->id]);
});

it('evaluates list rules on encrypted fields through the index', function (): void {
    $ann = Contact::factory()->create(['email' => 'ann@acme.com', 'first_name' => 'Ann', 'last_name' => 'Lee', 'job_title' => 'Head of Sales', 'timezone' => 'Europe/Paris']);
    $bob = Contact::factory()->create(['email' => 'bob@acme.com', 'first_name' => 'Bob', 'last_name' => 'Day', 'job_title' => 'Engineer', 'timezone' => null]);
    $cy = Contact::factory()->create(['email' => 'cy@other.com', 'first_name' => 'Cy', 'last_name' => 'Lee', 'job_title' => null, 'timezone' => 'Europe/Paris']);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(memberIds(listOf([['property' => 'email', 'operator' => 'equals', 'value' => 'ANN@acme.com']])))->toBe([$ann->id])
        ->and(memberIds(listOf([['property' => 'last_name', 'operator' => '=', 'value' => 'lee']])))->toBe([$ann->id, $cy->id])
        ->and(memberIds(listOf([['property' => 'last_name', 'operator' => '!=', 'value' => 'Lee']])))->toBe([$bob->id])
        ->and(memberIds(listOf([['property' => 'email', 'operator' => 'in', 'value' => ['bob@acme.com', 'cy@other.com']]])))->toBe([$bob->id, $cy->id])
        ->and(memberIds(listOf([['property' => 'email', 'operator' => 'not_in', 'value' => ['bob@acme.com']]])))->toBe([$ann->id, $cy->id])
        ->and(memberIds(listOf([['property' => 'job_title', 'operator' => 'is_not_set']])))->toBe([$cy->id])
        ->and(memberIds(listOf([['field' => 'job_title', 'operator' => 'is_set'], ['field' => 'last_name', 'operator' => '=', 'value' => 'Lee']])))->toBe([$ann->id]);

    // Index rules are answered by the database: nothing was decrypted to answer them.
    expect(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'odden_search_index')))->toBeTrue();
});

it('checks the rules the index cannot answer in PHP, on the records the other rules left', function (): void {
    $ann = Contact::factory()->create(['first_name' => 'Ann', 'job_title' => 'Head of Sales', 'timezone' => 'Europe/Paris', 'lifecycle_stage' => 'customer']);
    $bob = Contact::factory()->create(['first_name' => 'Bob', 'job_title' => 'Sales Engineer', 'timezone' => 'Europe/Paris', 'lifecycle_stage' => 'lead']);
    $cy = Contact::factory()->create(['first_name' => 'Cy', 'job_title' => 'Designer', 'timezone' => 'Asia/Tokyo', 'lifecycle_stage' => 'lead']);

    expect(memberIds(listOf([['property' => 'job_title', 'operator' => 'contains', 'value' => 'SALES']])))->toBe([$ann->id, $bob->id])
        ->and(memberIds(listOf([['property' => 'timezone', 'operator' => 'equals', 'value' => 'europe/paris'], ['property' => 'lifecycle_stage', 'operator' => '=', 'value' => 'lead']])))->toBe([$bob->id])
        ->and(memberIds(listOf([['property' => 'timezone', 'operator' => 'not_in', 'value' => ['Europe/Paris']]])))->toBe([$cy->id]);

    // The list follows the records as they change, in both directions.
    $list = listOf([['property' => 'job_title', 'operator' => 'contains', 'value' => 'sales']]);
    $bob->update(['job_title' => 'Gardener']);
    $cy->update(['job_title' => 'Sales Lead']);
    $list->syncActiveMembers();

    expect(memberIds($list))->toBe([$ann->id, $cy->id]);
});

it('does not offer greater than or less than on an encrypted field', function (): void {
    Contact::factory()->create();

    expect(fn () => listOf([['property' => 'last_name', 'operator' => '>', 'value' => 'M']]))->toThrow(InvalidListCriteriaException::class, 'encrypted');
});

it('refuses to decrypt more records than the limit allows, and names the way out', function (): void {
    Contact::factory()->count(5)->create(['job_title' => 'Sales', 'lifecycle_stage' => 'lead']);
    config(['odden-core.encryption.fallback_limit' => 3]);

    expect(fn () => listOf([['property' => 'job_title', 'operator' => 'contains', 'value' => 'sale']]))->toThrow(TooManyToFilter::class, 'Narrow it down');

    // Narrowed by a plain field first, the same rule is within the limit.
    Contact::query()->whereKey(Contact::query()->limit(3)->pluck('id'))->update(['lifecycle_stage' => 'customer']);

    expect(memberIds(listOf([['property' => 'lifecycle_stage', 'operator' => '=', 'value' => 'customer'], ['property' => 'job_title', 'operator' => 'contains', 'value' => 'sale']])))->toHaveCount(3);
});

it('lets a host rebind the limit', function (): void {
    app()->bind(FallbackLimit::class, fn () => new class extends FallbackLimit
    {
        public function records(): int
        {
            return 2;
        }
    });
    Contact::factory()->count(3)->create();

    expect(fn () => app(DecryptedFilter::class)->ids(Contact::query(), ['first_name'], fn () => true))->toThrow(TooManyToFilter::class);
});

it('sorts by decrypted names, nulls last, and orders a query by the result', function (): void {
    $c = Contact::factory()->create(['last_name' => 'clark', 'first_name' => 'Bob']);
    $a = Contact::factory()->create(['last_name' => 'Adams', 'first_name' => 'Zoe']);
    $n = Contact::factory()->create(['last_name' => null, 'first_name' => 'Nobody']);
    $b = Contact::factory()->create(['last_name' => 'Baker', 'first_name' => 'Ann']);

    $filter = app(DecryptedFilter::class);
    $ids = $filter->sortedIds(Contact::query(), ['last_name', 'first_name']);

    expect($ids)->toBe([$a->id, $b->id, $c->id, $n->id])
        ->and($filter->sortedIds(Contact::query(), ['last_name'], descending: true))->toBe([$c->id, $b->id, $a->id, $n->id])
        ->and(DecryptedFilter::orderByIds(Contact::query(), $ids)->pluck('id')->all())->toBe($ids);
});

it('orders a query by a list of ids however long the list is', function (): void {
    $ids = Contact::factory()->count(40)->create()->pluck('id')->shuffle()->values()->all();

    expect(DecryptedFilter::orderByIds(Contact::query(), $ids)->pluck('id')->all())->toBe($ids)
        ->and(DecryptedFilter::orderByIds(Contact::query(), array_slice($ids, 0, 7))->limit(7)->pluck('id')->all())->toBe(array_slice($ids, 0, 7));
});

it('converts plain rows to ciphertext with index rows, and does it again without harm', function (): void {
    $id = DB::table('odden_contacts')->insertGetId([
        'email' => 'Plain@Example.com', 'first_name' => 'Plain', 'last_name' => 'Person', 'phone' => '555 1', 'lifecycle_stage' => 'lead',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $company = DB::table('odden_companies')->insertGetId(['name' => 'Plain Co', 'domain' => 'plain.example', 'created_at' => now(), 'updated_at' => now()]);

    // Readable and not yet findable.
    expect(Contact::query()->find($id)->first_name)->toBe('Plain')
        ->and(Contact::query()->whereEmail('plain@example.com')->exists())->toBeFalse();

    foreach (range(1, 2) as $_) {
        $this->artisan('odden:encrypt-existing')->assertSuccessful();
    }

    $encrypter = app(FieldEncrypter::class);
    $row = DB::table('odden_contacts')->where('id', $id)->first();

    expect($encrypter->isEncrypted($row->email))->toBeTrue()
        ->and($encrypter->isEncrypted($row->first_name))->toBeTrue()
        ->and($encrypter->isEncrypted(DB::table('odden_companies')->where('id', $company)->value('domain')))->toBeTrue()
        ->and(ContactLookup::findByEmail('PLAIN@example.com')?->id)->toBe($id)
        ->and(Contact::query()->whereNameStartsWith('pla per')->pluck('id')->all())->toBe([$id])
        ->and(Company::query()->whereDomain('plain.example')->pluck('id')->all())->toBe([$company])
        ->and(indexedRows('contacts.email', $id))->toBe(1);

    config(['odden-core.encryption.allow_plaintext' => false]);

    expect(Contact::query()->find($id)->email)->toBe('plain@example.com');
});

it('converts only the rows a scope allows, index rows included', function (): void {
    $kept = DB::table('odden_contacts')->insertGetId(['email' => 'k@example.com', 'team_id' => 1, 'lifecycle_stage' => 'lead', 'created_at' => now(), 'updated_at' => now()]);
    $other = DB::table('odden_contacts')->insertGetId(['email' => 'o@example.com', 'team_id' => 2, 'lifecycle_stage' => 'lead', 'created_at' => now(), 'updated_at' => now()]);

    app(EncryptedColumns::class)->convert(
        app(ColumnConverter::class),
        fn ($query, string $table) => $table === 'odden_contacts' ? $query->where('team_id', 1) : $query->whereRaw('1 = 0'),
    );

    expect(app(FieldEncrypter::class)->isEncrypted(DB::table('odden_contacts')->where('id', $kept)->value('email')))->toBeTrue()
        ->and(DB::table('odden_contacts')->where('id', $other)->value('email'))->toBe('o@example.com')
        ->and(indexedRows('contacts.email', $kept))->toBe(1)
        ->and(indexedRows('contacts.email', $other))->toBe(0);
});
