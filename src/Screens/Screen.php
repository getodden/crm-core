<?php

declare(strict_types=1);

namespace Odden\Core\Screens;

use Illuminate\Database\Eloquent\Model;

final readonly class Screen
{
    /**
     * @param  class-string<Model>|null  $model
     * @param  list<class-string<Model>>  $viewAnyOf
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $group = null,
        public ?int $sort = null,
        public ?string $icon = null,
        public ?string $model = null,
        public ?string $ability = null,
        public bool $record = false,
        public ?string $module = null,
        public bool $navigation = false,
        public array $viewAnyOf = [],
    ) {}

    /**
     * @param  class-string<Model>  $model
     * @param  array<int|string, string|array{ability?: string|null, record?: bool}>  $pages
     * @return list<self>
     */
    public static function forModel(
        string $prefix,
        string $model,
        string $label,
        ?string $group = null,
        ?int $sort = null,
        ?string $icon = null,
        ?string $module = null,
        array $pages = ['index', 'create', 'view', 'edit'],
    ): array {
        $screens = [];

        foreach ($pages as $name => $definition) {
            $page = is_string($definition) ? $definition : (string) $name;
            $options = is_array($definition) ? $definition : [];

            $screens[] = new self(
                key: "{$prefix}.{$page}",
                label: $label,
                group: $group,
                sort: $sort,
                icon: $icon,
                model: $model,
                ability: array_key_exists('ability', $options) ? $options['ability'] : match ($page) {
                    'create' => 'create',
                    'view' => 'view',
                    'edit' => 'update',
                    default => 'viewAny',
                },
                record: $options['record'] ?? in_array($page, ['view', 'edit'], true),
                module: $module,
                navigation: $page === 'index',
            );
        }

        return $screens;
    }

    /**
     * @param  class-string<Model>|null  $model
     * @param  list<class-string<Model>>  $viewAnyOf
     */
    public static function page(string $key, string $label, ?string $group = null, ?int $sort = null, ?string $icon = null, ?string $module = null, ?string $ability = null, ?string $model = null, array $viewAnyOf = []): self
    {
        return new self(
            key: $key,
            label: $label,
            group: $group,
            sort: $sort,
            icon: $icon,
            model: $model,
            ability: $ability,
            module: $module,
            navigation: true,
            viewAnyOf: $viewAnyOf,
        );
    }

    public function with(?string $label = null, ?string $group = null, ?int $sort = null, ?string $icon = null, ?bool $navigation = null, ?string $ability = null): self
    {
        return new self(
            key: $this->key,
            label: $label ?? $this->label,
            group: $group ?? $this->group,
            sort: $sort ?? $this->sort,
            icon: $icon ?? $this->icon,
            model: $this->model,
            ability: $ability ?? $this->ability,
            record: $this->record,
            module: $this->module,
            navigation: $navigation ?? $this->navigation,
            viewAnyOf: $this->viewAnyOf,
        );
    }
}
