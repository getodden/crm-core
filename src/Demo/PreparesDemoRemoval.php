<?php

declare(strict_types=1);

namespace Odden\Core\Demo;

/**
 * A {@see DemoSeeder} that has to settle something before its sample records are deleted, such as a guard that would otherwise refuse
 * the delete. Removing the sample data calls it in the same transaction, just before the records go.
 */
interface PreparesDemoRemoval
{
    public function prepareRemoval(): void;
}
