<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Odden\Core\Exceptions\InvalidScreenRegistrationException;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenDrawer;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Core\Tests\Fixtures\RecordingScreenShell;
use Odden\Core\Tests\Fixtures\Ticket;
use Odden\Core\Tests\Fixtures\User;

function abilityRegistry(): ScreenRegistry
{
    $registry = new ScreenRegistry;
    $registry->shell('alpha', RecordingScreenShell::class);
    $registry->define(...[
        ...Screen::forModel('tickets', Ticket::class, 'Tickets'),
        Screen::page('overview', 'Overview'),
        Screen::page('reports', 'Reports', ability: 'view-reports'),
    ]);

    return $registry;
}

function userWithoutAbilities(): Authenticatable
{
    return new User;
}

it('allows a screen with no ability', function (): void {
    expect(abilityRegistry()->can('overview', userWithoutAbilities()))->toBeTrue();
});

it('checks a gate ability when the screen has no model', function (): void {
    $registry = abilityRegistry();
    $user = userWithoutAbilities();

    expect($registry->can('reports', $user))->toBeFalse();

    Gate::define('view-reports', fn (): bool => true);

    expect($registry->can('reports', $user))->toBeTrue();
});

it('allows a model with no policy', function (): void {
    expect(abilityRegistry()->can('tickets.edit', userWithoutAbilities(), new Ticket))->toBeTrue();
});

it('allows an ability the policy does not define', function (): void {
    Gate::policy(Ticket::class, get_class(new class
    {
        public function viewAny(): bool
        {
            return false;
        }
    }));

    expect(abilityRegistry()->can('tickets.create', userWithoutAbilities()))->toBeTrue();
});

it('asks the policy and passes the record, or the model class without one', function (): void {
    $policy = new class
    {
        public static mixed $subject = null;

        public function viewAny(User $user): bool
        {
            return false;
        }

        public function update(User $user, Ticket $ticket): bool
        {
            self::$subject = $ticket;

            return $ticket->title === 'open';
        }
    };
    Gate::policy(Ticket::class, $policy::class);
    $registry = abilityRegistry();
    $user = userWithoutAbilities();

    expect($registry->can('tickets.index', $user))->toBeFalse()
        ->and($registry->can('tickets.edit', $user, new Ticket(['title' => 'open'])))->toBeTrue()
        ->and($registry->can('tickets.edit', $user, new Ticket(['title' => 'closed'])))->toBeFalse()
        ->and($policy::$subject)->toBeInstanceOf(Ticket::class);
});

it('leaves a screen the user cannot open out of the navigation', function (): void {
    $registry = abilityRegistry();
    $registry->draw('tickets.index', new ScreenDrawer('alpha', 'App\Tickets'));
    $registry->draw('reports', new ScreenDrawer('alpha', 'App\Reports'));
    $user = userWithoutAbilities();

    $keys = fn (): array => array_map(fn (Screen $screen): string => $screen->key, $registry->navigation($user)[0]['screens'] ?? []);

    expect($keys())->toBe(['tickets.index']);

    Gate::define('view-reports', fn (): bool => true);

    expect($keys())->toBe(['reports', 'tickets.index'])
        ->and(array_map(fn (Screen $screen): string => $screen->key, $registry->navigation($user)[0]['screens']))->toBe(['reports', 'tickets.index']);
});

it('denies a record screen when no record is given instead of calling the policy', function (): void {
    $policy = new class
    {
        public function update(User $user, Ticket $ticket): bool
        {
            return true;
        }
    };
    Gate::policy(Ticket::class, $policy::class);
    $registry = abilityRegistry();
    $user = userWithoutAbilities();

    expect($registry->can('tickets.edit', $user))->toBeFalse()
        ->and($registry->can('tickets.edit', $user, new Ticket))->toBeTrue();
});

it('requires viewAny on every model a page lists', function (): void {
    $policy = new class
    {
        public function viewAny(User $user): bool
        {
            return false;
        }
    };
    Gate::policy(Ticket::class, $policy::class);
    $registry = new ScreenRegistry;
    $registry->define(
        Screen::page('listed', 'Listed', viewAnyOf: [Ticket::class]),
        Screen::page('unlisted', 'Unlisted'),
    );
    $user = userWithoutAbilities();

    expect($registry->can('listed', $user))->toBeFalse()
        ->and($registry->can('unlisted', $user))->toBeTrue();
});

it('adds required models to a page defined elsewhere only while their module is on', function (): void {
    $policy = new class
    {
        public function viewAny(User $user): bool
        {
            return false;
        }
    };
    Gate::policy(Ticket::class, $policy::class);
    $registry = new ScreenRegistry;
    $registry->define(Screen::page('overview', 'Overview'));
    $registry->requireViewAny('overview', 'service', Ticket::class);
    $user = userWithoutAbilities();

    expect($registry->can('overview', $user))->toBeFalse();

    $registry->disableModules('service');

    expect($registry->can('overview', $user))->toBeTrue();
});

it('refuses to freeze while a required class does not exist', function (): void {
    $listed = new ScreenRegistry;
    $listed->define(Screen::page('a', 'A', viewAnyOf: ['App\\Misspelled']));

    $required = new ScreenRegistry;
    $required->define(Screen::page('a', 'A'));
    $required->requireViewAny('a', null, 'App\\Misspelled');

    expect(fn () => $listed->freeze())->toThrow(InvalidScreenRegistrationException::class, 'App\\Misspelled')
        ->and(fn () => $required->freeze())->toThrow(InvalidScreenRegistrationException::class, 'App\\Misspelled');
});

it('denies a record of another model than the screen shows', function (): void {
    $registry = new ScreenRegistry;
    $registry->define(...Screen::forModel('tickets', Ticket::class, 'Tickets'));

    expect($registry->can('tickets.edit', userWithoutAbilities(), new User))->toBeFalse()
        ->and($registry->can('tickets.edit', userWithoutAbilities(), new Ticket))->toBeTrue();
});

it('lets a before callback deny a screen whose model has no policy, and an overview page', function (): void {
    Gate::before(fn (): ?bool => false);
    $registry = new ScreenRegistry;
    $registry->define(...Screen::forModel('tickets', Ticket::class, 'Tickets'));
    $registry->define(Screen::page('overview', 'Overview'));
    $registry->requireViewAny('overview', null, Ticket::class);
    $user = userWithoutAbilities();

    expect($registry->can('tickets.index', $user))->toBeFalse()
        ->and($registry->can('overview', $user))->toBeFalse();
});

it('lets a before callback allow what has no policy', function (): void {
    Gate::before(fn (): ?bool => true);

    expect(abilityRegistry()->can('reports', userWithoutAbilities()))->toBeTrue();
});

it('keeps the model requirements of a page that is redefined', function (): void {
    $policy = new class
    {
        public function viewAny(User $user): bool
        {
            return false;
        }
    };
    Gate::policy(Ticket::class, $policy::class);
    $registry = new ScreenRegistry;
    $registry->define(Screen::page('overview', 'Overview'));
    $registry->requireViewAny('overview', null, Ticket::class);
    $registry->redefine(Screen::page('overview', 'Renamed'));

    expect($registry->can('overview', userWithoutAbilities()))->toBeFalse();
});
