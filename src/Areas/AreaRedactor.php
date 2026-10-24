<?php

declare(strict_types=1);

namespace Odden\Core\Areas;

use Odden\Core\Encryption\SensitiveProperties;

/**
 * Takes what a person (or an AI acting for them) may not see out of plain data: an action's result, or text that is about to leave for
 * an AI provider.
 *
 * The data is matched by key name at any depth, using a map of key => area that the action declares (CrmAction::areas()). A key in an
 * area the person does not hold is replaced; so is anything in the Sensitive area when the data is going to an AI provider, whoever
 * asks, because that area never leaves the system. As a net under the maps, a few key names that always mean a birth date are treated
 * as Sensitive too.
 */
final class AreaRedactor
{
    public const string WITHHELD = '[withheld: outside your areas]';

    public const string SENSITIVE = '[withheld: sensitive]';

    /** Key names that hold a date of birth wherever they turn up. */
    private const array SENSITIVE_KEYS = ['date_of_birth', 'dob', 'birthdate', 'birth_date', 'birthday'];

    public function __construct(private readonly Areas $areas) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, DataArea>  $map  Key name => area, as declared by an action.
     * @param  bool  $forAi  Whether the data goes to an AI provider: the Sensitive area is then withheld for everyone.
     * @return array<string, mixed>
     */
    public function redact(array $data, array $map, int|string|null $userId, bool $forAi): array
    {
        // The custom properties the workspace marked sensitive are matched by name too, wherever the name turns up in the data (an
        // action declares fixed keys; a workspace's property names are its own). A name an action declares keeps the action's area.
        return $this->walk($data, [...app(SensitiveProperties::class)->namedAreas(), ...$map], $userId, $forAi);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, DataArea>  $map
     * @return array<string, mixed>
     */
    private function walk(array $data, array $map, int|string|null $userId, bool $forAi): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $area = $map[$key] ?? (in_array($key, self::SENSITIVE_KEYS, true) ? DataArea::Sensitive : null);

            if ($area === DataArea::Sensitive && $forAi) {
                $out[$key] = self::SENSITIVE;
            } elseif ($area !== null && ! $this->areas->holds($area, $userId)) {
                $out[$key] = self::WITHHELD;
            } else {
                $out[$key] = is_array($value) ? $this->walk($value, $map, $userId, $forAi) : $value;
            }
        }

        return $out;
    }

    /**
     * Text going to an AI provider that is not an action's result (a ticket to draft a reply to, a timeline to summarize): only the
     * Sensitive area is taken out, by key name.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function forAi(array $data): array
    {
        return $this->redact($data, [], null, true);
    }
}
