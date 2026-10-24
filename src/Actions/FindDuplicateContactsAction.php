<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Encryption\Index\IndexText;
use Odden\Core\Encryption\Index\SearchIndex;
use Odden\Core\Models\Contact;

class FindDuplicateContactsAction
{
    /**
     * Find groups of duplicate contacts based on matching email, phone, or full name.
     *
     * Emails match case-insensitively, phone numbers match on their digits only (so "(555) 010-1234"
     * and "555.010.1234" are the same number), and names match on first plus last name,
     * case-insensitively. A group of contacts that was already reported under an earlier field
     * (email, then phone, then name) is not reported again.
     *
     * The values are encrypted, so the groups come from the search index (records sharing a keyed hash); nothing is decrypted
     * to find them.
     *
     * `$visible` limits the search to contacts in the given query (for example the contacts a panel
     * user may see), so duplicates are never reported across a scope boundary.
     *
     * @param  Builder<covariant Model>|null  $visible
     * @return array<int, array{
     *     match_field: string,
     *     match_value: string,
     *     contacts: Collection<int, Contact>
     * }>
     */
    public function execute(?Builder $visible = null): array
    {
        $scope = static fn (): Builder => $visible === null
            ? Contact::query()
            : Contact::query()->whereIn((new Contact)->getQualifiedKeyName(), (clone $visible)->select((new Contact)->getQualifiedKeyName()));

        $index = app(SearchIndex::class);
        $among = $scope()->select((new Contact)->getQualifiedKeyName())->toBase();

        $duplicates = [];
        $reported = [];

        foreach (['email' => 'email', 'phone' => 'phone', 'name' => 'full_name'] as $matchField => $indexed) {
            foreach ($index->sharedTokens(Contact::blindIndexField($indexed), $among) as $ids) {
                sort($ids);
                $signature = implode(',', $ids);

                if (isset($reported[$signature])) {
                    continue;
                }

                $reported[$signature] = true;
                $contacts = $scope()->whereIn((new Contact)->getQualifiedKeyName(), $ids)->orderBy('id')->get();

                $first = $contacts->first();

                if ($first === null || $contacts->count() < 2) {
                    continue;
                }

                $duplicates[] = [
                    'match_field' => $matchField,
                    'match_value' => $this->matchValue($matchField, $first),
                    'contacts' => $contacts,
                ];
            }
        }

        return $duplicates;
    }

    private function matchValue(string $field, Contact $contact): string
    {
        return match ($field) {
            'email' => IndexText::normalize(IndexText::EMAIL, (string) $contact->email),
            'phone' => IndexText::normalize(IndexText::DIGITS, (string) $contact->phone),
            default => IndexText::normalize(IndexText::TEXT, (string) $contact->first_name).' '.IndexText::normalize(IndexText::TEXT, (string) $contact->last_name),
        };
    }
}
