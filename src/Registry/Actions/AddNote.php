<?php

declare(strict_types=1);

namespace Odden\Core\Registry\Actions;

use Odden\Core\Actions\LogActivityAction;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Contact;
use Odden\Core\Registry\ActionFailedException;
use Odden\Core\Registry\ActionResult;
use Odden\Core\Registry\Actor;

class AddNote extends BaseAction
{
    public static function name(): string
    {
        return 'notes.add';
    }

    public static function description(): string
    {
        return 'Add a note to a contact\'s timeline. Use contacts.search first to get the id.';
    }

    public static function rules(): array
    {
        return [
            'contact_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'max:10000'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function sensitive(): array
    {
        return ['body', 'title'];
    }

    public function handle(Actor $actor, array $input): ActionResult
    {
        $contact = Contact::query()->find((int) $input['contact_id']);

        if ($contact === null) {
            throw new ActionFailedException('There is no contact with that id.');
        }

        $activity = app(LogActivityAction::class)->execute(
            subject: $contact,
            type: ActivityType::Note,
            title: (string) ($input['title'] ?? 'Note'),
            body: (string) $input['body'],
            metadata: $actor->isAi() ? ['added_by' => 'assistant'] : [],
            creatorId: is_numeric($actor->userId) ? (int) $actor->userId : null,
        );

        return ActionResult::completed(['id' => $activity->id], null, $activity->getMorphClass(), $activity->id);
    }
}
