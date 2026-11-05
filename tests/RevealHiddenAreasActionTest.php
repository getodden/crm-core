<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Odden\Core\Actions\RevealHiddenAreasAction;
use Odden\Core\Areas\AreaAccess;
use Odden\Core\Areas\AreaRedactor;
use Odden\Core\Areas\AreaRevealed;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\DataArea;
use Odden\Core\Areas\RevealNotAllowed;
use Odden\Core\Models\Contact;
use Odden\Core\Registry\ActionExecutor;
use Odden\Core\Tests\Fixtures\User;

function personHolding(int $id, array $held, array $mayReveal): void
{
    test()->actingAs(User::query()->create(['id' => $id, 'name' => "User {$id}", 'email' => "u{$id}@example.com", 'password' => 'x']));

    app()->instance(AreaAccess::class, new class($id, $held, $mayReveal) implements AreaAccess
    {
        public function __construct(private int $id, private array $held, private array $mayReveal) {}

        public function allows(DataArea $area, int|string|null $userId = null): bool
        {
            return $userId === $this->id && in_array($area, $this->held, true);
        }

        public function mayReveal(DataArea $area, int|string|null $userId = null): bool
        {
            return in_array($area, $this->mayReveal, true);
        }
    });
    app()->forgetInstance(Areas::class);
    app()->forgetInstance(AreaRedactor::class);
    app()->forgetInstance(ActionExecutor::class);
}

it('reveals only the hidden areas the role may open, and says why', function (): void {
    Event::fake([AreaRevealed::class]);
    $contact = Contact::factory()->create(['email' => 'ann@example.com', 'date_of_birth' => '2011-03-02']);
    personHolding(11, [], [DataArea::Sensitive]);

    $revealed = app(RevealHiddenAreasAction::class)->execute($contact, 'Parent asked about the age limit.');

    expect($revealed)->toBe([DataArea::Sensitive])
        ->and(app(Areas::class)->canSee(DataArea::Sensitive, $contact))->toBeTrue()
        ->and(app(Areas::class)->canSee(DataArea::Identity, $contact))->toBeFalse();

    Event::assertDispatchedTimes(AreaRevealed::class, 1);
    Event::assertDispatched(AreaRevealed::class, fn (AreaRevealed $event): bool => $event->area === DataArea::Sensitive
        && $event->userId === 11 && $event->recordId === $contact->id && $event->reason === 'Parent asked about the age limit.');
});

it('refuses a reason that is too short', function (): void {
    $contact = Contact::factory()->create(['date_of_birth' => '2011-03-02']);
    personHolding(12, [], [DataArea::Sensitive]);

    expect(fn () => app(RevealHiddenAreasAction::class)->execute($contact, 'too short'))->toThrow(RevealNotAllowed::class);
});
