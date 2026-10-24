<?php

declare(strict_types=1);

namespace Odden\Core\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Odden\Core\Actions\CreateContactAction;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Events\ContactCreated;
use Odden\Core\Models\Contact;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_contact_via_factory(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'email' => 'sarah@example.com',
            'lifecycle_stage' => LifecycleStage::Lead,
        ]);

        $this->assertDatabaseHas('odden_contacts', [
            'id' => $contact->id,
            'lifecycle_stage' => LifecycleStage::Lead->value,
        ]);

        $this->assertSame('Sarah Connor', $contact->full_name);
        $this->assertSame(LifecycleStage::Lead, $contact->lifecycle_stage);
    }

    public function test_create_contact_action_normalizes_email_and_dispatches_event(): void
    {
        Event::fake([ContactCreated::class]);

        $action = new CreateContactAction;
        $contact = $action->execute([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => '  JOHN.DOE@EXAMPLE.COM ',
            'lifecycle_stage' => 'customer',
        ]);

        $this->assertSame('john.doe@example.com', $contact->email);
        $this->assertSame(LifecycleStage::Customer, $contact->lifecycle_stage);

        Event::assertDispatched(ContactCreated::class, function (ContactCreated $event) use ($contact): bool {
            return $event->contact->id === $contact->id;
        });
    }

    public function test_can_store_and_query_custom_properties(): void
    {
        $contact1 = Contact::factory()->create([
            'properties' => [
                'tier' => 'enterprise',
                'seat_count' => 50,
            ],
        ]);

        $contact2 = Contact::factory()->create([
            'properties' => [
                'tier' => 'starter',
                'seat_count' => 5,
            ],
        ]);

        $this->assertSame('enterprise', $contact1->getProperty('tier'));
        $this->assertSame(50, $contact1->getProperty('seat_count'));

        $matched = Contact::whereProperty('tier', 'enterprise')->get();

        $this->assertCount(1, $matched);
        $this->assertTrue($matched->first()->is($contact1));
    }

    public function test_soft_deletes_contact(): void
    {
        $contact = Contact::factory()->create();

        $contact->delete();

        $this->assertSoftDeleted('odden_contacts', ['id' => $contact->id]);
        $this->assertNull(Contact::find($contact->id));
        $this->assertNotNull(Contact::withTrashed()->find($contact->id));
    }

    public function test_can_set_prospecting_fields_and_mark_contacted(): void
    {
        $contact = Contact::factory()->create([
            'job_title' => 'Chief Revenue Officer',
            'lead_status' => LeadStatus::Open,
            'linkedin_url' => 'https://linkedin.com/in/sarah-cro',
            'timezone' => 'America/Chicago',
            'last_contacted_at' => null,
        ]);

        $this->assertSame('Chief Revenue Officer', $contact->job_title);
        $this->assertSame(LeadStatus::Open, $contact->lead_status);
        $this->assertSame('https://linkedin.com/in/sarah-cro', $contact->linkedin_url);
        $this->assertSame('America/Chicago', $contact->timezone);
        $this->assertNull($contact->last_contacted_at);

        $now = now();
        $contact->markContacted($now);

        $this->assertNotNull($contact->fresh()->last_contacted_at);
        $this->assertSame($now->toDateTimeString(), $contact->fresh()->last_contacted_at->toDateTimeString());
    }
}
