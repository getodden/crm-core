<?php

declare(strict_types=1);

namespace Odden\Core\Registry;

/**
 * Decides whether an actor may run an action at all. A self-hosted install allows everything (Odden has no permission system of
 * its own); a host application rebinds this to its own checks. For an AI actor the host must answer as the person it acts for,
 * so the AI never has more rights than they do.
 */
interface ActionGate
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function allows(Actor $actor, CrmAction $action, array $input): bool;
}
