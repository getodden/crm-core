<?php

declare(strict_types=1);

namespace Odden\Core\Registry;

final class AllowAllActionGate implements ActionGate
{
    public function allows(Actor $actor, CrmAction $action, array $input): bool
    {
        return true;
    }
}
