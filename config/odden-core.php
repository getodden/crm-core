<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Odden Core Configuration
    |--------------------------------------------------------------------------
    |
    | Database-agnostic configuration for core CRM models, table prefixes,
    | and custom properties.
    |
    */

    'tables' => [
        'contacts' => 'odden_contacts',
        'companies' => 'odden_companies',
        'properties' => 'odden_properties',
        'associations' => 'odden_associations',
        'association_types' => 'odden_association_types',
        'activities' => 'odden_activities',
        'property_history' => 'odden_property_history',
        'lists' => 'odden_lists',
        'list_memberships' => 'odden_list_memberships',
        'lifecycle_stage_transitions' => 'odden_lifecycle_stage_transitions',
        'custom_object_definitions' => 'odden_custom_object_definitions',
        'custom_object_records' => 'odden_custom_object_records',
        'demo_records' => 'odden_demo_records',
    ],

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model used for owners, assignees and authors across all
    | Odden packages. When null, the default auth provider's model is used
    | (auth.providers.users.model).
    |
    */
    'user_model' => env('ODDEN_USER_MODEL'),

    /*
    |--------------------------------------------------------------------------
    | Domain Auto-Association
    |--------------------------------------------------------------------------
    |
    | Automatically associate created contacts with matching companies by corporate
    | email domain. Excludes consumer freemail domains.
    |
    */
    'auto_associate_companies' => false,

    /*
    |--------------------------------------------------------------------------
    | Custom Freemail Domains
    |--------------------------------------------------------------------------
    |
    | Additional consumer domains to treat as freemail (never auto-create or associate).
    |
    */
    'freemail_domains' => [],

    /*
    |--------------------------------------------------------------------------
    | Email Validation
    |--------------------------------------------------------------------------
    |
    | Every contact gets an email status (valid, risky, invalid, unknown). Without the
    | DNS check it is derived from the address alone: syntax, a typo in a well-known
    | domain, a disposable provider, a shared role mailbox. With it, a domain that
    | accepts no mail is marked invalid. The mailbox itself is never probed.
    |
    | "check_dns" is applied when a contact is created through CreateContactAction;
    | set it to false to keep that step out of the request and run
    | ValidateContactEmailAction from a queue instead. Definite answers are cached
    | per domain for "dns_cache_minutes"; failed lookups (the resolver timed out
    | or errored) for "dns_failure_cache_minutes", so they aren't retried for
    | every contact on that domain.
    |
    */
    'email_validation' => [
        'enabled' => env('ODDEN_EMAIL_VALIDATION', true),
        'check_dns' => env('ODDEN_EMAIL_CHECK_DNS', true),
        'dns_cache_minutes' => 1440,
        'dns_failure_cache_minutes' => 5,

        // The most a DNS check may take, in seconds; slower answers count as unknown. The
        // nameservers asked: empty uses those in /etc/resolv.conf ("1.1.1.1", "10.0.0.2:53").
        'dns_timeout_seconds' => env('ODDEN_EMAIL_DNS_TIMEOUT', 2),
        'dns_nameservers' => [],

        // Extra disposable-provider domains and shared-mailbox local parts to flag as risky.
        'disposable_domains' => [],
        'role_local_parts' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Lifecycle State Machine
    |--------------------------------------------------------------------------
    |
    | Controls whether strict forward progression through the lifecycle funnel
    | is enforced by default.
    |
    */
    'lifecycle' => [
        'strict_transitions' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Requests per minute, per IP address, for the public routes Odden packages
    | register. "public" covers browser-facing submissions (forms, chat, portal
    | replies); "poll" covers read endpoints clients call repeatedly (chat
    | polling, article suggestions); "api" covers token-authenticated webhooks
    | and sending APIs. Each module (odden.service, odden.help, ...) has its own
    | counter.
    |
    */
    'rate_limits' => [
        'public' => (int) env('ODDEN_PUBLIC_RATE_LIMIT', 30),
        'poll' => (int) env('ODDEN_POLL_RATE_LIMIT', 120),
        'api' => (int) env('ODDEN_API_RATE_LIMIT', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pluggable Enrichment Engine
    |--------------------------------------------------------------------------
    |
    | Configuration for automated domain and corporate data enrichment.
    | Available drivers: "heuristic" (no external API; it only returns what
    | the domain itself tells it, never guessed industry, size or tech stack).
    |
    | logo_url: a logo service URL with a {domain} placeholder, for example
    | "https://www.google.com/s2/favicons?domain={domain}&sz=128". Off by
    | default, because using one sends company domains to that service.
    |
    */
    'enrichment' => [
        'driver' => env('ODDEN_ENRICHMENT_DRIVER', 'heuristic'),
        'auto_enrich' => false,
        'logo_url' => env('ODDEN_ENRICHMENT_LOGO_URL'),
    ],
];
