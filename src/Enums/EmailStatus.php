<?php

declare(strict_types=1);

namespace Odden\Core\Enums;

/**
 * What Odden knows about whether an email address can receive mail.
 *
 * "Valid" means no problem was found: the address is well formed and its domain accepts mail.
 * It does not mean the mailbox was confirmed to exist; only a delivered message or a
 * confirmation click proves that.
 */
enum EmailStatus: string
{
    case Valid = 'valid';
    case Risky = 'risky';
    case Invalid = 'invalid';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::Risky => 'Risky',
            self::Invalid => 'Invalid',
            self::Unknown => 'Unknown',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Valid => 'success',
            self::Risky => 'warning',
            self::Invalid => 'danger',
            self::Unknown => 'gray',
        };
    }
}
