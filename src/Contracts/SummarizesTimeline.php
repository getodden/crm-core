<?php

declare(strict_types=1);

namespace Odden\Core\Contracts;

use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;

/**
 * Writes the briefing the panel shows for a contact or company: where the relationship stands and what to do next.
 *
 * The built-in implementation (SummarizeTimelineAction) is rule-based: it reads the recent activity and the health and lead
 * scores, and calls no outside service. An application or add-on can rebind this contract to another implementation (one
 * backed by a language model, say) as long as it returns the same shape. The panel asks the container for this contract,
 * so nothing else has to change.
 *
 *     $this->app->bind(SummarizesTimeline::class, MyTimelineSummarizer::class);
 */
interface SummarizesTimeline
{
    /**
     * @return array{
     *     title: string,
     *     sentiment: 'positive'|'neutral'|'at_risk',
     *     executive_summary: string,
     *     key_milestones: list<string>,
     *     recommended_next_action: string,
     *     touchpoints_analyzed: int
     * }
     */
    public function execute(Contact|Company $subject): array;
}
