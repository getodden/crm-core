<?php

declare(strict_types=1);

namespace Odden\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Odden\Core\Encryption\SensitivePropertyConverter;
use Odden\Core\Models\PropertyDefinition;

/**
 * Brings the stored values of one custom property in line with its definition after the Sensitive switch was turned on or off (see
 * SensitivePropertyConverter). It works on the definition as it is when the job runs, so a switch turned on and off again quickly ends
 * where the definition says. Safe to run twice. A multi-tenant host runs queued jobs as the tenant that dispatched them.
 */
class ConvertSensitiveProperty implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $definitionId) {}

    public function handle(SensitivePropertyConverter $converter): void
    {
        $definition = PropertyDefinition::query()->find($this->definitionId);

        if ($definition !== null) {
            $converter->reconcile($definition);
        }
    }
}
