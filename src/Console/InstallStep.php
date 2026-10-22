<?php

declare(strict_types=1);

namespace Odden\Core\Console;

use Illuminate\Console\Command;

/**
 * A check or setup action a module adds to `odden:install`. Register it with {@see InstallSteps} from the module's service provider.
 * A step must be safe to run again: it only does what is still missing.
 */
interface InstallStep
{
    public function run(Command $command): void;
}
