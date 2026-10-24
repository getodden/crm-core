<?php

declare(strict_types=1);

namespace Odden\Core\Registry\Actions;

use Carbon\CarbonImmutable;
use Odden\Core\Actions\LogActivityAction;
use Odden\Core\Areas\DataArea;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Contact;
use Odden\Core\Registry\ActionFailedException;
use Odden\Core\Registry\ActionResult;
use Odden\Core\Registry\Actor;

class CreateTask extends BaseAction
{
    public static function name(): string
    {
        return 'tasks.create';
    }

    public static function description(): string
    {
        return 'Create a follow-up task on a contact, optionally with a due date. Use contacts.search first to get the id.';
    }

    public static function rules(): array
    {
        return [
            'contact_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
            'details' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function sensitive(): array
    {
        return ['title', 'details'];
    }

    public function areas(): array
    {
        return [
            'title' => DataArea::Sales,
            'details' => DataArea::Sales,
        ];
    }

    public function handle(Actor $actor, array $input): ActionResult
    {
        $contact = Contact::query()->find((int) $input['contact_id']);

        if ($contact === null) {
            throw new ActionFailedException('There is no contact with that id.');
        }

        $activity = app(LogActivityAction::class)->execute(
            subject: $contact,
            type: ActivityType::Task,
            title: (string) $input['title'],
            body: isset($input['details']) ? (string) $input['details'] : null,
            metadata: $actor->isAi() ? ['added_by' => 'assistant'] : [],
            status: ActivityStatus::Pending,
            dueAt: isset($input['due_at']) ? CarbonImmutable::parse((string) $input['due_at']) : null,
            creatorId: is_numeric($actor->userId) ? (int) $actor->userId : null,
        );

        return ActionResult::completed(['id' => $activity->id], null, $activity->getMorphClass(), $activity->id);
    }
}
