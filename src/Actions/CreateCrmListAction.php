<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Odden\Core\Enums\ListType;
use Odden\Core\Models\CrmList;

class CreateCrmListAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes, int|string|null $creatorId = null): CrmList
    {
        $list = CrmList::create([...$attributes, 'created_by_id' => $creatorId]);

        if ($list->type === ListType::Active) {
            $list->syncActiveMembers();
        }

        return $list;
    }
}
