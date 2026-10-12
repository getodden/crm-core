<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Odden\Core\Actions\CreateContactAction;
use Odden\Core\Models\Contact;

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
     * The matching contact, or a new one created through CreateContactAction (so ContactCreated
     * fires and the email is checked) with the normalized email and the given attributes.
     *
     * Two callers creating the same address at once are serialized with a cache lock, so only one
     * contact is created.
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

        return Cache::lock(CacheKey::for('odden:contact-email:'.sha1($normalized)), 10)->block(10, $findOrCreate);
    }
}
