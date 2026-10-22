<?php

declare(strict_types=1);

namespace Odden\Core\Demo;

use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\CustomerHealthStatus;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Enums\ListType;
use Odden\Core\Enums\PropertyType;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Core\Models\PropertyDefinition;

/**
 * Sample companies, people, activity, lists and custom properties. Every address is on the reserved `.example` domain, so nothing sent
 * to a sample contact can reach a real person.
 */
final class CoreDemoSeeder implements DemoSeeder
{
    public const GROUP_COMPANIES = 'companies';

    public const GROUP_CONTACTS = 'contacts';

    private const DOMAIN = '.example';

    public function module(): string
    {
        return 'Contacts and companies';
    }

    public function order(): int
    {
        return 0;
    }

    public function seed(DemoContext $context): void
    {
        $this->properties($context);
        $this->companies($context);
        $this->contacts($context);
        $this->activities($context);
        $this->lists($context);
    }

    private function properties(DemoContext $context): void
    {
        $rows = [
            ['contact', 'preferred_channel', 'Preferred channel', PropertyType::Select, 'Engagement', ['email' => 'Email', 'phone' => 'Phone', 'chat' => 'Chat']],
            ['contact', 'linkedin_url', 'LinkedIn', PropertyType::Text, 'Engagement', null],
            ['company', 'employee_count', 'Employees', PropertyType::Number, 'Firmographics', null],
            ['company', 'contract_renewal', 'Contract renewal', PropertyType::Date, 'Account', null],
        ];

        foreach ($rows as $i => [$entity, $name, $label, $type, $group, $options]) {
            if (PropertyDefinition::query()->where('entity_type', $entity)->where('name', $name)->exists()) {
                continue;
            }

            $context->track(PropertyDefinition::query()->create([
                'entity_type' => $entity,
                'name' => $name,
                'label' => $label,
                'type' => $type,
                'group_name' => $group,
                'options' => $options,
                'is_required' => false,
                'is_searchable' => $i % 2 === 0,
                'sort_order' => $i,
            ]));
        }
    }

    private function companies(DemoContext $context): void
    {
        $rows = [
            'kestrel' => ['Kestrel Labs', 'kestrellabs', 'Software', LifecycleStage::Customer, 86, CustomerHealthStatus::Healthy],
            'atlas' => ['Atlas Freight', 'atlasfreight', 'Logistics', LifecycleStage::Opportunity, 64, CustomerHealthStatus::Neutral],
            'juniper' => ['Juniper & Vine', 'juniperandvine', 'Retail', LifecycleStage::SalesQualifiedLead, 58, CustomerHealthStatus::Neutral],
            'blue' => ['Blue Harbor Co.', 'blueharbor', 'Finance', LifecycleStage::Customer, 79, CustomerHealthStatus::Healthy],
            'lumen' => ['Lumen Health', 'lumenhealth', 'Healthcare', LifecycleStage::Lead, 34, CustomerHealthStatus::AtRisk],
        ];

        foreach ($rows as $key => [$name, $domain, $industry, $stage, $score, $health]) {
            $company = $context->track(Company::query()->create([
                'name' => $name,
                'domain' => $domain.self::DOMAIN,
                'industry' => $industry,
                'lifecycle_stage' => $stage,
                'health_score' => $score,
                'health_status' => $health,
                'owner_id' => $context->ownerId(),
            ]));

            $context->remember(self::GROUP_COMPANIES, $key, $company);
        }
    }

    private function contacts(DemoContext $context): void
    {
        $rows = [
            'maya' => ['Maya', 'Okafor', 'VP Operations', 'kestrel', 86, LifecycleStage::Customer, LeadStatus::Connected, 2],
            'grace' => ['Grace', 'Lindqvist', 'Procurement', 'kestrel', 38, LifecycleStage::Customer, LeadStatus::Open, 720],
            'daniel' => ['Daniel', 'Reyes', 'Operations Manager', 'atlas', 74, LifecycleStage::Opportunity, LeadStatus::InProgress, 26],
            'samir' => ['Samir', 'Haddad', 'IT Lead', 'atlas', 55, LifecycleStage::Opportunity, LeadStatus::Open, 480],
            'priya' => ['Priya', 'Nair', 'Head of E-commerce', 'juniper', 68, LifecycleStage::SalesQualifiedLead, LeadStatus::Connected, 70],
            'owen' => ['Owen', 'Park', 'Marketing Lead', 'juniper', 61, LifecycleStage::MarketingQualifiedLead, LeadStatus::New, 720],
            'tom' => ['Tom', 'Whitlock', 'Finance Director', 'blue', 81, LifecycleStage::Customer, LeadStatus::Connected, 120],
            'elena' => ['Elena', 'Petrova', 'Clinical Systems Lead', 'lumen', 42, LifecycleStage::Lead, LeadStatus::AttemptedContact, 340],
        ];

        foreach ($rows as $key => [$first, $last, $title, $companyKey, $score, $stage, $status, $hoursAgo]) {
            $company = $context->find(self::GROUP_COMPANIES, $companyKey);
            $contact = $context->track(Contact::query()->create([
                'first_name' => $first,
                'last_name' => $last,
                'email' => strtolower($first).'@'.preg_replace('/\.example$/', '', (string) $company?->getAttribute('domain')).self::DOMAIN,
                'job_title' => $title,
                'lead_score' => $score,
                'lifecycle_stage' => $stage,
                'lead_status' => $status,
                'last_contacted_at' => now()->subHours($hoursAgo),
                'owner_id' => $context->ownerId(),
            ]));

            if ($company instanceof Company) {
                $contact->associateWith($company);
            }

            $context->remember(self::GROUP_CONTACTS, $key, $contact);
        }
    }

    private function activities(DemoContext $context): void
    {
        $rows = [
            ['maya', ActivityType::Call, 'Call about seat expansion', 26, ActivityStatus::Completed, null],
            ['maya', ActivityType::Note, 'Wants seat expansion priced for 160 seats', 72, ActivityStatus::Completed, null],
            ['maya', ActivityType::Task, 'Send seat expansion proposal', 1, ActivityStatus::Pending, 24],
            ['maya', ActivityType::Email, 'Sent the renewal paperwork', 200, ActivityStatus::Completed, null],
            ['daniel', ActivityType::Email, 'Replied to the fleet tracking rollout thread', 26, ActivityStatus::Completed, null],
            ['daniel', ActivityType::Task, 'Share the security questionnaire', 5, ActivityStatus::Pending, 48],
            ['daniel', ActivityType::Meeting, 'Rollout planning with Atlas ops', 300, ActivityStatus::Completed, null],
            ['priya', ActivityType::Email, 'Sent the wholesale portal proposal', 70, ActivityStatus::Completed, null],
            ['priya', ActivityType::Task, 'Follow up on the proposal', 2, ActivityStatus::Pending, -20],
            ['tom', ActivityType::Meeting, 'Quote walkthrough', 120, ActivityStatus::Completed, null],
            ['tom', ActivityType::Call, 'Pricing call with finance', 500, ActivityStatus::Completed, null],
            ['elena', ActivityType::Note, 'Deal looks stalled: no reply in two weeks', 168, ActivityStatus::Completed, null],
            ['elena', ActivityType::Task, 'Re-engage with a case study', 3, ActivityStatus::Pending, -72],
            ['samir', ActivityType::Note, 'Imported from CSV, needs a first call', 700, ActivityStatus::Completed, null],
            ['grace', ActivityType::Email, 'Sequence step 2 sent', 720, ActivityStatus::Completed, null],
            ['owen', ActivityType::Note, 'Downloaded the pricing guide', 96, ActivityStatus::Completed, null],
        ];

        foreach ($rows as [$key, $type, $title, $hoursAgo, $status, $dueInHours]) {
            $contact = $context->find(self::GROUP_CONTACTS, $key);

            if (! $contact instanceof Contact) {
                continue;
            }

            $activity = $contact->activities()->create([
                'type' => $type,
                'status' => $status,
                'title' => $title,
                'due_at' => $dueInHours === null ? null : now()->addHours($dueInHours),
                'completed_at' => $status === ActivityStatus::Completed ? now()->subHours($hoursAgo) : null,
            ]);
            $activity->forceFill(['created_at' => now()->subHours($hoursAgo)])->save();
            $context->track($activity);
        }
    }

    private function lists(DemoContext $context): void
    {
        $customers = $context->track(CrmList::query()->create([
            'name' => 'Customers',
            'description' => 'Everyone whose company is a customer.',
            'entity_type' => (new Contact)->getMorphClass(),
            'type' => ListType::Active,
            'criteria' => [['field' => 'lifecycle_stage', 'operator' => 'equals', 'value' => LifecycleStage::Customer->value]],
            'created_by_id' => $context->ownerId(),
        ]));
        $customers->syncActiveMembers();

        $targets = $context->track(CrmList::query()->create([
            'name' => 'Target accounts',
            'description' => 'Companies the team is working this quarter.',
            'entity_type' => (new Company)->getMorphClass(),
            'type' => ListType::Static,
            'created_by_id' => $context->ownerId(),
        ]));

        foreach (['atlas', 'juniper', 'lumen'] as $key) {
            $company = $context->find(self::GROUP_COMPANIES, $key);

            if ($company instanceof Company) {
                $targets->addMember($company);
            }
        }
    }
}
