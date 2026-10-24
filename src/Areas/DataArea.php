<?php

declare(strict_types=1);

namespace Odden\Core\Areas;

/**
 * The five areas every encrypted field and every Odden table belongs to (PLAN-encryption-at-rest.md, section 5).
 *
 * An area is a set of abilities, not a role: a host decides which people hold which areas, and a person who does not hold an area
 * sees its fields masked. Identity is who a contact or company is; Marketing, Service and Sales are the work done with them; Sensitive
 * is the small set of fields that almost nobody needs (date of birth, government, tax and bank identifiers).
 */
enum DataArea: string
{
    case Identity = 'identity';
    case Marketing = 'marketing';
    case Service = 'service';
    case Sales = 'sales';
    case Sensitive = 'sensitive';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function description(): string
    {
        return match ($this) {
            self::Identity => 'Who a contact or company is: name, e-mail, phone, job title, website.',
            self::Marketing => 'Campaigns, form submissions, text messages sent for marketing, engagement and scores.',
            self::Service => 'Tickets, ticket messages, voicemail transcripts and satisfaction comments.',
            self::Sales => 'Deals, quotes, notes and activities on the timeline.',
            self::Sensitive => 'Date of birth and any government, tax or bank identifier.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $area): string => $area->value, self::cases());
    }

    /**
     * Turn stored area names into areas, ignoring any this version does not know.
     *
     * @param  iterable<mixed>  $names
     * @return list<self>
     */
    public static function fromNames(iterable $names): array
    {
        $areas = [];

        foreach ($names as $name) {
            $area = $name instanceof self ? $name : (is_string($name) ? self::tryFrom($name) : null);

            if ($area !== null && ! in_array($area, $areas, true)) {
                $areas[] = $area;
            }
        }

        return $areas;
    }
}
