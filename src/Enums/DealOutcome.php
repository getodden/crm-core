<?php

declare(strict_types=1);

namespace Odden\Core\Enums;

/**
 * Package-neutral view of a deal's status, so packages can reason about deals without
 * depending on getodden/crm-sales.
 */
enum DealOutcome: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }
}
