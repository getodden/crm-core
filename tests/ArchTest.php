<?php

declare(strict_types=1);
use Odden\Core\Models\ActionLogEntry;
use Odden\Core\Models\ProposedAction;
use Odden\Core\Registry\CrmAction;
use Odden\Core\Support\ModelRegistry;

// Dependency rules scan source text; see sourceFilesMatching() in tests/Pest.php.
it('core domain remains strictly headless (no Filament or Livewire)', function (): void {
    expect(sourceFilesMatching('/(?<![\\\\\w])(Filament|Livewire)\\\\+[A-Z]/'))->toBeEmpty();
});

it('core does not depend on the other Odden modules', function (): void {
    expect(sourceFilesMatching('/\bOdden\\\\+(Sales|Service|Marketing|Filament)\\\\+/'))->toBeEmpty();
});

arch('no debug functions are left in the code')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('all core domain actions have an execute method')
    ->expect('Odden\Core\Actions')
    ->toHaveMethod('execute');

arch('all core enums are string backed for database agnosticism')
    ->expect('Odden\Core\Enums')
    ->toBeStringBackedEnums();

arch('every registry action implements the CrmAction contract')
    ->expect('Odden\Core\Registry\Actions')
    ->toImplement(CrmAction::class);

it('registry tables are registered with the model registry', function (): void {
    $models = app(ModelRegistry::class)->models();

    expect($models)->toContain(ActionLogEntry::class, ProposedAction::class);
});
