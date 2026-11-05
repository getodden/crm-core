<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\DataArea;

class RevealHiddenAreasAction
{
    public function __construct(private readonly Areas $areas) {}

    /**
     * @return list<DataArea>
     */
    public function execute(Model $record, string $reason, int|string|null $userId = null): array
    {
        $revealed = [];

        foreach ($this->areas->hiddenOn($record, $userId) as $area) {
            if ($this->areas->mayReveal($area, $userId)) {
                $this->areas->reveal($area, $record, $reason, $userId);
                $revealed[] = $area;
            }
        }

        return $revealed;
    }
}
