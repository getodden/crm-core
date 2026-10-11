<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Odden\Core\Models\Contact;
use Odden\Core\Support\EmailValidator;

class ValidateContactEmailAction
{
    public function __construct(protected EmailValidator $validator) {}

    /**
     * Check the contact's email address and record the outcome on the contact.
     *
     * Saved quietly: a deliverability result is not an edit by a person, so it should not
     * touch the property history or fire update listeners.
     */
    public function execute(Contact $contact, bool $checkDns = true): Contact
    {
        // A bounce is stronger evidence than a DNS lookup can give, so it is not re-checked away.
        if ($contact->hasBounced()) {
            return $contact;
        }

        $check = $this->validator->check((string) $contact->email, $checkDns);

        $contact->forceFill([
            'email_status' => $check->status,
            'email_status_reason' => $check->reason,
            'email_checked_at' => now(),
        ])->saveQuietly();

        return $contact;
    }
}
