<?php

declare(strict_types=1);

use Odden\Core\Actions\CompleteActivityAction;
use Odden\Core\Actions\LogActivityAction;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Models\Contact;

it('completes a pending task and stamps the time', function (): void {
    $this->freezeTime();
    $task = app(LogActivityAction::class)->execute(Contact::factory()->create(), 'task', 'Send the quote', status: 'pending');

    $completed = app(CompleteActivityAction::class)->execute($task);

    expect($completed->is($task))->toBeTrue()
        ->and($task->fresh()->status)->toBe(ActivityStatus::Completed)
        ->and($task->fresh()->completed_at->toDateTimeString())->toBe(now()->toDateTimeString());
});
