<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Odden\Core\Exceptions\InvalidScreenRegistrationException;
use Odden\Core\Exceptions\ScreenNotAvailableException;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Core\Models\PropertyDefinition;
use Odden\Core\Screens\CoreScreens;
use Odden\Core\Screens\NoScreenTenantParameters;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenDrawer;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Core\Screens\Screens;
use Odden\Core\Screens\ScreenTenantParameters;
use Odden\Core\Tests\Fixtures\RecordingScreenShell;
use Odden\Core\Tests\Fixtures\User;

it('defines the core screens', function (): void {
    $keys = array_map(fn (Screen $screen): string => $screen->key, app(ScreenRegistry::class)->all());

    expect($keys)->toBe([
        'contacts.index', 'contacts.create', 'contacts.view', 'contacts.edit',
        'companies.index', 'companies.create', 'companies.view', 'companies.edit',
        'lists.index', 'lists.create', 'lists.view', 'lists.edit',
        'custom-properties.index', 'custom-properties.create', 'custom-properties.edit',
        'executive-overview',
        'data-quality',
    ]);
});

it('labels, groups and orders the core screens', function (): void {
    $registry = app(ScreenRegistry::class);

    expect($registry->get('contacts.index')->label)->toBe('Contacts')
        ->and($registry->get('contacts.index')->model)->toBe(Contact::class)
        ->and($registry->get('companies.index')->label)->toBe('Companies')
        ->and($registry->get('companies.index')->model)->toBe(Company::class)
        ->and($registry->get('lists.index')->label)->toBe('Lists')
        ->and($registry->get('lists.index')->model)->toBe(CrmList::class)
        ->and($registry->get('lists.index')->sort)->toBe(3)
        ->and($registry->get('custom-properties.index')->label)->toBe('Custom properties')
        ->and($registry->get('custom-properties.index')->group)->toBe('Settings')
        ->and($registry->get('custom-properties.index')->model)->toBe(PropertyDefinition::class)
        ->and($registry->get('executive-overview')->group)->toBe('Executive')
        ->and($registry->get('executive-overview')->sort)->toBe(-10)
        ->and($registry->get('data-quality')->group)->toBe('CRM')
        ->and($registry->get('data-quality')->sort)->toBe(10);
});

it('freezes the registry once the application has booted', function (): void {
    $registry = app(ScreenRegistry::class);

    expect($registry->isFrozen())->toBeTrue()
        ->and(Screens::isFrozen())->toBeTrue()
        ->and(fn () => Screens::define(Screen::page('late', 'Late')))->toThrow(InvalidScreenRegistrationException::class);
});

it('serves one registry and, by default, no tenant parameters', function (): void {
    expect(app(ScreenRegistry::class))->toBe(app(ScreenRegistry::class))
        ->and(app(ScreenTenantParameters::class))->toBeInstanceOf(NoScreenTenantParameters::class);
});

it('reads through the static facade', function (): void {
    expect(Screens::has('contacts.index'))->toBeTrue()
        ->and(Screens::get('contacts.index')->label)->toBe('Contacts')
        ->and(Screens::available('contacts.index'))->toBeFalse()
        ->and(fn () => Screens::url('contacts.index'))->toThrow(ScreenNotAvailableException::class);
});

it('delegates every facade method to the registry', function (): void {
    $registry = app(ScreenRegistry::class);
    $user = new User;

    expect(Screens::all())->toEqual($registry->all())
        ->and(Screens::replacements())->toBe($registry->replacements())
        ->and(Screens::exceptions())->toBe($registry->exceptions())
        ->and(Screens::disabledModules())->toBe($registry->disabledModules())
        ->and(Screens::isExcepted('contacts.index'))->toBeFalse()
        ->and(Screens::moduleEnabled('sales'))->toBeTrue()
        ->and(Screens::drawerFor('contacts.index', 'none'))->toBeNull()
        ->and(Screens::keysDrawnBy('none', 'App\\X'))->toBe([])
        ->and(Screens::can('data-quality', $user))->toBe($registry->can('data-quality', $user))
        ->and(Screens::canOpen('data-quality', $user))->toBeFalse()
        ->and(Screens::navigation($user))->toBe($registry->navigation($user));

    foreach ([
        fn () => Screens::define(Screen::page('x', 'X')),
        fn () => Screens::redefine(Screen::page('data-quality', 'X')),
        fn () => Screens::defineGroup('G', 1),
        fn () => Screens::draw('x', new ScreenDrawer('s', 'App\\X')),
        fn () => Screens::shell('s', RecordingScreenShell::class),
        fn () => Screens::replace('App\\A', 'App\\B'),
        fn () => Screens::except('x'),
        fn () => Screens::disableModules('x'),
        fn () => Screens::requireViewAny('data-quality', null, Contact::class),
    ] as $mutation) {
        expect($mutation)->toThrow(InvalidScreenRegistrationException::class);
    }

    expect(fn () => Screens::freeze())->not->toThrow(Throwable::class);
});

it('requires viewAny on contacts and companies for the overview pages even when redefined', function (): void {
    $registry = app(ScreenRegistry::class);
    $policy = new class
    {
        public function viewAny(): bool
        {
            return false;
        }
    };
    Gate::policy(Company::class, $policy::class);
    $user = new User;

    $fresh = new ScreenRegistry;
    CoreScreens::register($fresh);
    $fresh->redefine(Screen::page('data-quality', 'Data health', 'CRM'));

    expect($registry->can('executive-overview', $user))->toBeFalse()
        ->and($fresh->can('data-quality', $user))->toBeFalse();
});
