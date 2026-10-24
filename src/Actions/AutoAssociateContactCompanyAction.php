<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;

class AutoAssociateContactCompanyAction
{
    public function __construct(
        protected ExtractCorporateDomainAction $extractCorporateDomain,
        protected AssociateRecordsAction $associateRecords,
        protected CreateCompanyAction $createCompany,
    ) {}

    /**
     * Auto-associate a contact with a company matching their corporate domain.
     * Optionally creates the company if no match is found.
     */
    public function execute(
        Contact $contact,
        bool $createCompanyIfMissing = false,
        string $associationType = 'primary'
    ): ?Company {
        if (empty($contact->email)) {
            return null;
        }

        $domain = $this->extractCorporateDomain->execute($contact->email);
        if ($domain === null) {
            return null;
        }

        // Check if contact is already associated with a company with this domain
        /** @var Company|null $existingAssociation */
        $existingAssociation = $contact->companies()
            ->whereDomain($domain)
            ->first();

        if ($existingAssociation !== null) {
            return $existingAssociation;
        }

        // Query for an existing company with this domain
        $query = Company::query()->whereDomain($domain);

        if ($contact->team_id !== null) {
            $query->where(function ($q) use ($contact): void {
                $q->where('team_id', $contact->team_id)
                    ->orWhereNull('team_id');
            })->orderByRaw('CASE WHEN team_id IS NULL THEN 1 ELSE 0 END');
        }

        // The team's own company first, then the oldest, so the same company is picked every time.
        /** @var Company|null $company */
        $company = $query->orderBy((new Company)->getQualifiedKeyName())->first();

        if ($company !== null) {
            $this->associateRecords->execute($contact, $company, $associationType);

            return $company;
        }

        if (! $createCompanyIfMissing) {
            return null;
        }

        // Derive company name from domain and create new company
        $name = $this->extractCorporateDomain->deriveCompanyName($domain);

        /** @var Company $company */
        $company = $this->createCompany->execute([
            'name' => $name,
            'domain' => $domain,
            'team_id' => $contact->team_id,
            'owner_id' => $contact->owner_id,
        ]);

        $this->associateRecords->execute($contact, $company, $associationType);

        return $company;
    }
}
