<?php

declare(strict_types=1);

namespace Odden\Core\Encryption\Index;

use RuntimeException;

/**
 * More records need decrypting than the fallback allows. Narrow the search with a field that has an index (an address, a phone
 * number, the start of a name), or with a filter on a plain field such as the lifecycle stage.
 */
final class TooManyToFilter extends RuntimeException
{
    public function __construct(public readonly int $candidates, public readonly int $limit)
    {
        parent::__construct(sprintf(
            'This needs %d records to be decrypted and checked one by one, and at most %d are allowed. Narrow it down with an email address, a phone number, the start of a name or another filter.',
            $candidates,
            $limit,
        ));
    }
}
