<?php

return [

    /*
    | How this instance runs. "self_hosted": one workspace, created on first
    | run, registration closed, billing inactive. "saas": open registration
    | and one workspace per customer (not built yet).
    */

    'mode' => env('NOONGUN_MODE', 'self_hosted'),

    /*
    | Timezones offered on the setup screen. Any valid identifier is accepted;
    | this list only decides what is shown first.
    */

    'timezones' => [
        'Europe/Copenhagen',
        'Africa/Johannesburg',
        'UTC',
        'Europe/London',
        'Europe/Berlin',
        'Europe/Amsterdam',
        'Europe/Stockholm',
        'Europe/Oslo',
        'Africa/Lagos',
        'Africa/Nairobi',
        'America/New_York',
        'America/Chicago',
        'America/Los_Angeles',
        'Asia/Singapore',
        'Australia/Sydney',
    ],

    'default_timezone' => env('NOONGUN_DEFAULT_TIMEZONE', 'Europe/Copenhagen'),

    'locales' => [
        'en' => 'English',
    ],

    /*
    | Plan limits per workspace. null means unlimited. Self-hosted instances
    | are always unlimited; the saas plans will override these.
    */

    'limits' => [
        'channels' => null,
        'posts_per_month' => null,
        'ai_drafts_per_month' => null,
        'members' => null,
    ],

    'features' => [
        'registration' => env('NOONGUN_MODE', 'self_hosted') === 'saas',
        'billing' => false,
    ],

    /*
    | Platforms that can be connected. The key is the platform name stored on
    | each channel. "adapter" is the ChannelAdapter class; every other key is
    | passed to that adapter as its settings. Adding a platform means one
    | adapter folder and one entry here. API versions and scopes are set here
    | and nowhere else.
    */

    'channels' => [
        'facebook' => [
            'adapter' => 'App\\Domain\\Channels\\Adapters\\Facebook\\FacebookAdapter',
            'app_id' => env('FACEBOOK_APP_ID'),
            'app_secret' => env('FACEBOOK_APP_SECRET'),
            'api_version' => env('FACEBOOK_GRAPH_VERSION', 'v27.0'),
            'graph_url' => env('FACEBOOK_GRAPH_URL', 'https://graph.facebook.com'),
            'dialog_url' => env('FACEBOOK_DIALOG_URL', 'https://www.facebook.com'),
            'scopes' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('FACEBOOK_SCOPES', 'pages_show_list,pages_read_engagement,pages_manage_posts')),
            ))),
            // Longest post body in characters. The Graph API documentation
            // gives no limit; this is the one Facebook has stated for posts.
            // See docs/platform-notes/meta.md.
            'max_body_length' => 63206,
        ],
    ],

    /*
    | How PublishPostJob behaves. "backoff" is the wait in seconds before each
    | retry of a retryable failure, comma separated; the last value repeats.
    | "lock_seconds" is how long the lock on one variant is held at most, and
    | must be longer than "timeout", the limit for one platform call.
    | "calls_per_job" is how many variants one run of the job sends before it
    | hands the rest to a successor. It bounds the time limit of a job, which
    | is timeout x calls_per_job + 15 seconds and must stay below the
    | "retry_after" of the queue connection.
    | "stuck_after_minutes" is how long a post may stay in the publishing
    | state without a sign of life from its job before it is treated as
    | failed. The largest backoff is added to it.
    */

    'publishing' => [
        'queue' => env('NOONGUN_PUBLISH_QUEUE', 'default'),
        'max_attempts' => (int) env('NOONGUN_PUBLISH_MAX_ATTEMPTS', 5),
        'backoff' => array_map(intval(...), explode(',', (string) env('NOONGUN_PUBLISH_BACKOFF', '60,300,900,3600'))),
        'timeout' => (int) env('NOONGUN_PUBLISH_TIMEOUT', 30),
        'calls_per_job' => (int) env('NOONGUN_PUBLISH_CALLS_PER_JOB', 3),
        'lock_seconds' => (int) env('NOONGUN_PUBLISH_LOCK_SECONDS', 120),
        'stuck_after_minutes' => (int) env('NOONGUN_PUBLISH_STUCK_AFTER_MINUTES', 30),
    ],

    /*
    | Drafting with an LLM. "provider" is the provider every workspace uses
    | unless it is given another one. Each entry under "providers" names the
    | DraftGenerator class, the instance API key and the model; adding a
    | provider means one entry here. Model names, limits and prices are set
    | here and nowhere else.
    |
    | "api_key" is the instance key, set by the operator. A key stored for
    | the instance in the database goes before it, and a workspace's own key
    | goes before both. See docs/adr/0011-drafting-contract.md.
    |
    | "max_tokens" limits one answer, including the tokens a model spends on
    | thinking. "timeout" is the limit in seconds for one call.
    | "prompt_version" names a folder under resources/prompts/draft/ and is
    | stored on every draft run. "effort" of a provider is how much work the
    | model puts into one answer (low, medium, high and so on); empty leaves
    | it to the provider's default.
    |
    | "prices" is in "currency" per million tokens, per provider and model,
    | and is only used to estimate the cost of a run. NOONGUN_DRAFT_PRICES
    | adds or replaces entries, as JSON in the same shape:
    | {"anthropic":{"some-model":{"input":1.5,"output":7.5}}}
    | A model without a price gives a run without a cost estimate.
    |
    | "limits" bounds what "Propose a week" may spend. "posts_per_proposal"
    | is the most posts one proposal drafts, "runs_per_day" the most draft
    | runs a workspace may open in 24 hours. "max_attempts" is how many times
    | one planned post is asked for when the provider fails in a way worth
    | trying again, and "backoff" the wait in seconds before each retry,
    | comma separated; the last value repeats. "stuck_after_minutes" is how
    | long a draft run may stay unfinished before it is marked as failed.
    | "estimate_input_tokens" is the prompt size assumed for the cost ceiling
    | shown before a proposal starts. "weeks_ahead" is how far ahead a week
    | can be proposed.
    */

    'drafting' => [
        'provider' => env('NOONGUN_DRAFT_PROVIDER', 'anthropic'),
        'max_tokens' => (int) env('NOONGUN_DRAFT_MAX_TOKENS', 16000),
        'timeout' => (int) env('NOONGUN_DRAFT_TIMEOUT', 120),
        'prompt_version' => env('NOONGUN_DRAFT_PROMPT_VERSION', 'v1'),
        'currency' => env('NOONGUN_DRAFT_PRICE_CURRENCY', 'USD'),

        'providers' => [
            'anthropic' => [
                'generator' => 'App\\Domain\\Drafting\\Generators\\PrismDraftGenerator',
                'api_key' => env('ANTHROPIC_API_KEY'),
                'model' => env('NOONGUN_DRAFT_ANTHROPIC_MODEL', 'claude-opus-5-5'),
                'effort' => env('NOONGUN_DRAFT_ANTHROPIC_EFFORT'),
            ],
        ],

        'prices' => array_replace_recursive(
            [
                'anthropic' => [
                    'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
                    'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
                    'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
                ],
            ],
            (array) json_decode((string) env('NOONGUN_DRAFT_PRICES', '{}'), true),
        ),

        'limits' => [
            'posts_per_proposal' => (int) env('NOONGUN_DRAFT_MAX_POSTS_PER_PROPOSAL', 14),
            'runs_per_day' => (int) env('NOONGUN_DRAFT_MAX_RUNS_PER_DAY', 50),
            'max_attempts' => (int) env('NOONGUN_DRAFT_MAX_ATTEMPTS', 3),
            'backoff' => array_map(intval(...), explode(',', (string) env('NOONGUN_DRAFT_BACKOFF', '30,120'))),
            'stuck_after_minutes' => (int) env('NOONGUN_DRAFT_STUCK_AFTER_MINUTES', 15),
            'estimate_input_tokens' => (int) env('NOONGUN_DRAFT_ESTIMATE_INPUT_TOKENS', 8000),
            'weeks_ahead' => 8,
        ],
    ],

];
