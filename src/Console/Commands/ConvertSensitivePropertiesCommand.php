<?php

declare(strict_types=1);

namespace Odden\Core\Console\Commands;

use Illuminate\Console\Command;
use Odden\Core\Encryption\SensitivePropertyConverter;
use Odden\Core\Models\PropertyDefinition;

class ConvertSensitivePropertiesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'odden:convert-sensitive-properties {--check : Only count the stored values that do not match their definition}';

    /**
     * @var string
     */
    protected $description = 'Encrypt the stored values of custom properties marked sensitive, and decrypt those no longer marked (safe to run again)';

    public function handle(SensitivePropertyConverter $converter): int
    {
        $definitions = PropertyDefinition::query()->orderBy('entity_type')->orderBy('name')->get();

        if ($definitions->isEmpty()) {
            $this->components->info('There are no custom properties.');

            return self::SUCCESS;
        }

        $left = 0;

        foreach ($definitions as $definition) {
            $label = SensitivePropertyConverter::label($definition);

            if ($this->option('check')) {
                $pending = $converter->pending($definition);
                $left += $pending;
                $this->line(sprintf('%-40s %-9s %d to convert', $label, $definition->is_sensitive ? 'sensitive' : 'plain', $pending));

                continue;
            }

            $this->line(sprintf('%-40s %-9s %d converted', $label, $definition->is_sensitive ? 'sensitive' : 'plain', $converter->reconcile($definition)));
        }

        return $this->option('check') && $left > 0 ? self::FAILURE : self::SUCCESS;
    }
}
