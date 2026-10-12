<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Odden\Core\Actions\CreateContactAction;
use Odden\Core\Models\Contact;
use Throwable;

/**
 * Finds and creates contacts by email address, ignoring case and surrounding whitespace.
 *
 * Every entry point that turns an email address into a contact (the Service portal, chat widget
 * and inbound email, Sales meeting booking) goes through here, so one person is one contact
 * however they type their address. Contacts are always saved with the email lowercased and
 * trimmed (older rows were normalized by a migration), so the lookup is an exact match on the
 * normalized address, which uses the email index.
 */
class ContactLookup
{
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * The contact whose email matches, ignoring case and surrounding whitespace. The oldest wins.
     */
    public static function findByEmail(string $email): ?Contact
    {
        $normalized = self::normalizeEmail($email);

        if ($normalized === '') {
            return null;
        }

        $key = (new Contact)->getKeyName();

        /** @var Contact|null $contact */
        $contact = Contact::query()
            ->where('email', $normalized)
            ->orderBy($key)
            ->first();

        return $contact;
    }

    /**
     * How long a lock on one address lasts, and how long a second caller waits for it.
     */
    private const int LOCK_SECONDS = 10;

    /**
     * Lock keys this process holds until its open database transaction ends, with the time
     * each lock expires.
     *
     * @var array<string, float>
     */
    private static array $heldUntilTransactionEnds = [];

    /**
     * The matching contact, or a new one created through CreateContactAction (so ContactCreated
     * fires and the email is checked) with the normalized email and the given attributes.
     *
     * There is no unique index on email (contacts are per team, soft-deleted, and older data can
     * hold duplicates), so this is the one way to create a contact from an address without
     * risking a duplicate. Callers for the same address are serialized with a cache lock, and
     * the lookup runs again once the lock is held, so only one contact is created. When the
     * caller is inside a database transaction the lock is kept until that transaction commits:
     * until then other connections can't see the new contact.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function findOrCreate(string $email, array $attributes = []): Contact
    {
        $normalized = self::normalizeEmail($email);

        $findOrCreate = function () use ($normalized, $attributes): Contact {
            return self::findByEmail($normalized)
                ?? app(CreateContactAction::class)->execute([...$attributes, 'email' => $normalized]);
        };

        if ($normalized === '' || ! Cache::getStore() instanceof LockProvider) {
            return $findOrCreate();
        }

        $key = CacheKey::for('odden:contact-email:'.sha1($normalized));

        // This process already holds the lock for the address until its transaction ends, and its
        // own connection sees the contact it created, so waiting would only wait for itself.
        if ((self::$heldUntilTransactionEnds[$key] ?? 0) > microtime(true)) {
            return $findOrCreate();
        }

        $lock = Cache::lock($key, self::LOCK_SECONDS);

        try {
            $lock->block(self::LOCK_SECONDS);
        } catch (LockTimeoutException) {
            // The holder took longer than its lock lasts. Look again, and create without the
            // lock rather than lose the contact.
            return $findOrCreate();
        } catch (Throwable) {
            // The lock store is unreachable: it guards against duplicates but must not stop
            // contacts from being created.
            return $findOrCreate();
        }

        try {
            $contact = $findOrCreate();
        } catch (Throwable $e) {
            self::release($lock, $key);

            throw $e;
        }

        self::releaseWhenTransactionEnds($lock, $key, $contact->getConnection());

        return $contact;
    }

    private static function releaseWhenTransactionEnds(Lock $lock, string $key, Connection $connection): void
    {
        $release = fn () => self::release($lock, $key);

        if ($connection->transactionLevel() === 0) {
            $release();

            return;
        }

        self::$heldUntilTransactionEnds[$key] = microtime(true) + self::LOCK_SECONDS;

        // Runs at once when no transaction of the application is open (a test's own wrapping
        // transaction doesn't count). A rolled-back transaction leaves the lock to expire after
        // LOCK_SECONDS (Laravel 12.0 has no afterRollBack()).
        $connection->afterCommit($release);
    }

    private static function release(Lock $lock, string $key): void
    {
        unset(self::$heldUntilTransactionEnds[$key]);

        try {
            $lock->release();
        } catch (Throwable) {
            // The lock expires on its own.
        }
    }
}
