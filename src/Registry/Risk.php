<?php

declare(strict_types=1);

namespace Odden\Core\Registry;

/**
 * How much an action can hurt. The executor lets an AI actor run Read and Write actions at once, and holds Destructive and
 * Outbound ones (anything that deletes, or leaves the CRM as a message) until a person approves them.
 */
enum Risk: string
{
    case Read = 'read';
    case Write = 'write';
    case Destructive = 'destructive';
    case Outbound = 'outbound';

    /**
     * Whether an AI actor must have a person approve this before it runs.
     */
    public function needsApprovalFromAi(): bool
    {
        return $this === self::Destructive || $this === self::Outbound;
    }
}
