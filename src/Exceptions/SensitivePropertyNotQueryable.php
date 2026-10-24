<?php

declare(strict_types=1);

namespace Odden\Core\Exceptions;

use InvalidArgumentException;

/**
 * A query asked the database to look inside a sensitive custom property. Its values are stored encrypted, so the database cannot
 * compare them; a list rule on it is checked record by record (EvaluateActiveListAction) instead.
 */
class SensitivePropertyNotQueryable extends InvalidArgumentException
{
    public function __construct(public readonly string $property)
    {
        parent::__construct("The property [{$property}] is marked sensitive, so its values are stored encrypted and cannot be searched in the database.");
    }
}
