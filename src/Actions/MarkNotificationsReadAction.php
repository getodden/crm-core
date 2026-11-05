<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class MarkNotificationsReadAction
{
    public function execute(Model $notifiable, ?string $id = null): int
    {
        if ($id !== null && ! Str::isUuid($id)) {
            return 0;
        }

        return DatabaseNotification::query()
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->whereNull('read_at')
            ->when($id !== null, fn ($query) => $query->whereKey($id))
            ->update(['read_at' => now()]);
    }
}
