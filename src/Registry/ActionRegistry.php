<?php

declare(strict_types=1);

namespace Odden\Core\Registry;

use InvalidArgumentException;

/**
 * Every action the installed packages offer. Each package registers its own from its service provider (resolved lazily, so
 * provider order doesn't matter), the way models go into the ModelRegistry.
 */
final class ActionRegistry
{
    /** @var array<string, class-string<CrmAction>> */
    private array $actions = [];

    /**
     * @param  class-string<CrmAction>  ...$classes
     */
    public function register(string ...$classes): void
    {
        foreach ($classes as $class) {
            if (! is_subclass_of($class, CrmAction::class)) {
                throw new InvalidArgumentException("{$class} does not implement ".CrmAction::class.'.');
            }

            $name = $class::name();

            if (isset($this->actions[$name]) && $this->actions[$name] !== $class) {
                throw new InvalidArgumentException("An action named {$name} is already registered by {$this->actions[$name]}.");
            }

            $this->actions[$name] = $class;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->actions[$name]);
    }

    public function get(string $name): CrmAction
    {
        if (! isset($this->actions[$name])) {
            throw new InvalidArgumentException("No action named {$name} is registered.");
        }

        return app($this->actions[$name]);
    }

    /**
     * @return array<string, class-string<CrmAction>>
     */
    public function all(): array
    {
        ksort($this->actions);

        return $this->actions;
    }

    /**
     * The actions as tool definitions for a model or an MCP server: name, description and an input schema built from the rules.
     *
     * @param  list<string>|null  $only  Limit to these names (so a caller can offer a model a smaller set).
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>, risk: string}>
     */
    public function toolDefinitions(?array $only = null): array
    {
        $tools = [];

        foreach ($this->all() as $name => $class) {
            if ($only !== null && ! in_array($name, $only, true)) {
                continue;
            }

            $tools[] = [
                'name' => $name,
                'description' => $class::description(),
                'input_schema' => RuleSchema::for($class::rules()),
                'risk' => app($class)->risk()->value,
            ];
        }

        return $tools;
    }
}
