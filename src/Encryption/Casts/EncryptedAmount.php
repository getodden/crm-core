<?php

declare(strict_types=1);

namespace Odden\Core\Encryption\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Odden\Core\Contracts\BusinessData;
use Odden\Core\Encryption\DecryptionException;
use Odden\Core\Encryption\FieldEncrypter;

/**
 * A money figure that a workspace can keep encrypted ("Encrypt business data", PLAN-encryption-at-rest.md section 6).
 *
 * The plain column keeps its decimal type, so a workspace that leaves the setting off sums, averages and sorts in the database as
 * before. A workspace that turns it on stores the figure in the companion text column (`{column}_encrypted`) and leaves the plain one
 * at 0.00. Reading takes the encrypted form when there is one, so a row is read correctly whichever form it is in, which keeps the
 * time between flipping the setting and converting the stored rows safe.
 *
 * Name the field in the cast: `'amount' => EncryptedAmount::class.':deals.amount'`.
 *
 * Anything that adds up or compares the figure in SQL must go through EncryptedAmounts, and anything that reads it must select the
 * companion column too; a read of the plain column without it, in a workspace that encrypts, throws instead of reporting 0.00.
 *
 * @implements CastsAttributes<string|null, float|int|string|null>
 */
class EncryptedAmount implements CastsAttributes, ComparesCastableAttributes
{
    public function __construct(private readonly string $field, private readonly int $scale = 2) {}

    public static function companion(string $column): string
    {
        return $column.'_encrypted';
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $companion = self::companion($key);
        $stored = $attributes[$companion] ?? null;

        if (is_string($stored) && $stored !== '') {
            $encrypter = app(FieldEncrypter::class);

            if ($encrypter->isEncrypted($stored)) {
                return $this->format($encrypter->decrypt($stored, $this->field));
            }

            throw new DecryptionException(sprintf('%s #%s: [%s] is not in the encrypted format.', class_basename($model), $model->getKey() ?? 'new', $companion));
        }

        // A row read without its companion column cannot tell a plain 0.00 from an encrypted figure: refuse, so a total is never
        // quietly short. (Only a workspace that encrypts can have such a row.)
        if ($value !== null && ! array_key_exists($companion, $attributes) && $model->exists && $this->encrypts() && (float) $value === 0.0) {
            throw new LogicException(sprintf('%s #%s was read without [%s]; select it together with [%s].', class_basename($model), $model->getKey(), $companion, $key));
        }

        return $value === null ? null : $this->format($value);
    }

    /**
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $companion = self::companion($key);

        if ($value === null) {
            return [$key => null, $companion => null];
        }

        if (! $this->encrypts()) {
            return [$key => is_numeric($value) ? $this->format($value) : $value, $companion => null];
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("[{$key}] must be a number.");
        }

        $plain = $this->format($value);
        $encrypter = app(FieldEncrypter::class);
        $stored = $model->getRawOriginal($companion);

        // Each encryption of the same figure gives different bytes: keep the stored ones when nothing changed, so saving a record
        // does not look like a change to its amount.
        if (is_string($stored) && $encrypter->isEncrypted($stored)) {
            try {
                if (hash_equals($encrypter->decrypt($stored, $this->field) ?? '', $plain)) {
                    return [$key => $this->format(0), $companion => $stored];
                }
            } catch (DecryptionException) {
                // Unreadable: replace it.
            }
        }

        return [$key => $this->format(0), $companion => $encrypter->encrypt($plain, $this->field)];
    }

    /**
     * 1500, 1500.0 and "1500.00" are the same figure (a driver may return any of them), so saving a record does not rewrite it.
     */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        return $firstValue === $secondValue || (is_numeric($firstValue) && is_numeric($secondValue) && abs((float) $firstValue - (float) $secondValue) < 0.5 / (10 ** $this->scale));
    }

    private function encrypts(): bool
    {
        return app(BusinessData::class)->encrypted();
    }

    private function format(mixed $value): string
    {
        return number_format((float) $value, $this->scale, '.', '');
    }
}
