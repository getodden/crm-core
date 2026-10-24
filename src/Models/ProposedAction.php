<?php

declare(strict_types=1);

namespace Odden\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Encryption\Casts\EncryptedJson;
use Odden\Core\Encryption\Casts\EncryptedString;

/**
 * An action an AI asked for that a person has to approve before it runs. The input and the error text are stored encrypted with the workspace key.
 *
 * @property int $id
 * @property string $action
 * @property array<string, mixed> $input
 * @property string $status
 * @property string $requested_by_kind
 * @property string|null $requested_by_user_id
 * @property string $via
 * @property string|null $idempotency_key
 * @property string|null $decided_by_user_id
 * @property CarbonInterface|null $decided_at
 * @property CarbonInterface $expires_at
 * @property string|null $error
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ProposedAction extends Model
{
    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    public const string EXPIRED = 'expired';

    public const string FAILED = 'failed';

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('odden-core.tables.proposed_actions', 'odden_proposed_actions');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input' => EncryptedJson::class.':proposed_actions.input',
            'error' => EncryptedString::class.':proposed_actions.error',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }
}
