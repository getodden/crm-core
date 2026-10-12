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
use Odden\Core\Database\Factories\CompanyFactory;
use Odden\Core\Enums\CustomerHealthStatus;
use Odden\Core\Enums\LifecycleStage;
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
 * @property string $name
 * @property string|null $domain
 * @property string|null $phone
 * @property string|null $industry
 * @property LifecycleStage|null $lifecycle_stage
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
 * @property string|null $account_tier
 * @property int $intent_score
 * @property bool $intent_surge
 * @property int $health_score
 * @property CustomerHealthStatus $health_status
 * @property CarbonInterface|null $last_health_calculated_at
 * @property int $buying_committee_size
 * @property CarbonInterface|null $last_intent_activity_at
 * @property array<string, mixed>|null $properties
 * @property int|null $owner_id
 * @property int|null $team_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Company extends Model
{
    use AuditsProperties;
    use BelongsToTeam;
    use HasActivities;
    use HasAssociations;
    use HasCustomProperties;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use HasLifecycleStageTransitions;
    use QualifiesRelatedColumns;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'domain',
        'phone',
        'industry',
        'lifecycle_stage',
        'became_subscriber_at',
        'became_lead_at',
        'became_marketing_qualified_lead_at',
        'became_sales_qualified_lead_at',
        'became_opportunity_at',
        'became_customer_at',
        'became_evangelist_at',
        'became_other_at',
        'account_tier',
        'intent_score',
        'intent_surge',
        'health_score',
        'health_status',
        'last_health_calculated_at',
        'buying_committee_size',
        'last_intent_activity_at',
        'properties',
        'owner_id',
        'team_id',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-core.tables.companies', 'odden_companies');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lifecycle_stage' => LifecycleStage::class,
            'became_subscriber_at' => 'datetime',
            'became_lead_at' => 'datetime',
            'became_marketing_qualified_lead_at' => 'datetime',
            'became_sales_qualified_lead_at' => 'datetime',
            'became_opportunity_at' => 'datetime',
            'became_customer_at' => 'datetime',
            'became_evangelist_at' => 'datetime',
            'became_other_at' => 'datetime',
            'properties' => 'array',
            'intent_score' => 'integer',
            'intent_surge' => 'boolean',
            'health_score' => 'integer',
            'health_status' => CustomerHealthStatus::class,
            'last_health_calculated_at' => 'datetime',
            'buying_committee_size' => 'integer',
            'last_intent_activity_at' => 'datetime',
        ];
    }

    /**
     * Determine if customer account is in healthy standing.
     */
    public function isHealthy(): bool
    {
        return $this->health_status === CustomerHealthStatus::Healthy;
    }

    /**
     * Determine if customer account is at risk of churn.
     */
    public function isAtRisk(): bool
    {
        return $this->health_status === CustomerHealthStatus::AtRisk;
    }

    /**
     * Determine if this account is a qualified high-priority target account.
     */
    public function isTargetAccount(): bool
    {
        return in_array($this->account_tier, ['tier_1', 'tier_2'], true);
    }

    /**
     * Determine if account has surging buyer intent.
     */
    public function isSurging(): bool
    {
        return $this->intent_surge;
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
     * Associated contacts.
     *
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(
            Contact::class,
            config('odden-core.tables.associations', 'odden_associations'),
            'child_id',
            'parent_id'
        )
            ->wherePivot('child_type', $this->getMorphClass())
            ->wherePivot('parent_type', (new Contact)->getMorphClass())
            ->withPivot(['id', 'type'])
            ->withTimestamps();
    }

    /**
     * Scope query to find company by domain.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWhereDomain(Builder $query, string $domain): Builder
    {
        return $query->where('domain', static::normalizeDomain($domain));
    }

    /**
     * A website address reduced to its host: lowercased and trimmed, without the scheme, a
     * leading `www.`, a port, or anything after the host (https://www.Acme.com/about → acme.com).
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain) ?? $domain;
        $domain = preg_replace('#[/?\#].*$#s', '', $domain) ?? $domain;
        $domain = preg_replace('#:\d+$#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '.');

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }
}
