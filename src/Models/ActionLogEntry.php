<?php

declare(strict_types=1);

namespace Odden\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of a registry action: who ran it, through what (`ui`, `assistant`, `mcp`), and how it ended. Written by the
 * ActionExecutor and never edited.
 *
 * @property int $id
 * @property string $action
 * @property string $actor_kind
 * @property string|null $actor_user_id
 * @property string $via
 * @property string $status
 * @property array<string, mixed>|null $input
 * @property string|null $error
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $idempotency_key
 * @property int|null $proposal_id
 * @property CarbonInterface|null $created_at
 */
class ActionLogEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('odden-core.tables.action_log', 'odden_action_log');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['input' => 'array'];
    }
}
