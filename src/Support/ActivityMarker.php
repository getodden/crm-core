<?php

declare(strict_types=1);

namespace Odden\Core\Support;

/**
 * A short plain-text tag on an activity that code can look it up by, so the activity's metadata (which is encrypted, and so cannot
 * be searched) does not have to be. It names an automation and the record it concerns, never a person: "cadence:12:3" is step 3 of
 * enrollment 12.
 */
final class ActivityMarker
{
    public static function cadenceStep(int $enrollmentId, int $step): string
    {
        return "cadence:{$enrollmentId}:{$step}";
    }

    public static function quotePortalView(int $quoteId): string
    {
        return "quote-view:{$quoteId}";
    }

    /**
     * The marker an activity with this metadata carries, or null when it is not one that code looks up.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function fromMetadata(?array $metadata): ?string
    {
        if ($metadata === null) {
            return null;
        }

        if (isset($metadata['sequence_enrollment_id'], $metadata['step'])) {
            return self::cadenceStep((int) $metadata['sequence_enrollment_id'], (int) $metadata['step']);
        }

        if (isset($metadata['quote_id']) && ($metadata['event'] ?? null) === 'portal_view') {
            return self::quotePortalView((int) $metadata['quote_id']);
        }

        return null;
    }
}
