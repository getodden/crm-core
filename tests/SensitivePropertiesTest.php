<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Odden\Core\Actions\EvaluateActiveListAction;
use Odden\Core\Areas\AllowAllAreaAccess;
use Odden\Core\Areas\AreaAccess;
use Odden\Core\Areas\AreaRedactor;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\DataArea;
use Odden\Core\Areas\DataAreas;
use Odden\Core\Contracts\KeyProvider;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Encryption\DecryptionException;
use Odden\Core\Encryption\FieldEncrypter;
use Odden\Core\Encryption\SensitiveProperties;
use Odden\Core\Encryption\SensitivePropertyConverter;
use Odden\Core\Enums\PropertyType;
use Odden\Core\Exceptions\SensitivePropertyInUse;
use Odden\Core\Exceptions\SensitivePropertyNotQueryable;
use Odden\Core\Jobs\ConvertSensitiveProperty;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Core\Models\PropertyDefinition;
use Odden\Core\Models\PropertyHistory;
use Odden\Core\Tests\Fixtures\User;

function sensitiveDefinition(string $name = 'tax_id', string $entity = 'contact', ?DataArea $area = null, PropertyType $type = PropertyType::Text, bool $sensitive = true): PropertyDefinition
{
    return PropertyDefinition::query()->create([
        'entity_type' => $entity, 'name' => $name, 'label' => ucfirst($name), 'type' => $type,
        'is_sensitive' => $sensitive, 'data_area' => $area?->value,
    ]);
}

function storedProperties(string $table, int $id): array
{
    return json_decode((string) DB::table($table)->where('id', $id)->value('properties'), true);
}

function heldOnly(array $held, array $mayReveal = []): void
{
    app()->instance(AreaAccess::class, new class($held, $mayReveal) implements AreaAccess
    {
        public function __construct(private array $held, private array $mayReveal) {}

        public function allows(DataArea $area, int|string|null $userId = null): bool
        {
            return in_array($area, $this->held[$userId] ?? [], true);
        }

        public function mayReveal(DataArea $area, int|string|null $userId = null): bool
        {
            return in_array($area, $this->mayReveal, true);
        }
    });
    app()->forgetInstance(Areas::class);
    app()->forgetInstance(AreaRedactor::class);
}

function actingAsUser(int $id): User
{
    $user = User::query()->create(['id' => $id, 'name' => "User {$id}", 'email' => "u{$id}@example.com", 'password' => 'x']);
    test()->actingAs($user);

    return $user;
}

it('stores a sensitive property encrypted inside the JSON, leaves the others readable, and reads it back as it was written', function (): void {
    sensitiveDefinition('tax_id');
    sensitiveDefinition('seats', type: PropertyType::Number);
    sensitiveDefinition('flags', type: PropertyType::Json);
    sensitiveDefinition('vip', type: PropertyType::Boolean);

    $contact = Contact::factory()->create(['properties' => [
        'tax_id' => '123-45-6789', 'seats' => 12, 'flags' => ['a' => 1, 'b' => [2, 3]], 'vip' => true, 'nickname' => 'Zed',
    ]]);

    $stored = storedProperties('odden_contacts', $contact->id);

    expect($stored['nickname'])->toBe('Zed')
        ->and(app(FieldEncrypter::class)->isEncrypted($stored['tax_id']))->toBeTrue()
        ->and(app(FieldEncrypter::class)->isEncrypted($stored['seats']))->toBeTrue()
        ->and(DB::table('odden_contacts')->where('id', $contact->id)->value('properties'))->not->toContain('123-45')->not->toContain('"a"');

    $fresh = $contact->fresh();

    expect($fresh->properties)->toBe(['tax_id' => '123-45-6789', 'seats' => 12, 'flags' => ['a' => 1, 'b' => [2, 3]], 'vip' => true, 'nickname' => 'Zed'])
        ->and($fresh->getProperty('tax_id'))->toBe('123-45-6789');
});

it('keeps the stored bytes of a sensitive value that did not change, and writes no history for it', function (): void {
    sensitiveDefinition('tax_id');
    $contact = Contact::factory()->create(['properties' => ['tax_id' => '111']]);
    $first = storedProperties('odden_contacts', $contact->id)['tax_id'];

    $contact = $contact->fresh();
    $contact->update(['properties' => [...$contact->properties, 'nickname' => 'Zed']]);

    expect(storedProperties('odden_contacts', $contact->id)['tax_id'])->toBe($first)
        ->and(PropertyHistory::query()->where('property_name', 'tax_id')->count())->toBe(0)
        ->and(PropertyHistory::query()->where('property_name', 'nickname')->count())->toBe(1);

    $contact->update(['properties' => [...$contact->properties, 'tax_id' => '222']]);

    $history = PropertyHistory::query()->where('property_name', 'tax_id')->sole();

    expect(storedProperties('odden_contacts', $contact->id)['tax_id'])->not->toBe($first)
        ->and($history->old_value)->toBe('111')
        ->and($history->new_value)->toBe('222')
        ->and(DB::table('odden_property_history')->where('id', $history->id)->value('new_value'))->not->toContain('222');
});

it('puts a change of a sensitive property in the history under the area of the property', function (): void {
    sensitiveDefinition('tax_id');
    sensitiveDefinition('region', area: DataArea::Sales);
    $contact = Contact::factory()->create(['properties' => ['tax_id' => '1', 'region' => 'north', 'nickname' => 'a']]);
    $contact->update(['properties' => ['tax_id' => '2', 'region' => 'south', 'nickname' => 'b']]);

    $history = PropertyHistory::query()->get()->keyBy('property_name');
    $areas = app(DataAreas::class);

    expect($areas->areaOfAttribute($history['tax_id'], 'new_value'))->toBe(DataArea::Sensitive)
        ->and($areas->areaOfAttribute($history['region'], 'new_value'))->toBe(DataArea::Sales)
        ->and($areas->areaOfAttribute($history['nickname'], 'new_value'))->toBe(DataArea::Identity);
});

it('defaults the area to Sensitive and keeps the one that was chosen', function (): void {
    $default = sensitiveDefinition('tax_id');
    $chosen = sensitiveDefinition('region', area: DataArea::Service);
    $plain = sensitiveDefinition('nickname', sensitive: false);

    $sensitive = app(SensitiveProperties::class);

    expect($default->fresh()->data_area)->toBe('sensitive')
        ->and($sensitive->areaOf('contact', 'tax_id'))->toBe(DataArea::Sensitive)
        ->and($sensitive->areaOf(new Contact, 'region'))->toBe(DataArea::Service)
        ->and($sensitive->areaOf('contact', 'nickname'))->toBeNull()
        ->and($sensitive->areaOf('company', 'tax_id'))->toBeNull()
        ->and($plain->fresh()->is_sensitive)->toBeFalse()
        ->and($chosen->area())->toBe(DataArea::Service);
});

it('treats a definition by class name, morph name or short name alike', function (): void {
    sensitiveDefinition('tax_id', entity: Contact::class);
    sensitiveDefinition('vat', entity: 'company');

    expect(app(SensitiveProperties::class)->isSensitive('contact', 'tax_id'))->toBeTrue()
        ->and(app(SensitiveProperties::class)->isSensitive(new Company, 'vat'))->toBeTrue();

    $contact = Contact::factory()->create(['properties' => ['tax_id' => 'x']]);

    expect(app(FieldEncrypter::class)->isEncrypted(storedProperties('odden_contacts', $contact->id)['tax_id']))->toBeTrue();
});

it('forgets a definition that changed, so the next write follows it', function (): void {
    $definition = sensitiveDefinition('tax_id', sensitive: false);
    $plain = Contact::factory()->create(['properties' => ['tax_id' => 'before']]);

    expect(storedProperties('odden_contacts', $plain->id)['tax_id'])->toBe('before');

    $definition->update(['is_sensitive' => true]);
    $later = Contact::factory()->create(['properties' => ['tax_id' => 'after']]);

    expect(storedProperties('odden_contacts', $later->id)['tax_id'])->not->toBe('after');
});

it('converts the stored values when the switch is turned on and back when it is turned off, and can be run again', function (): void {
    $definition = sensitiveDefinition('tax_id', sensitive: false);
    $withValue = Contact::factory()->count(3)->create(['properties' => ['tax_id' => 'A-1', 'nickname' => 'n']]);
    $withoutValue = Contact::factory()->create(['properties' => ['nickname' => 'n']]);
    $nothing = Contact::factory()->create(['properties' => null]);
    $converter = app(SensitivePropertyConverter::class);

    expect($converter->pending($definition))->toBe(0);

    $definition->update(['is_sensitive' => true]);

    expect($converter->pending($definition->fresh()))->toBe(3)
        // Not converted yet: still read as it is.
        ->and($withValue->first()->fresh()->properties['tax_id'])->toBe('A-1')
        ->and($converter->reconcile($definition->fresh()))->toBe(3)
        ->and($converter->reconcile($definition->fresh()))->toBe(0)
        ->and($converter->pending($definition->fresh()))->toBe(0);

    foreach ($withValue as $contact) {
        expect(app(FieldEncrypter::class)->isEncrypted(storedProperties('odden_contacts', $contact->id)['tax_id']))->toBeTrue()
            ->and($contact->fresh()->properties)->toBe(['tax_id' => 'A-1', 'nickname' => 'n']);
    }

    expect(storedProperties('odden_contacts', $withoutValue->id))->toBe(['nickname' => 'n'])
        ->and(DB::table('odden_contacts')->where('id', $nothing->id)->value('properties'))->toBeNull();

    $definition->update(['is_sensitive' => false]);

    expect($converter->pending($definition->fresh()))->toBe(3)
        ->and($converter->reconcile($definition->fresh()))->toBe(3)
        ->and($converter->reconcile($definition->fresh()))->toBe(0)
        ->and(storedProperties('odden_contacts', $withValue->first()->id))->toBe(['tax_id' => 'A-1', 'nickname' => 'n']);
});

it('converts soft-deleted records too, and companies and contacts separately', function (): void {
    $definition = sensitiveDefinition('tax_id', sensitive: false);
    $gone = Contact::factory()->create(['properties' => ['tax_id' => 'gone']]);
    $gone->delete();
    $company = Company::factory()->create(['properties' => ['tax_id' => 'company']]);

    $definition->update(['is_sensitive' => true]);
    app(SensitivePropertyConverter::class)->reconcile($definition->fresh());

    expect(app(FieldEncrypter::class)->isEncrypted(storedProperties('odden_contacts', $gone->id)['tax_id']))->toBeTrue()
        // The company has no sensitive property of that name.
        ->and(storedProperties('odden_companies', $company->id)['tax_id'])->toBe('company');
});

it('converts through the queued job, and through the command', function (): void {
    $definition = sensitiveDefinition('tax_id', sensitive: false);
    $contact = Contact::factory()->create(['properties' => ['tax_id' => 'A-1']]);
    $definition->update(['is_sensitive' => true]);

    ConvertSensitiveProperty::dispatchSync($definition->id);
    expect(app(FieldEncrypter::class)->isEncrypted(storedProperties('odden_contacts', $contact->id)['tax_id']))->toBeTrue();

    $definition->update(['is_sensitive' => false]);
    $this->artisan('odden:convert-sensitive-properties', ['--check' => true])->assertFailed();
    $this->artisan('odden:convert-sensitive-properties')->assertSuccessful();
    $this->artisan('odden:convert-sensitive-properties', ['--check' => true])->assertSuccessful();

    expect(storedProperties('odden_contacts', $contact->id)['tax_id'])->toBe('A-1');
});

it('reads a value that is encrypted even when its property is no longer sensitive', function (): void {
    $definition = sensitiveDefinition('tax_id');
    $contact = Contact::factory()->create(['properties' => ['tax_id' => 'A-1']]);

    $definition->update(['is_sensitive' => false]);

    expect($contact->fresh()->properties['tax_id'])->toBe('A-1');
});

it('will not read a sensitive value moved to another property, record type or workspace', function (): void {
    sensitiveDefinition('tax_id');
    sensitiveDefinition('other');
    $contact = Contact::factory()->create(['properties' => ['tax_id' => 'secret']]);
    $stored = storedProperties('odden_contacts', $contact->id);

    DB::table('odden_contacts')->where('id', $contact->id)->update(['properties' => json_encode(['other' => $stored['tax_id']])]);
    expect(fn () => $contact->fresh()->properties)->toThrow(DecryptionException::class);

    $company = Company::factory()->create(['properties' => null]);
    DB::table('odden_companies')->where('id', $company->id)->update(['properties' => json_encode(['tax_id' => $stored['tax_id']])]);
    // Not sensitive on companies, and the text does not decrypt there: it is read as the text it is.
    expect($company->fresh()->properties['tax_id'])->toBe($stored['tax_id']);

    // Another workspace's key cannot read it.
    config(['odden-core.encryption.key' => 'base64:'.base64_encode(random_bytes(32))]);
    app()->forgetInstance(KeyProvider::class);
    app()->forgetInstance(FieldEncrypter::class);
    DB::table('odden_contacts')->where('id', $contact->id)->update(['properties' => json_encode($stored)]);

    expect(fn () => $contact->fresh()->properties)->toThrow(DecryptionException::class);
});

it('treats text that only looks like an encrypted value as text, and encrypts it when the property becomes sensitive', function (): void {
    $definition = sensitiveDefinition('tax_id', sensitive: false);
    $contact = Contact::factory()->create(['properties' => ['tax_id' => 'odden1.abc.def']]);

    expect($contact->fresh()->properties['tax_id'])->toBe('odden1.abc.def');

    $definition->update(['is_sensitive' => true]);
    app(SensitivePropertyConverter::class)->reconcile($definition->fresh());

    expect(storedProperties('odden_contacts', $contact->id)['tax_id'])->not->toBe('odden1.abc.def')
        ->and($contact->fresh()->properties['tax_id'])->toBe('odden1.abc.def');
});

it('refuses to rename, move or delete a sensitive property, and does not offer it for searching', function (): void {
    $definition = sensitiveDefinition('tax_id');

    expect($definition->fresh()->is_searchable)->toBeFalse()
        ->and(fn () => $definition->fresh()->update(['name' => 'other']))->toThrow(SensitivePropertyInUse::class)
        ->and(fn () => $definition->fresh()->update(['entity_type' => 'company']))->toThrow(SensitivePropertyInUse::class)
        ->and(fn () => $definition->fresh()->delete())->toThrow(SensitivePropertyInUse::class);

    $definition->fresh()->update(['is_sensitive' => false]);
    $definition->fresh()->update(['name' => 'other']);
    $definition->fresh()->delete();

    expect(PropertyDefinition::query()->count())->toBe(0);
});

it('does not let the database look inside a sensitive property', function (): void {
    sensitiveDefinition('tax_id');
    sensitiveDefinition('region', sensitive: false);
    Contact::factory()->create(['properties' => ['tax_id' => 'A', 'region' => 'north']]);

    expect(fn () => Contact::query()->whereProperty('tax_id', 'A')->get())->toThrow(SensitivePropertyNotQueryable::class)
        ->and(Contact::query()->whereProperty('region', 'north')->count())->toBe(1);
});

it('evaluates a list rule on a sensitive property record by record', function (): void {
    sensitiveDefinition('tax_id');
    sensitiveDefinition('seats', type: PropertyType::Number);
    $a = Contact::factory()->create(['properties' => ['tax_id' => 'AB-100', 'seats' => 5]]);
    $b = Contact::factory()->create(['properties' => ['tax_id' => 'CD-200', 'seats' => 50]]);
    $c = Contact::factory()->create(['properties' => ['nickname' => 'x']]);

    $members = function (array $criteria): array {
        $list = CrmList::query()->create(['name' => 'L', 'entity_type' => 'contact', 'type' => 'active', 'criteria' => $criteria]);
        app(EvaluateActiveListAction::class)->execute($list);

        return $list->memberships()->pluck('member_id')->sort()->values()->all();
    };

    expect($members([['property' => 'tax_id', 'operator' => 'equals', 'value' => 'ab-100']]))->toBe([$a->id])
        ->and($members([['property' => 'tax_id', 'operator' => 'contains', 'value' => '-200']]))->toBe([$b->id])
        ->and($members([['property' => 'tax_id', 'operator' => 'in', 'value' => ['AB-100', 'CD-200']]]))->toBe([$a->id, $b->id])
        ->and($members([['property' => 'tax_id', 'operator' => 'is_set']]))->toBe([$a->id, $b->id])
        ->and($members([['property' => 'tax_id', 'operator' => 'is_not_set']]))->toBe([$c->id])
        ->and($members([['property' => 'seats', 'operator' => 'greater_than', 'value' => 10]]))->toBe([$b->id])
        ->and($members([['property' => 'seats', 'operator' => 'less_than', 'value' => 10], ['property' => 'tax_id', 'operator' => 'contains', 'value' => 'ab']]))->toBe([$a->id]);
});

it('masks a sensitive property from a person who does not hold its area, and leaves the others', function (): void {
    sensitiveDefinition('tax_id');
    sensitiveDefinition('region', area: DataArea::Sales);
    $contact = Contact::factory()->create(['properties' => ['tax_id' => '123', 'region' => 'north', 'nickname' => 'Zed']]);
    actingAsUser(2);

    heldOnly([2 => [DataArea::Identity, DataArea::Sales]]);
    expect($contact->fresh()->toArray()['properties'])->toBe(['tax_id' => null, 'region' => 'north', 'nickname' => 'Zed'])
        ->and($contact->fresh()->toJson())->not->toContain('123')
        // Code that works on the record still reads it.
        ->and($contact->fresh()->getProperty('tax_id'))->toBe('123');

    heldOnly([2 => [DataArea::Identity]]);
    expect($contact->fresh()->toArray()['properties'])->toBe(['tax_id' => null, 'region' => null, 'nickname' => 'Zed']);

    heldOnly([2 => [DataArea::Sensitive]]);
    expect($contact->fresh()->toArray()['properties'])->toBe(['tax_id' => '123', 'region' => null, 'nickname' => 'Zed']);
});

it('does not let someone change a sensitive property they cannot see', function (): void {
    sensitiveDefinition('tax_id');
    $contact = Contact::factory()->create(['properties' => ['tax_id' => '123', 'nickname' => 'Zed']]);
    actingAsUser(3);
    heldOnly([3 => [DataArea::Identity]]);

    $contact = $contact->fresh();
    $contact->update(['properties' => ['tax_id' => 'hacked', 'nickname' => 'Ann']]);

    expect($contact->fresh()->properties)->toBe(['tax_id' => '123', 'nickname' => 'Ann']);

    $new = Contact::query()->create(['first_name' => 'N', 'last_name' => 'N', 'email' => 'n@example.com', 'properties' => ['tax_id' => 'sneaky', 'nickname' => 'Q']]);

    expect($new->fresh()->properties)->toBe(['nickname' => 'Q']);
});

it('lets a person reveal a hidden sensitive property for one record with a reason', function (): void {
    sensitiveDefinition('tax_id');
    $one = Contact::factory()->create(['properties' => ['tax_id' => '123']]);
    $two = Contact::factory()->create(['properties' => ['tax_id' => '456']]);
    actingAsUser(4);
    heldOnly([4 => [DataArea::Identity]], mayReveal: [DataArea::Sensitive]);
    $areas = app(Areas::class);

    expect($areas->hiddenOn($one))->toBe([DataArea::Sensitive])
        ->and($areas->canSeeProperty($one, 'tax_id'))->toBeFalse();

    $areas->reveal(DataArea::Sensitive, $one, 'Verifying the tax number on a call');

    expect($areas->canSeeProperty($one, 'tax_id'))->toBeTrue()
        ->and($one->fresh()->toArray()['properties']['tax_id'])->toBe('123')
        ->and($two->fresh()->toArray()['properties']['tax_id'])->toBeNull();
});

it('takes a sensitive property out of what an action returns, and out of what goes to an AI provider', function (): void {
    sensitiveDefinition('tax_id');
    sensitiveDefinition('region', area: DataArea::Sales);
    $data = ['contact' => ['id' => 1, 'properties' => ['tax_id' => '123', 'region' => 'north', 'nickname' => 'Zed']]];
    heldOnly([5 => [DataArea::Identity, DataArea::Sensitive, DataArea::Sales], 6 => [DataArea::Identity]]);

    $redactor = app(AreaRedactor::class);

    expect($redactor->redact($data, [], 5, false)['contact']['properties'])->toBe(['tax_id' => '123', 'region' => 'north', 'nickname' => 'Zed'])
        ->and($redactor->redact($data, [], 5, true)['contact']['properties'])->toBe(['tax_id' => AreaRedactor::SENSITIVE, 'region' => 'north', 'nickname' => 'Zed'])
        ->and($redactor->redact($data, [], 6, false)['contact']['properties'])->toBe(['tax_id' => AreaRedactor::WITHHELD, 'region' => AreaRedactor::WITHHELD, 'nickname' => 'Zed'])
        ->and($redactor->forAi($data)['contact']['properties']['tax_id'])->toBe(AreaRedactor::SENSITIVE)
        ->and($redactor->forAi($data)['contact']['properties']['region'])->toBe('north');
});

it('shows everyone a sensitive property on a self-hosted install', function (): void {
    sensitiveDefinition('tax_id');
    $contact = Contact::factory()->create(['properties' => ['tax_id' => '123']]);
    actingAsUser(1);

    expect(app(AreaAccess::class))->toBeInstanceOf(AllowAllAreaAccess::class)
        ->and($contact->fresh()->toArray()['properties']['tax_id'])->toBe('123');
});

it('keeps one workspace\'s sensitive definitions and values apart from another\'s', function (): void {
    $tenant = new class implements TenantContext
    {
        public static int $id = 1;

        public function id(): int
        {
            return self::$id;
        }

        public function scopeUsers(Builder $users): Builder
        {
            return $users;
        }
    };
    app()->instance(TenantContext::class, $tenant);
    app()->forgetInstance(KeyProvider::class);
    app()->forgetInstance(FieldEncrypter::class);
    app()->forgetInstance(SensitiveProperties::class);

    $tenant::$id = 1;
    sensitiveDefinition('tax_id');
    $contact = Contact::factory()->create(['properties' => ['tax_id' => 'one']]);
    expect(app(SensitiveProperties::class)->isSensitive('contact', 'tax_id'))->toBeTrue();

    // Workspace 2 has its own key (and, in a real host, its own definitions): the first one's value cannot be read there.
    $tenant::$id = 2;
    app()->forgetInstance(FieldEncrypter::class);
    DB::table('odden_properties')->delete();
    app(SensitiveProperties::class)->forget();

    expect(app(SensitiveProperties::class)->isSensitive('contact', 'tax_id'))->toBeFalse()
        ->and($contact->fresh()->properties['tax_id'])->toBe(storedProperties('odden_contacts', $contact->id)['tax_id']);

    sensitiveDefinition('tax_id');
    app()->forgetInstance(FieldEncrypter::class);

    expect(fn () => $contact->fresh()->properties)->toThrow(DecryptionException::class);

    $tenant::$id = 1;
    app()->forgetInstance(FieldEncrypter::class);
    app(SensitiveProperties::class)->forget();

    expect($contact->fresh()->properties['tax_id'])->toBe('one');
});
