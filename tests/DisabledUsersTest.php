<?php

declare(strict_types=1);

use Odden\Core\Support\Mentions;
use Odden\Core\Support\UserModel;
use Odden\Core\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->active = User::factory()->create(['name' => 'Ann Active']);
    $this->gone = User::factory()->create(['name' => 'Dan Disabled', 'disabled_at' => now()]);
});

it('treats nobody as disabled until a column is configured', function (): void {
    expect(config('odden-core.users.disabled_column'))->toBeNull()
        ->and(UserModel::pickable()->pluck('name')->sort()->values()->all())->toBe(['Ann Active', 'Dan Disabled'])
        ->and(array_values(Mentions::teammates()))->toBe(['Ann Active', 'Dan Disabled']);
});

it('stops offering disabled users once a column is configured', function (): void {
    config(['odden-core.users.disabled_column' => 'disabled_at']);

    expect(UserModel::pickable()->pluck('name')->all())->toBe(['Ann Active'])
        ->and(array_values(Mentions::teammates()))->toBe(['Ann Active'])
        ->and(Mentions::teammates('dan'))->toBe([]);
});

it('still offers the user a record already has, and leaves plain lookups alone', function (): void {
    config(['odden-core.users.disabled_column' => 'disabled_at']);

    expect(UserModel::pickable($this->gone->id)->pluck('name')->sort()->values()->all())->toBe(['Ann Active', 'Dan Disabled'])
        ->and(UserModel::pickable([$this->gone->id])->count())->toBe(2)
        ->and(UserModel::query()->count())->toBe(2)
        ->and(UserModel::query()->find($this->gone->id))->not->toBeNull();
});

it('keeps resolving a mention already written of a disabled person', function (): void {
    config(['odden-core.users.disabled_column' => 'disabled_at']);

    expect(Mentions::names([$this->gone->id, $this->active->id]))->toBe([
        $this->active->id => 'Ann Active',
        $this->gone->id => 'Dan Disabled',
    ]);
});
