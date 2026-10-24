<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Contracts\KeyProvider;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Encryption\Casts\EncryptedDate;
use Odden\Core\Encryption\Casts\EncryptedJson;
use Odden\Core\Encryption\Casts\EncryptedString;
use Odden\Core\Encryption\DataKey;
use Odden\Core\Encryption\DecryptionException;
use Odden\Core\Encryption\EnvironmentKeyProvider;
use Odden\Core\Encryption\FieldEncrypter;

/**
 * A host application's idea of "the active tenant", changed from the test.
 */
final class EncryptionTestTenant implements TenantContext
{
    public static int|string|null $id = null;

    public function id(): int|string|null
    {
        return self::$id;
    }

    public function scopeUsers(Builder $users): Builder
    {
        return $users;
    }
}

final class EncryptedThing extends Model
{
    protected $table = 'things';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'name' => EncryptedString::class.':things.name',
            'born' => EncryptedDate::class.':things.born',
            'extra' => EncryptedJson::class.':things.extra',
        ];
    }
}

/**
 * The model as it comes back from the database: only the stored values.
 */
function reload(Model $model): Model
{
    return (new EncryptedThing)->newFromBuilder($model->getAttributes());
}

beforeEach(function (): void {
    EncryptionTestTenant::$id = null;
    app()->instance(TenantContext::class, new EncryptionTestTenant);
    config(['odden-core.encryption.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'odden-core.encryption.previous_keys' => [], 'odden-core.encryption.allow_plaintext' => true]);
    app()->forgetScopedInstances();
});

function encrypter(): FieldEncrypter
{
    return app(FieldEncrypter::class);
}

it('encrypts a value so that it is not readable in the stored form, and reads it back', function (): void {
    $stored = encrypter()->encrypt('pat@example.com', 'contacts.email');

    expect($stored)->toStartWith('odden1.')->not->toContain('pat@example.com')
        ->and(encrypter()->isEncrypted($stored))->toBeTrue()
        ->and(encrypter()->decrypt($stored, 'contacts.email'))->toBe('pat@example.com')
        ->and(encrypter()->encrypt('pat@example.com', 'contacts.email'))->not->toBe($stored);
});

it('keeps null as null and handles empty and non-ASCII text', function (): void {
    expect(encrypter()->encrypt(null, 'x'))->toBeNull()
        ->and(encrypter()->decrypt(null, 'x'))->toBeNull()
        ->and(encrypter()->decrypt(encrypter()->encrypt('', 'x'), 'x'))->toBe('')
        ->and(encrypter()->decrypt(encrypter()->encrypt('Zoë Müller 日本', 'x'), 'x'))->toBe('Zoë Müller 日本');
});

it('refuses a value moved to another field, altered, or not in the format', function (): void {
    $stored = encrypter()->encrypt('secret', 'contacts.phone');
    $altered = substr($stored, 0, -3).(str_ends_with($stored, 'AAA') ? 'BBB' : 'AAA');

    expect(fn () => encrypter()->decrypt($stored, 'contacts.email'))->toThrow(DecryptionException::class)
        ->and(fn () => encrypter()->decrypt($altered, 'contacts.phone'))->toThrow(DecryptionException::class)
        ->and(fn () => encrypter()->decrypt('plain text', 'contacts.phone'))->toThrow(DecryptionException::class)
        ->and(fn () => encrypter()->decrypt('odden1.unknownkey.AAAA', 'contacts.phone'))->toThrow(DecryptionException::class);
});

it('cannot read one tenant\'s value with another tenant\'s key', function (): void {
    EncryptionTestTenant::$id = 1;
    $first = encrypter()->encrypt('alpha secret', 'contacts.email');
    expect(encrypter()->decrypt($first, 'contacts.email'))->toBe('alpha secret');

    EncryptionTestTenant::$id = 2;

    expect(fn () => encrypter()->decrypt($first, 'contacts.email'))->toThrow(DecryptionException::class);

    EncryptionTestTenant::$id = 1;
    expect(encrypter()->decrypt($first, 'contacts.email'))->toBe('alpha secret');
});

it('reads old values after the secret is changed, and writes new ones with the new secret', function (): void {
    $old = encrypter()->encrypt('kept', 'f');

    config(['odden-core.encryption.key' => 'base64:'.base64_encode(str_repeat('n', 32)), 'odden-core.encryption.previous_keys' => ['base64:'.base64_encode(str_repeat('k', 32))]]);
    app()->forgetScopedInstances();

    $new = encrypter()->encrypt('fresh', 'f');

    expect(encrypter()->decrypt($old, 'f'))->toBe('kept')
        ->and(encrypter()->decrypt($new, 'f'))->toBe('fresh')
        ->and(explode('.', $new)[1])->not->toBe(explode('.', $old)[1]);

    config(['odden-core.encryption.previous_keys' => []]);
    app()->forgetScopedInstances();

    expect(fn () => encrypter()->decrypt($old, 'f'))->toThrow(DecryptionException::class);
});

it('uses the application key when no encryption key is set, and says so when there is nothing to use', function (): void {
    config(['odden-core.encryption.key' => null]);
    app()->forgetScopedInstances();

    expect(encrypter()->decrypt(encrypter()->encrypt('x', 'f'), 'f'))->toBe('x');

    config(['app.key' => '']);
    app()->forgetScopedInstances();

    expect(fn () => encrypter()->encrypt('x', 'f'))->toThrow(RuntimeException::class, 'odden:encryption-key');
});

it('builds blind indexes that match equal values only within the same tenant and field', function (): void {
    EncryptionTestTenant::$id = 1;
    $email = encrypter()->blindIndex('pat@example.com', 'contacts.email');

    expect($email)->toMatch('/^[0-9a-f]{64}$/')
        ->and(encrypter()->blindIndex('pat@example.com', 'contacts.email'))->toBe($email)
        ->and(encrypter()->blindIndex('pat@example.org', 'contacts.email'))->not->toBe($email)
        ->and(encrypter()->blindIndex('pat@example.com', 'companies.email'))->not->toBe($email)
        ->and(encrypter()->blindIndex('pat@example.com', 'contacts.email', 'word'))->not->toBe($email)
        ->and($email)->not->toContain('pat');

    EncryptionTestTenant::$id = 2;
    expect(encrypter()->blindIndex('pat@example.com', 'contacts.email'))->not->toBe($email);
});

it('uses a different key for the index than for the values', function (): void {
    $key = app(KeyProvider::class)->current();

    expect($key->derive('blind-index'))->not->toBe($key->bytes())->and(strlen($key->derive('blind-index')))->toBe(32);
});

it('never shows or serializes a key', function (): void {
    $key = app(KeyProvider::class)->current();

    ob_start();
    var_dump($key);
    $dump = ob_get_clean();

    expect($dump)->toContain($key->id)->not->toContain(bin2hex($key->bytes()))->not->toContain(base64_encode($key->bytes()))
        ->and(fn () => serialize($key))->toThrow(LogicException::class)
        ->and(fn () => new DataKey('short', 'too short'))->toThrow(LogicException::class);

    try {
        encrypter()->decrypt('odden1.'.$key->id.'.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'f');
    } catch (DecryptionException $e) {
        expect($e->getMessage())->not->toContain($key->bytes());
    }
});

it('encrypts model attributes through the casts, and reads them back as the right types', function (): void {
    $thing = new EncryptedThing;
    $thing->name = 'Pat Lee';
    $thing->born = '2012-03-04';
    $thing->extra = ['school' => 'Oak Elementary', 'grade' => 5];

    $stored = $thing->getAttributes();
    expect($stored['name'])->toStartWith('odden1.')->not->toContain('Pat')
        ->and($stored['born'])->toStartWith('odden1.')->not->toContain('2012')
        ->and($stored['extra'])->toStartWith('odden1.')->not->toContain('Oak');

    $loaded = reload($thing);

    expect($loaded->name)->toBe('Pat Lee')
        ->and($loaded->born->toDateString())->toBe('2012-03-04')
        ->and($loaded->extra)->toBe(['school' => 'Oak Elementary', 'grade' => 5]);
});

it('leaves null attributes null', function (): void {
    $thing = new EncryptedThing;
    $thing->name = null;
    $thing->born = null;
    $thing->extra = null;

    $loaded = reload($thing);

    expect($thing->getAttributes())->toBe(['name' => null, 'born' => null, 'extra' => null])
        ->and($loaded->name)->toBeNull()->and($loaded->born)->toBeNull()->and($loaded->extra)->toBeNull();
});

it('reads a plain value from before the column was encrypted only while that is allowed', function (): void {
    $legacy = (new EncryptedThing)->newFromBuilder(['name' => 'Old Plain Name', 'born' => '2001-02-03']);

    expect($legacy->name)->toBe('Old Plain Name')->and($legacy->born->toDateString())->toBe('2001-02-03');

    $legacy->name = 'Old Plain Name';
    expect($legacy->getAttributes()['name'])->toStartWith('odden1.');

    config(['odden-core.encryption.allow_plaintext' => false]);

    expect(fn () => (new EncryptedThing)->newFromBuilder(['name' => 'Old Plain Name'])->name)->toThrow(DecryptionException::class);
});

it('derives the same key for the same tenant in a new request, and a different one for another tenant', function (): void {
    $provider = new EnvironmentKeyProvider(app('config'), app(TenantContext::class));

    EncryptionTestTenant::$id = 7;
    $a = $provider->current();
    $again = (new EnvironmentKeyProvider(app('config'), app(TenantContext::class)))->current();
    EncryptionTestTenant::$id = 8;
    $b = $provider->current();

    expect($a->bytes())->toBe($again->bytes())->and($a->bytes())->not->toBe($b->bytes())->and($a->id)->toBe($b->id);
});

it('makes a key with the odden:encryption-key command', function (): void {
    $this->artisan('odden:encryption-key')->expectsOutputToContain('base64:')->assertSuccessful();
});

it('is the default key provider, and a host can replace it', function (): void {
    expect(app(KeyProvider::class))->toBeInstanceOf(EnvironmentKeyProvider::class);

    $custom = new class implements KeyProvider
    {
        public function current(): DataKey
        {
            return new DataKey('host', str_repeat('h', 32));
        }

        public function find(string $id): ?DataKey
        {
            return $id === 'host' ? $this->current() : null;
        }
    };
    app()->instance(KeyProvider::class, $custom);
    app()->forgetInstance(FieldEncrypter::class);

    expect(explode('.', encrypter()->encrypt('x', 'f'))[1])->toBe('host');
});
