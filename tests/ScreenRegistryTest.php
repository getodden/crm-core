<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Exceptions\InvalidScreenRegistrationException;
use Odden\Core\Exceptions\ScreenNotAvailableException;
use Odden\Core\Exceptions\UnknownScreenException;
use Odden\Core\Models\Contact;
use Odden\Core\Screens\NoScreenTenantParameters;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenDrawer;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Core\Screens\ScreenTenantParameters;
use Odden\Core\Tests\Fixtures\RecordingScreenShell;
use Odden\Core\Tests\Fixtures\User;

function screenRegistry(): ScreenRegistry
{
    $registry = new ScreenRegistry;
    $registry->shell('alpha', RecordingScreenShell::class, 1);
    $registry->shell('beta', RecordingScreenShell::class, 5);

    return $registry;
}

beforeEach(fn () => RecordingScreenShell::reset());

it('makes a screen for each page of a model with the right ability and record need', function (): void {
    $screens = Screen::forModel('contacts', Contact::class, 'Contacts', 'CRM', 4, null, 'crm', ['index', 'create', 'view', 'edit', 'board']);

    expect(array_map(fn (Screen $screen): string => $screen->key, $screens))->toBe(['contacts.index', 'contacts.create', 'contacts.view', 'contacts.edit', 'contacts.board'])
        ->and(array_map(fn (Screen $screen): ?string => $screen->ability, $screens))->toBe(['viewAny', 'create', 'view', 'update', 'viewAny'])
        ->and(array_map(fn (Screen $screen): bool => $screen->record, $screens))->toBe([false, false, true, true, false])
        ->and(array_map(fn (Screen $screen): bool => $screen->navigation, $screens))->toBe([true, false, false, false, false])
        ->and($screens[0]->model)->toBe(Contact::class)
        ->and($screens[0]->module)->toBe('crm')
        ->and($screens[0]->sort)->toBe(4);
});

it('changes only what with() is given', function (): void {
    $screen = Screen::page('data-quality', 'Data quality', 'CRM', 10, 'check', 'crm')->with(label: 'Quality', navigation: false);

    expect($screen->label)->toBe('Quality')
        ->and($screen->navigation)->toBeFalse()
        ->and($screen->group)->toBe('CRM')
        ->and($screen->sort)->toBe(10)
        ->and($screen->icon)->toBe('check')
        ->and($screen->module)->toBe('crm');
});

it('defines, reads and redefines screens', function (): void {
    $registry = screenRegistry();
    $registry->define(Screen::page('a', 'A'), Screen::page('b', 'B'));
    $registry->redefine($registry->get('a')->with(label: 'Renamed'));

    expect($registry->has('a'))->toBeTrue()
        ->and($registry->get('a')->label)->toBe('Renamed')
        ->and($registry->all())->toHaveCount(2)
        ->and($registry->has('missing'))->toBeFalse();
});

it('refuses a duplicate key and redefining an unknown key', function (): void {
    $registry = screenRegistry();
    $registry->define(Screen::page('a', 'A'));

    expect(fn () => $registry->define(Screen::page('a', 'Again')))->toThrow(InvalidScreenRegistrationException::class)
        ->and(fn () => $registry->redefine(Screen::page('missing', 'Missing')))->toThrow(UnknownScreenException::class)
        ->and(fn () => $registry->get('missing'))->toThrow(UnknownScreenException::class);
});

it('keeps one drawer per shell and lets a later call replace it', function (): void {
    $registry = screenRegistry();
    $registry->define(Screen::page('a', 'A'));
    $registry->draw('a', new ScreenDrawer('alpha', 'App\First'));
    $registry->draw('a', new ScreenDrawer('alpha', 'App\Second', 'index'));

    expect($registry->drawerFor('a', 'alpha')?->class)->toBe('App\Second')
        ->and($registry->drawerFor('a', 'alpha')?->page)->toBe('index')
        ->and($registry->drawerFor('a', 'beta'))->toBeNull()
        ->and($registry->keysDrawnBy('alpha', 'App\Second'))->toBe(['a'])
        ->and($registry->keysDrawnBy('alpha', 'App\First'))->toBe([]);
});

it('passes the replacement class to the shell and keeps the original on the drawer', function (): void {
    $registry = screenRegistry();
    $registry->define(Screen::page('a', 'A'));
    $registry->draw('a', new ScreenDrawer('alpha', 'App\Original'));
    $registry->replace('App\Original', 'App\Replacement');

    expect($registry->url('a'))->toBe('alpha:App\Replacement:a')
        ->and(RecordingScreenShell::$calls[0]['class'])->toBe('App\Replacement')
        ->and($registry->replacements())->toBe(['App\Original' => 'App\Replacement']);
});

it('hides screens matching an except pattern', function (): void {
    $registry = screenRegistry();
    $registry->define(Screen::page('contacts.index', 'Contacts'), Screen::page('deals.index', 'Deals'));
    $registry->draw('contacts.index', new ScreenDrawer('alpha', 'App\Contacts'));
    $registry->draw('deals.index', new ScreenDrawer('alpha', 'App\Deals'));
    $registry->except('contacts.*');

    expect($registry->isExcepted('contacts.index'))->toBeTrue()
        ->and($registry->available('contacts.index'))->toBeFalse()
        ->and($registry->available('deals.index'))->toBeTrue()
        ->and(fn () => $registry->url('contacts.index'))->toThrow(ScreenNotAvailableException::class);
});

it('hides the screens of a disabled module', function (): void {
    $registry = screenRegistry();
    $registry->define(Screen::page('quotes.index', 'Quotes', module: 'sales'), Screen::page('contacts.index', 'Contacts'));
    $registry->draw('quotes.index', new ScreenDrawer('alpha', 'App\Quotes'));
    $registry->draw('contacts.index', new ScreenDrawer('alpha', 'App\Contacts'));
    $registry->disableModules('sales');

    expect($registry->moduleEnabled('sales'))->toBeFalse()
        ->and($registry->moduleEnabled(null))->toBeTrue()
        ->and($registry->disabledModules())->toBe(['sales'])
        ->and($registry->available('quotes.index'))->toBeFalse()
        ->and($registry->available('contacts.index'))->toBeTrue()
        ->and(fn () => $registry->url('quotes.index'))->toThrow(ScreenNotAvailableException::class);
});

it('blocks every mutator once frozen', function (): void {
    $registry = screenRegistry();
    $registry->define(Screen::page('a', 'A'));
    $registry->freeze();

    expect($registry->isFrozen())->toBeTrue()
        ->and(fn () => $registry->define(Screen::page('b', 'B')))->toThrow(InvalidScreenRegistrationException::class)
        ->and(fn () => $registry->redefine(Screen::page('a', 'A2')))->toThrow(InvalidScreenRegistrationException::class)
        ->and(fn () => $registry->defineGroup('G', 1))->toThrow(InvalidScreenRegistrationException::class)
        ->and(fn () => $registry->draw('a', new ScreenDrawer('alpha', 'App\A')))->toThrow(InvalidScreenRegistrationException::class)
        ->and(fn () => $registry->shell('gamma', RecordingScreenShell::class))->toThrow(InvalidScreenRegistrationException::class)
        ->and(fn () => $registry->replace('App\A', 'App\B'))->toThrow(InvalidScreenRegistrationException::class)
        ->and(fn () => $registry->except('a'))->toThrow(InvalidScreenRegistrationException::class)
        ->and(fn () => $registry->disableModules('sales'))->toThrow(InvalidScreenRegistrationException::class);
});

it('refuses to freeze while a drawn key is undefined', function (): void {
    $registry = screenRegistry();
    $registry->draw('ghost.index', new ScreenDrawer('alpha', 'App\Ghost'));

    expect(fn () => $registry->freeze())->toThrow(UnknownScreenException::class, 'ghost.index')
        ->and($registry->isFrozen())->toBeFalse();
});

it('builds the url with the highest-priority shell that draws the key', function (): void {
    $registry = screenRegistry();
    $registry->define(Screen::page('a', 'A'));
    $registry->draw('a', new ScreenDrawer('alpha', 'App\Alpha'));
    $registry->draw('a', new ScreenDrawer('beta', 'App\Beta'));

    expect($registry->url('a'))->toBe('beta:App\Beta:a');

    RecordingScreenShell::$refusedClasses = ['App\Beta'];

    expect($registry->url('a'))->toBe('alpha:App\Alpha:a')
        ->and($registry->available('a'))->toBeTrue();

    RecordingScreenShell::$refusedClasses = ['App\Beta', 'App\Alpha'];

    expect($registry->available('a'))->toBeFalse()
        ->and(fn () => $registry->url('a'))->toThrow(ScreenNotAvailableException::class);
});

it('tries shells registered with equal priority in registration order', function (): void {
    $registry = new ScreenRegistry;
    $registry->shell('first', RecordingScreenShell::class);
    $registry->shell('second', RecordingScreenShell::class);
    $registry->define(Screen::page('a', 'A'));
    $registry->draw('a', new ScreenDrawer('second', 'App\Second'));
    $registry->draw('a', new ScreenDrawer('first', 'App\First'));

    expect($registry->url('a'))->toBe('first:App\First:a');
});

it('needs a record for a record screen and passes it on', function (): void {
    $registry = screenRegistry();
    $registry->define(...Screen::forModel('contacts', Contact::class, 'Contacts'));
    $registry->draw('contacts.edit', new ScreenDrawer('alpha', 'App\EditContact'));

    expect(fn () => $registry->url('contacts.edit'))->toThrow(InvalidArgumentException::class);

    $registry->url('contacts.edit', 42, ['tab' => 'mine'], null, false);

    expect(RecordingScreenShell::$calls[0]['record'])->toBe(42)
        ->and(RecordingScreenShell::$calls[0]['parameters'])->toBe(['tab' => 'mine'])
        ->and(RecordingScreenShell::$calls[0]['absolute'])->toBeFalse();
});

it('lets tenant parameters win over the given parameters and passes the tenant on', function (): void {
    app()->instance(ScreenTenantParameters::class, new class implements ScreenTenantParameters
    {
        public function for(Model $tenant): array
        {
            return ['workspace' => 'acme', 'tab' => 'tenant'];
        }
    });
    $registry = screenRegistry();
    $registry->define(Screen::page('a', 'A'));
    $registry->draw('a', new ScreenDrawer('alpha', 'App\Alpha'));
    $tenant = new Contact;

    $registry->url('a', null, ['tab' => 'mine', 'extra' => 'x', 'workspace' => 'other'], $tenant);
    $registry->url('a');

    expect(RecordingScreenShell::$calls[0]['parameters'])->toBe(['tab' => 'tenant', 'extra' => 'x', 'workspace' => 'acme'])
        ->and(RecordingScreenShell::$calls[0]['tenant'])->toBe($tenant)
        ->and(RecordingScreenShell::$calls[1]['parameters'])->toBe([]);
});

it('adds no tenant parameters by default', function (): void {
    expect((new NoScreenTenantParameters)->for(new Contact))->toBe([]);
});

it('rejects an unknown key', function (): void {
    $registry = screenRegistry();

    expect(fn () => $registry->url('missing'))->toThrow(UnknownScreenException::class)
        ->and($registry->available('missing'))->toBeFalse();
});

it('groups navigation by group sort then label and screens by sort then label', function (): void {
    $registry = screenRegistry();
    $registry->defineGroup('Zed', 1);
    $registry->defineGroup('Alpha', 2);
    $registry->define(
        Screen::page('z2', 'Banana', 'Zed', 2),
        Screen::page('z1', 'Cherry', 'Zed', 1),
        Screen::page('z3', 'Apple', 'Zed', 2),
        Screen::page('a1', 'Alpha one', 'Alpha'),
        Screen::page('u1', 'Unlisted group', 'Other'),
        Screen::page('n1', 'No group'),
        Screen::page('hidden', 'Hidden', 'Zed', 0)->with(navigation: false),
        Screen::page('undrawn', 'Undrawn', 'Zed', 0),
    );

    foreach (['z1', 'z2', 'z3', 'a1', 'u1', 'n1', 'hidden'] as $key) {
        $registry->draw($key, new ScreenDrawer('alpha', 'App\\'.$key));
    }

    $navigation = $registry->navigation(new User);

    expect(array_column($navigation, 'group'))->toBe([null, 'Zed', 'Alpha', 'Other'])
        ->and(array_map(fn (Screen $screen): string => $screen->key, $navigation[1]['screens']))->toBe(['z1', 'z3', 'z2']);
});

it('leaves out screens that are excepted, in a disabled module, or not drawn', function (): void {
    $registry = screenRegistry();
    $registry->define(Screen::page('a', 'A'), Screen::page('b', 'B'), Screen::page('c', 'C', module: 'sales'), Screen::page('d', 'D'));

    foreach (['a', 'b', 'c'] as $key) {
        $registry->draw($key, new ScreenDrawer('alpha', 'App\\'.$key));
    }

    $registry->except('b');
    $registry->disableModules('sales');

    expect(array_map(fn (Screen $screen): string => $screen->key, $registry->navigation(new User)[0]['screens']))->toBe(['a']);
});

it('resolves the tenant parameters on every call instead of holding one', function (): void {
    $registry = screenRegistry();
    $registry->define(Screen::page('a', 'A'));
    $registry->draw('a', new ScreenDrawer('alpha', 'App\Alpha'));
    $tenant = new Contact;

    app()->instance(ScreenTenantParameters::class, new class implements ScreenTenantParameters
    {
        public function for(Model $tenant): array
        {
            return ['workspace' => 'first'];
        }
    });
    $registry->url('a', null, [], $tenant);

    app()->instance(ScreenTenantParameters::class, new class implements ScreenTenantParameters
    {
        public function for(Model $tenant): array
        {
            return ['workspace' => 'second'];
        }
    });
    $registry->url('a', null, [], $tenant);

    expect(RecordingScreenShell::$calls[0]['parameters'])->toBe(['workspace' => 'first'])
        ->and(RecordingScreenShell::$calls[1]['parameters'])->toBe(['workspace' => 'second']);
});

it('opens a screen only when it is available and allowed', function (): void {
    $registry = screenRegistry();
    $registry->define(Screen::page('a', 'A'), Screen::page('b', 'B', ability: 'never'), Screen::page('c', 'C'));
    $registry->draw('a', new ScreenDrawer('alpha', 'App\A'));
    $registry->draw('b', new ScreenDrawer('alpha', 'App\B'));
    $user = new User;

    expect($registry->canOpen('a', $user))->toBeTrue()
        ->and($registry->canOpen('b', $user))->toBeFalse()
        ->and($registry->canOpen('c', $user))->toBeFalse();
});

it('lists plain pages and pages with their own ability and record need', function (): void {
    $screens = Screen::forModel('contacts', Contact::class, 'Contacts', pages: ['index', 'merge' => ['ability' => 'update', 'record' => true], 'export' => ['ability' => null]]);

    expect(array_map(fn (Screen $screen): string => $screen->key, $screens))->toBe(['contacts.index', 'contacts.merge', 'contacts.export'])
        ->and($screens[1]->ability)->toBe('update')
        ->and($screens[1]->record)->toBeTrue()
        ->and($screens[2]->ability)->toBeNull()
        ->and($screens[2]->record)->toBeFalse();
});

it('lets a page carry a model and with() change the ability', function (): void {
    $screen = Screen::page('overview', 'Overview', model: Contact::class, viewAnyOf: [Contact::class])->with(ability: 'viewAny');

    expect($screen->model)->toBe(Contact::class)
        ->and($screen->ability)->toBe('viewAny')
        ->and($screen->viewAnyOf)->toBe([Contact::class]);
});

it('freezes a registry that is resolved again after boot', function (): void {
    app()->forgetInstance(ScreenRegistry::class);

    expect(app(ScreenRegistry::class)->isFrozen())->toBeTrue()
        ->and(app(ScreenRegistry::class)->has('contacts.index'))->toBeTrue();
});

it('refuses to freeze while a constrained key is undefined', function (): void {
    $registry = screenRegistry();
    $registry->requireViewAny('ghost', null, Contact::class);

    expect(fn () => $registry->freeze())->toThrow(UnknownScreenException::class, 'ghost');
});
