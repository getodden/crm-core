<?php

declare(strict_types=1);

namespace Odden\Core\Contracts;

use Illuminate\Support\Collection;
use Odden\Core\Models\Contact;
use Odden\Core\Support\DealSnapshot;

/**
 * Seam between packages that act on deals (marketing hand-off and workflows) and the package
 * that owns them. getodden/crm-sales binds the implementation; when Sales is not installed
 * nothing is bound, so resolve it with `app()->bound(DealGateway::class)`.
 */
interface DealGateway
{
    /**
     * Create an open deal and associate it with the contact as `primary` (and with the
     * contact's first company when $associateCompany is set).
     *
     * With no $pipelineId the default active pipeline is used (or, when none is marked default,
     * the first active one); with no $stageId the pipeline's first open stage (neither won nor
     * lost) is used. A $stageId must be an open stage of that pipeline. The deal takes the
     * contact's team and the configured default currency, and the owner defaults to the contact's
     * owner. Returns null when there is no usable pipeline or stage.
     */
    public function createOpenDeal(
        Contact $contact,
        string $name,
        float $amount,
        ?int $pipelineId = null,
        ?int $stageId = null,
        ?int $ownerId = null,
        bool $associateCompany = false,
    ): ?DealSnapshot;

    /**
     * @param  array<int, int|string>  $ids
     * @return Collection<int, DealSnapshot>
     */
    public function findMany(array $ids): Collection;
}
