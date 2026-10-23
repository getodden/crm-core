<?php

declare(strict_types=1);

namespace Odden\Core\Registry;

/**
 * Who an action runs for: a person, an AI acting for a person, or the system (a scheduled job). An AI actor always carries the
 * person it acts for, and a host's ActionGate must give it no more rights than that person has.
 */
final readonly class Actor
{
    public const string USER = 'user';

    public const string AI = 'ai';

    public const string SYSTEM = 'system';

    private function __construct(
        public string $kind,
        public int|string|null $userId,
    ) {}

    public static function user(int|string $userId): self
    {
        return new self(self::USER, $userId);
    }

    public static function ai(int|string $onBehalfOfUserId): self
    {
        return new self(self::AI, $onBehalfOfUserId);
    }

    public static function system(): self
    {
        return new self(self::SYSTEM, null);
    }

    public function isAi(): bool
    {
        return $this->kind === self::AI;
    }
}
