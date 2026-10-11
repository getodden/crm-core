<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Odden\Core\Enums\EmailStatus;
use Odden\Core\Enums\EmailStatusReason;

/**
 * The outcome of checking one email address.
 */
final readonly class EmailCheck
{
    /**
     * @param  string|null  $suggestion  A corrected address when the domain looks like a typo.
     */
    public function __construct(
        public EmailStatus $status,
        public ?EmailStatusReason $reason = null,
        public ?string $suggestion = null,
    ) {}

    public function isValid(): bool
    {
        return $this->status === EmailStatus::Valid;
    }
}
