<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Model;

class AttachRelatedRecordsAction
{
    public function __construct(private readonly AssociateRecordsAction $associate) {}

    /**
     * @param  iterable<Model>  $related
     */
    public function execute(Model $owner, iterable $related, string $type = 'default', bool $ownerIsParent = true): void
    {
        foreach ($related as $record) {
            $ownerIsParent
                ? $this->associate->execute($owner, $record, $type)
                : $this->associate->execute($record, $owner, $type);
        }
    }
}
