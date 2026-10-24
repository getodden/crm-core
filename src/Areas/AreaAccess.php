<?php

declare(strict_types=1);

namespace Odden\Core\Areas;

/**
 * Which data areas a person may read, and which they may open with a reason. A self-hosted install has no roles of its own, so by
 * default everyone holds every area (AllowAllAreaAccess); a host that has roles rebinds this to its own rules.
 *
 * A null user is the current signed-in person. With nobody signed in (a queued job, a command, a public form) nothing is masked: the
 * limits are for people using the panel, the assistant and exports on their behalf, not for the system itself.
 */
interface AreaAccess
{
    public function allows(DataArea $area, int|string|null $userId = null): bool;

    /**
     * Whether this person may open an area they do not hold, once, with a reason ("View with a reason").
     */
    public function mayReveal(DataArea $area, int|string|null $userId = null): bool;
}
