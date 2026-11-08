<?php

declare(strict_types=1);

namespace Odden\Core\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class ModelSaved
{
    use Dispatchable;

    public function __construct(
        public Model $model,
        public bool $wasCreated,
    ) {}
}
