<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Exceptions\CrossTenantException;

final class TenantGuard
{
    public const string COLUMN = 'tenant_id';

    public function __construct(private readonly TenantContext $context) {}

    public function tenantOf(Model $record): int|string|null
    {
        $tenant = $record->getAttribute(self::COLUMN);

        return is_int($tenant) || is_string($tenant) ? $tenant : null;
    }

    /**
     * @throws CrossTenantException
     */
    public function assertSameTenant(Model $a, Model $b): void
    {
        if (! $this->same($this->tenantOf($a), $this->tenantOf($b))) {
            throw new CrossTenantException(sprintf(
                'Cannot link %s #%s and %s #%s: they belong to different tenants.',
                class_basename($a), $a->getKey(), class_basename($b), $b->getKey(),
            ));
        }

        $this->assertInActiveTenant($a);
        $this->assertInActiveTenant($b);
    }

    /**
     * @throws CrossTenantException
     */
    public function assertInActiveTenant(Model $record): void
    {
        $active = $this->context->id();

        if ($active !== null && ! $this->same($this->tenantOf($record), $active)) {
            throw new CrossTenantException(sprintf(
                '%s #%s does not belong to the active tenant.',
                class_basename($record), $record->getKey(),
            ));
        }
    }

    public function assertOwnedWhenTenanted(Model $record): void
    {
        if (array_key_exists(self::COLUMN, $record->getAttributes())) {
            $this->assertInActiveTenant($record);
        }
    }

    private function same(int|string|null $a, int|string|null $b): bool
    {
        return $a === null || $b === null ? $a === $b : (string) $a === (string) $b;
    }
}
