<?php

declare(strict_types=1);

namespace Odden\Core\Registry\Actions;

use Odden\Core\Actions\CreateContactAction;
use Odden\Core\Areas\DataArea;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Registry\ActionResult;
use Odden\Core\Registry\Actor;
use Odden\Core\Support\ContactLookup;

class CreateContact extends BaseAction
{
    public static function name(): string
    {
        return 'contacts.create';
    }

    public static function description(): string
    {
        return 'Add a contact. An email address is required. If a contact with that address already exists, nothing is created and the existing contact is returned.';
    }

    public static function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'lifecycle_stage' => ['nullable', 'string', 'in:'.implode(',', array_column(LifecycleStage::cases(), 'value'))],
        ];
    }

    public function areas(): array
    {
        return [
            'email' => DataArea::Identity,
            'first_name' => DataArea::Identity,
            'last_name' => DataArea::Identity,
            'job_title' => DataArea::Identity,
            'phone' => DataArea::Identity,
        ];
    }

    public function handle(Actor $actor, array $input): ActionResult
    {
        $existing = ContactLookup::findByEmail((string) $input['email']);

        if ($existing !== null) {
            return ActionResult::completed(['id' => $existing->id, 'created' => false], 'A contact with that email address already exists.', $existing->getMorphClass(), $existing->id);
        }

        $contact = app(CreateContactAction::class)->execute(array_filter($input, static fn (mixed $v): bool => $v !== null));

        return ActionResult::completed(['id' => $contact->id, 'created' => true], null, $contact->getMorphClass(), $contact->id);
    }
}
