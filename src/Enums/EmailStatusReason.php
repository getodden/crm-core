<?php

declare(strict_types=1);

namespace Odden\Core\Enums;

enum EmailStatusReason: string
{
    case Syntax = 'syntax';
    case NoMailServer = 'no_mail_server';
    case Typo = 'typo';
    case Disposable = 'disposable';
    case Role = 'role';
    case DnsUnavailable = 'dns_unavailable';
    case NotChecked = 'not_checked';

    public function label(): string
    {
        return match ($this) {
            self::Syntax => 'Not a valid email address',
            self::NoMailServer => 'Domain does not accept email',
            self::Typo => 'Domain looks like a typo',
            self::Disposable => 'Disposable email provider',
            self::Role => 'Shared role address',
            self::DnsUnavailable => 'Domain could not be checked',
            self::NotChecked => 'Not checked yet',
        };
    }
}
