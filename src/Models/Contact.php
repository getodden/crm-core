<?php

declare(strict_types=1);

namespace Odden\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Odden\Core\Areas\ProtectedByArea;
use Odden\Core\Database\Factories\ContactFactory;
use Odden\Core\Encryption\Casts\CustomProperties;
use Odden\Core\Encryption\Casts\EncryptedDate;
use Odden\Core\Encryption\Casts\EncryptedString;
use Odden\Core\Encryption\Index\BlindIndexed;
use Odden\Core\Encryption\Index\HasBlindIndexes;
use Odden\Core\Encryption\Index\IndexText;
use Odden\Core\Enums\EmailStatus;
use Odden\Core\Enums\EmailStatusReason;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Support\ContactLookup;
use Odden\Core\Support\EmailValidator;
use Odden\Core\Support\UserModel;
use Odden\Core\Traits\AuditsProperties;
use Odden\Core\Traits\BelongsToTeam;
use Odden\Core\Traits\HasActivities;
use Odden\Core\Traits\HasAssociations;
use Odden\Core\Traits\HasCustomProperties;
use Odden\Core\Traits\HasLifecycleStageTransitions;
use Odden\Core\Traits\QualifiesRelatedColumns;

/**
 * @property int $id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $job_title
 * @property string $email
 * @property EmailStatus|null $email_status
 * @property EmailStatusReason|null $email_status_reason
 * @property CarbonInterface|null $email_checked_at
 * @property CarbonInterface|null $date_of_birth
 * @property string|null $phone
 * @property string|null $linkedin_url
 * @property string|null $timezone
 * @property LifecycleStage $lifecycle_stage
 * @property LeadStatus $lead_status
 * @property int $lead_score
 * @property CarbonInterface|null $lead_score_updated_at
 * @property array<string, mixed>|null $properties
 * @property CarbonInterface|null $last_contacted_at
 * @property int|null $owner_id
 * @property int|null $team_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property CarbonInterface|null $marketing_email_verified_at
 * @property string|null $marketing_verification_token
 * @property string|null $marketing_confirmation_token
 * @property list<string>|null $marketing_topics
 * @property CarbonInterface|null $last_marketing_email_sent_at
 * @property bool $is_unengaged
 * @property CarbonInterface|null $unengaged_since
 * @property string|null $sunset_stage
 * @property bool $sms_consent
 * @property CarbonInterface|null $sms_consent_at
 * @property CarbonInterface|null $became_subscriber_at
 * @property CarbonInterface|null $became_lead_at
 * @property CarbonInterface|null $became_marketing_qualified_lead_at
 * @property CarbonInterface|null $became_sales_qualified_lead_at
 * @property CarbonInterface|null $became_opportunity_at
 * @property CarbonInterface|null $became_customer_at
 * @property CarbonInterface|null $became_evangelist_at
 * @property CarbonInterface|null $became_other_at
 * @property-read CarbonInterface|null $became_mql_at
 * @property-read CarbonInterface|null $became_sql_at
 * @property-read string $full_name
 */
class Contact extends Model implements BlindIndexed
{
    use AuditsProperties;
    use BelongsToTeam;
    use HasActivities;
    use HasAssociations;
    use HasBlindIndexes;
    use HasCustomProperties;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    use HasLifecycleStageTransitions;
    use Notifiable;
    use ProtectedByArea;
    use QualifiesRelatedColumns;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'job_title',
        'email',
        'email_status',
        'email_status_reason',
        'email_checked_at',
        'phone',
        'linkedin_url',
        'timezone',
        'date_of_birth',
        'lifecycle_stage',
        'became_subscriber_at',
        'became_lead_at',
        'became_marketing_qualified_lead_at',
        'became_sales_qualified_lead_at',
        'became_opportunity_at',
        'became_customer_at',
        'became_evangelist_at',
        'became_other_at',
        'lead_status',
        'lead_score',
        'lead_score_updated_at',
        'sms_consent',
        'sms_consent_at',
        'marketing_email_verified_at',
        'marketing_verification_token',
        'marketing_confirmation_token',
        'marketing_topics',
        'last_marketing_email_sent_at',
        'is_unengaged',
        'unengaged_since',
        'sunset_stage',
        'properties',
        'last_contacted_at',
        'owner_id',
        'team_id',
    ];

    /**
     * Not shown when a contact is turned into an array or JSON (an API response, a log line): a date of birth is personal data
     * about a person who may be a child.
     *
     * @var list<string>
     */
    protected $hidden = ['date_of_birth'];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-core.tables.contacts', 'odden_contacts');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_name' => EncryptedString::class.':contacts.first_name',
            'last_name' => EncryptedString::class.':contacts.last_name',
            'job_title' => EncryptedString::class.':contacts.job_title',
            'email' => EncryptedString::class.':contacts.email',
            'phone' => EncryptedString::class.':contacts.phone',
            'linkedin_url' => EncryptedString::class.':contacts.linkedin_url',
            'timezone' => EncryptedString::class.':contacts.timezone',
            'email_status' => EmailStatus::class,
            'email_status_reason' => EmailStatusReason::class,
            'email_checked_at' => 'datetime',
            'date_of_birth' => EncryptedDate::class.':contacts.date_of_birth',
            'lifecycle_stage' => LifecycleStage::class,
            'became_subscriber_at' => 'datetime',
            'became_lead_at' => 'datetime',
            'became_marketing_qualified_lead_at' => 'datetime',
            'became_sales_qualified_lead_at' => 'datetime',
            'became_opportunity_at' => 'datetime',
            'became_customer_at' => 'datetime',
            'became_evangelist_at' => 'datetime',
            'became_other_at' => 'datetime',
            'lead_status' => LeadStatus::class,
            'lead_score' => 'integer',
            'lead_score_updated_at' => 'datetime',
            'sms_consent' => 'boolean',
            'sms_consent_at' => 'datetime',
            'marketing_email_verified_at' => 'datetime',
            'marketing_topics' => 'array',
            'last_marketing_email_sent_at' => 'datetime',
            'is_unengaged' => 'boolean',
            'unengaged_since' => 'datetime',
            'properties' => CustomProperties::class,
            'last_contacted_at' => 'datetime',
        ];
    }

    /**
     * Whether a message to this address bounced. A bounce is evidence from a real delivery attempt, so
     * it outranks anything the offline and DNS checks can say; only a change of address clears it.
     */
    public function hasBounced(): bool
    {
        return $this->email_status === EmailStatus::Invalid && $this->email_status_reason === EmailStatusReason::Bounced;
    }

    /**
     * The contact's age in whole years on a date (today by default), or null when it is not known: no date of birth, or one in the
     * future. An unknown age is not an adult age; whoever asks must decide what to do without one.
     */
    public function age(?CarbonInterface $on = null): ?int
    {
        $born = $this->date_of_birth;
        $on ??= now();

        if ($born === null || $born->startOfDay()->greaterThan($on)) {
            return null;
        }

        return (int) floor($born->startOfDay()->diffInYears($on->copy()->startOfDay()));
    }

    /**
     * Mark this contact as contacted with timestamp.
     */
    public function markContacted(?CarbonInterface $at = null): self
    {
        $this->update([
            'last_contacted_at' => $at ?? now(),
        ]);

        return $this;
    }

    /**
     * Get the contact's full name, falling back to email.
     */
    public function getFullNameAttribute(): string
    {
        $name = trim("{$this->first_name} {$this->last_name}");

        return $name !== '' ? $name : $this->email;
    }

    /**
     * Alias for became_marketing_qualified_lead_at.
     */
    public function getBecameMqlAtAttribute(): ?CarbonInterface
    {
        return $this->became_marketing_qualified_lead_at;
    }

    /**
     * Alias for became_sales_qualified_lead_at.
     */
    public function getBecameSqlAtAttribute(): ?CarbonInterface
    {
        return $this->became_sales_qualified_lead_at;
    }

    /**
     * The owner / assigned sales rep.
     *
     * @return BelongsTo<Model, $this>
     */
    public function owner(): BelongsTo
    {
        $userModel = UserModel::className();

        return $this->belongsTo($userModel, 'owner_id');
    }

    /**
     * Associated companies.
     *
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(
            Company::class,
            config('odden-core.tables.associations', 'odden_associations'),
            'parent_id',
            'child_id'
        )
            ->wherePivot('parent_type', $this->getMorphClass())
            ->wherePivot('child_type', (new Company)->getMorphClass())
            ->withPivot(['id', 'type'])
            ->withTimestamps();
    }

    /**
     * The columns found again through the search index, and how: the address and the phone number as a whole, the names and the
     * job title by word, the names also by the start of a word.
     *
     * @return array<string, array<string, string|true>>
     */
    public static function blindIndexedColumns(): array
    {
        return [
            'email' => ['exact' => IndexText::EMAIL],
            'phone' => ['exact' => IndexText::DIGITS],
            'first_name' => ['exact' => IndexText::TEXT, 'word' => true, 'prefix' => true],
            'last_name' => ['exact' => IndexText::TEXT, 'word' => true, 'prefix' => true],
            'job_title' => ['exact' => IndexText::TEXT, 'word' => true],
        ];
    }

    public static function blindIndexPrefix(): string
    {
        return 'contacts';
    }

    /**
     * Adds the full name (first and last together), so duplicates are found by name without decrypting.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function blindIndexEntries(): array
    {
        $entries = $this->blindIndexEntriesFromColumns();
        $first = IndexText::normalize(IndexText::TEXT, (string) $this->first_name);
        $last = IndexText::normalize(IndexText::TEXT, (string) $this->last_name);

        $entries[static::blindIndexField('full_name')] = ['exact' => $first !== '' && $last !== '' ? [$first.' '.$last] : []];

        return $entries;
    }

    /**
     * Scope query to find contact by email.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWhereEmail(Builder $query, string $email): Builder
    {
        return $this->scopeWhereBlindExact($query, 'email', ContactLookup::normalizeEmail($email));
    }

    /**
     * Contacts where a word of the first or last name starts with each word typed ("ann le" finds Annie Lee).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWhereNameStartsWith(Builder $query, string $text): Builder
    {
        return $this->scopeWhereBlindWords($query, ['first_name', 'last_name'], $text);
    }

    /**
     * A search box: matches the typed words against the start of the names, and an address or a phone number typed in full.
     * Anywhere-inside matching is not available on encrypted columns.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearchIdentity(Builder $query, string $term): Builder
    {
        $term = trim($term);

        return $query->where(function (Builder $q) use ($term): void {
            $q->where(fn (Builder $names) => $this->scopeWhereNameStartsWith($names, $term));

            if (str_contains($term, '@')) {
                $q->orWhere(fn (Builder $email) => $this->scopeWhereEmail($email, $term));
            }

            if (strlen(IndexText::normalize(IndexText::DIGITS, $term)) >= 5) {
                $q->orWhere(fn (Builder $phone) => $this->scopeWhereBlindExact($phone, 'phone', $term));
            }
        });
    }

    /**
     * Record what can be known about the address without touching the network, so every way a
     * contact is created or re-addressed leaves an honest status behind. The DNS check is a
     * separate step (see ValidateContactEmailAction) because it should not run inside a save.
     * A new address never inherits the previous address's status.
     */
    protected static function booted(): void
    {
        // Addresses are stored lowercased and trimmed, so lookups by email can use the index.
        static::saving(function (Contact $contact): void {
            $email = $contact->getAttribute('email');

            if ($contact->isDirty('email') && is_string($email)) {
                $contact->email = ContactLookup::normalizeEmail($email);
            }
        });

        static::creating(function (Contact $contact): void {
            if ($contact->email_status === null) {
                static::stampEmailStatus($contact);
            }
        });

        static::updating(function (Contact $contact): void {
            if ($contact->isDirty('email') && ! $contact->isDirty('email_status')) {
                static::stampEmailStatus($contact);
            }
        });
    }

    protected static function stampEmailStatus(Contact $contact): void
    {
        if (! config('odden-core.email_validation.enabled', true) || trim((string) $contact->email) === '') {
            $contact->email_status = null;
            $contact->email_status_reason = null;
            $contact->email_checked_at = null;

            return;
        }

        $check = app(EmailValidator::class)->check((string) $contact->email, checkDns: false);

        $contact->email_status = $check->status;
        $contact->email_status_reason = $check->reason;
        $contact->email_checked_at = now();
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }
}
