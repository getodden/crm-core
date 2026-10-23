<?php

declare(strict_types=1);

namespace Odden\Core\Registry\Actions;

use Odden\Core\Models\Contact;
use Odden\Core\Registry\ActionFailedException;
use Odden\Core\Registry\ActionResult;
use Odden\Core\Registry\Actor;

class UpdateContact extends BaseAction
{
    private const array FIELDS = ['first_name', 'last_name', 'job_title', 'phone', 'timezone'];

    public static function name(): string
    {
        return 'contacts.update';
    }

    public static function description(): string
    {
        return 'Change a contact\'s name, job title, phone number or timezone. Send only the fields to change. Use contacts.search first to get the id. It does not change email address or lifecycle stage.';
    }

    public static function rules(): array
    {
        return [
            'contact_id' => ['required', 'integer'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function handle(Actor $actor, array $input): ActionResult
    {
        $contact = Contact::query()->find((int) $input['contact_id']);

        if ($contact === null) {
            throw new ActionFailedException('There is no contact with that id.');
        }

        // A key that was sent changes the field (null clears it); a key left out leaves it alone.
        $changes = array_intersect_key($input, array_flip(self::FIELDS));

        if ($changes === []) {
            throw new ActionFailedException('Nothing to change: send at least one field.');
        }

        $contact->update($changes);

        return ActionResult::completed(['id' => $contact->id, 'changed' => array_keys($changes)], null, $contact->getMorphClass(), $contact->id);
    }
}
