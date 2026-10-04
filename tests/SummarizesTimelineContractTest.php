<?php

declare(strict_types=1);

use Odden\Core\Actions\SummarizeTimelineAction;
use Odden\Core\Contracts\SummarizesTimeline;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;

test('the briefing is the built-in rule-based one unless something else is bound', function () {
    expect(app(SummarizesTimeline::class))->toBeInstanceOf(SummarizeTimelineAction::class);
});

test('an application can bind its own summarizer and the container hands it out', function () {
    $custom = new class implements SummarizesTimeline
    {
        public function execute(Contact|Company $subject): array
        {
            return ['title' => 'Custom', 'sentiment' => 'neutral', 'executive_summary' => 'From elsewhere', 'key_milestones' => [], 'recommended_next_action' => 'Call', 'touchpoints_analyzed' => 0];
        }
    };
    app()->bind(SummarizesTimeline::class, fn () => $custom);

    $briefing = app(SummarizesTimeline::class)->execute(Contact::factory()->create());

    expect($briefing['executive_summary'])->toBe('From elsewhere');
});

test('the built-in summarizer satisfies the contract it is bound for', function () {
    $briefing = app(SummarizesTimeline::class)->execute(Contact::factory()->create());

    expect(array_keys($briefing))->toEqualCanonicalizing(['title', 'sentiment', 'executive_summary', 'key_milestones', 'recommended_next_action', 'touchpoints_analyzed'])
        ->and($briefing['sentiment'])->toBeIn(['positive', 'neutral', 'at_risk']);
});
