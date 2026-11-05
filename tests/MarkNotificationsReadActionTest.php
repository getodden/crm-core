<?php

declare(strict_types=1);

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Odden\Core\Actions\MarkNotificationsReadAction;
use Odden\Core\Tests\Fixtures\User;

function storeNotification(User $user, bool $read = false): DatabaseNotification
{
    return DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => ['title' => 'Hello'],
        'read_at' => $read ? now()->subDay() : null,
    ]);
}

it('marks every unread notification of the notifiable read', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $first = storeNotification($user);
    $second = storeNotification($user);

    $affected = app(MarkNotificationsReadAction::class)->execute($user);

    expect($affected)->toBe(2)
        ->and($first->fresh()->read_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($second->fresh()->read_at)->not->toBeNull();
});

it('marks only the named notification read', function (): void {
    $user = User::factory()->create();
    $named = storeNotification($user);
    $other = storeNotification($user);

    $affected = app(MarkNotificationsReadAction::class)->execute($user, $named->id);

    expect($affected)->toBe(1)
        ->and($named->fresh()->read_at)->not->toBeNull()
        ->and($other->fresh()->read_at)->toBeNull();
});

it('never touches another notifiable', function (): void {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $theirs = storeNotification($stranger);

    expect(app(MarkNotificationsReadAction::class)->execute($user, $theirs->id))->toBe(0)
        ->and(app(MarkNotificationsReadAction::class)->execute($user))->toBe(0)
        ->and($theirs->fresh()->read_at)->toBeNull();
});

it('leaves the read time of an already read notification alone', function (): void {
    $user = User::factory()->create();
    $read = storeNotification($user, read: true);
    $readAt = $read->fresh()->read_at->toDateTimeString();

    expect(app(MarkNotificationsReadAction::class)->execute($user, $read->id))->toBe(0)
        ->and($read->fresh()->read_at->toDateTimeString())->toBe($readAt);
});

it('answers 0 for an id that is not a uuid instead of querying with it', function (): void {
    $user = User::factory()->create();
    storeNotification($user);

    expect(app(MarkNotificationsReadAction::class)->execute($user, "1' or '1'='1"))->toBe(0);
});
