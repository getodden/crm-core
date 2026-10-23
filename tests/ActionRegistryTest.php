<?php

declare(strict_types=1);

use Odden\Core\Registry\ActionRegistry;
use Odden\Core\Registry\ActionResult;
use Odden\Core\Registry\Actions\CreateContact;
use Odden\Core\Registry\Actions\SearchContacts;
use Odden\Core\Registry\Actor;
use Odden\Core\Registry\CrmAction;
use Odden\Core\Registry\Risk;
use Odden\Core\Registry\RuleSchema;

it('registers the core actions', function (): void {
    expect(array_keys(app(ActionRegistry::class)->all()))->toBe([
        'contacts.create', 'contacts.search', 'contacts.update', 'notes.add', 'tasks.create',
    ]);
});

it('refuses a class that is not an action and a name taken by another class', function (): void {
    $registry = new ActionRegistry;

    expect(fn () => $registry->register(stdClass::class))->toThrow(InvalidArgumentException::class);

    $impostor = new class implements CrmAction
    {
        public static function name(): string
        {
            return 'contacts.create';
        }

        public static function description(): string
        {
            return '';
        }

        public static function rules(): array
        {
            return [];
        }

        public function risk(): Risk
        {
            return Risk::Read;
        }

        public function sensitive(): array
        {
            return [];
        }

        public function authorize(Actor $actor, array $input): bool
        {
            return true;
        }

        public function handle(Actor $actor, array $input): ActionResult
        {
            return ActionResult::completed();
        }
    };

    $registry->register(CreateContact::class);

    expect(fn () => $registry->register($impostor::class))->toThrow(InvalidArgumentException::class);
});

it('exports tool definitions with a schema built from the rules', function (): void {
    $tools = collect(app(ActionRegistry::class)->toolDefinitions())->keyBy('name');

    expect($tools['contacts.create']['input_schema']['required'])->toBe(['email'])
        ->and($tools['contacts.create']['input_schema']['properties']['email'])->toBe(['type' => 'string', 'format' => 'email', 'maxLength' => 255])
        ->and($tools['contacts.create']['input_schema']['properties']['lifecycle_stage']['enum'])->toContain('lead', 'customer')
        ->and($tools['contacts.search']['risk'])->toBe('read')
        ->and($tools['contacts.search']['input_schema']['properties']['limit'])->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => SearchContacts::LIMIT]);
});

it('can limit the tool list to the names asked for', function (): void {
    $tools = app(ActionRegistry::class)->toolDefinitions(['notes.add']);

    expect($tools)->toHaveCount(1)->and($tools[0]['name'])->toBe('notes.add');
});

it('describes only the rules it understands', function (): void {
    $schema = RuleSchema::for(['n' => 'required|integer|min:1', 'items.*' => 'string', 'flag' => ['boolean'], 'odd' => ['string', 'regex:/x/']]);

    expect(array_keys($schema['properties']))->toBe(['n', 'flag', 'odd'])
        ->and($schema['properties']['n'])->toBe(['type' => 'integer', 'minimum' => 1])
        ->and($schema['required'])->toBe(['n']);
});
