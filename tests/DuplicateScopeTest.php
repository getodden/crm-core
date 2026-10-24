<?php

declare(strict_types=1);

use Odden\Core\Actions\FindDuplicateCompaniesAction;
use Odden\Core\Actions\FindDuplicateContactsAction;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;

it('limits duplicate contacts to the visible query', function (): void {
    $one = Contact::factory()->create(['email' => 'same@example.com', 'first_name' => 'A', 'last_name' => 'One']);
    $two = Contact::factory()->create(['email' => 'same@example.com', 'first_name' => 'B', 'last_name' => 'Two']);
    Contact::factory()->create(['email' => 'same@example.com', 'first_name' => 'C', 'last_name' => 'Three']);

    expect(app(FindDuplicateContactsAction::class)->execute())->toHaveCount(1)
        ->and(app(FindDuplicateContactsAction::class)->execute(Contact::query()->whereKey($one->id)))->toBe([])
        ->and(app(FindDuplicateContactsAction::class)->execute(Contact::query()->whereKey([$one->id, $two->id])))->toHaveCount(1);
});

it('limits duplicate companies to the visible query', function (): void {
    $a = Company::factory()->create(['name' => 'Acme', 'domain' => 'acme.test']);
    Company::factory()->create(['name' => 'Acme', 'domain' => 'acme.test']);

    expect(app(FindDuplicateCompaniesAction::class)->execute())->not->toBe([])
        ->and(app(FindDuplicateCompaniesAction::class)->execute(Company::query()->whereKey($a->id)))->toBe([]);
});
