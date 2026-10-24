<?php

declare(strict_types=1);

namespace Odden\Core\Areas;

/**
 * A person opened an area they do not hold, for one record, giving a reason. A host listens to write its audit log and tell the owners
 * (Odden Cloud does); the core only records that it happened.
 */
final readonly class AreaRevealed
{
    public function __construct(
        public DataArea $area,
        public int|string $userId,
        public string $recordType,
        public int|string $recordId,
        public string $reason,
    ) {}
}
