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

    /*
    | Who may open the Horizon dashboard: the email addresses of the people
    | who run this instance, comma separated. The account must have a verified
    | email address. When the list is empty, a self-hosted instance falls back
    | to the owners of its first workspace, and a saas instance lets nobody in.
    */

    'operators' => array_values(array_filter(array_map(
        fn (string $email): string => mb_strtolower(trim($email)),
        explode(',', (string) env('NOONGUN_OPERATOR_EMAILS', '')),
    ))),

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
            // The permission that reach and clicks of a post need. It is not
            // requested unless it is added to FACEBOOK_SCOPES; a Page
            // connected without it gets reactions, comments and shares only.
            'insights_scope' => 'read_insights',
            // Calls a Page token may make in 24 hours: Meta allows 4800 for
            // each user who engaged with the Page, and this is that limit at
            // one such user. Reading metrics uses a share of it.
            'rate_limit_calls_per_day' => 4800,
            // How the numbers of a post are read. "post_fields" is asked of
            // the post itself: totals only (limit(0) keeps the people out
            // of the answer), comments counted with their replies (filter
            // "stream"). "clicks" and "reach" are the names of the insights
            // metrics; Meta renames these from time to time, and an empty
            // name means the number is not asked for. "batch_size" is the
            // most reads in one batch request, at most 50.
            'metrics' => [
                'post_fields' => 'reactions.summary(total_count).limit(0),comments.filter(stream).summary(true).limit(0),shares',
                'clicks' => env('FACEBOOK_METRIC_CLICKS', 'post_clicks'),
                'reach' => env('FACEBOOK_METRIC_REACH', 'post_total_media_view_unique'),
                'period' => 'lifetime',
                'batch_size' => 50,
            ],
        ],

        // A member's own profile, through the self-serve products "Share on
        // LinkedIn" and "Sign In with LinkedIn using OpenID Connect". See
        // docs/platform-notes/linkedin.md and docs/adr/0007.
        'linkedin' => [
            'adapter' => 'App\\Domain\\Channels\\Adapters\\LinkedIn\\LinkedInAdapter',
            'client_id' => env('LINKEDIN_CLIENT_ID'),
            'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
            // Sent as the Linkedin-Version header, YYYYMM. LinkedIn supports
            // a version for at least a year and refuses it after that.
            'api_version' => env('LINKEDIN_API_VERSION', '202611'),
            'api_url' => env('LINKEDIN_API_URL', 'https://api.linkedin.com'),
            // Where the sign-in, the token endpoints and the posts live.
            'web_url' => env('LINKEDIN_WEB_URL', 'https://www.linkedin.com'),
            'scopes' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('LINKEDIN_SCOPES', 'openid,profile,w_member_social')),
            ))),
            // The one scope that allows posting. A sign-in whose answer
            // names its scopes without this one is refused.
            'publish_scope' => 'w_member_social',
            // Longest post text in characters. The Posts API documentation
            // names no number; this is the limit of the LinkedIn composer.
            'max_body_length' => 3000,
            // Requests per member per day that Share on LinkedIn allows.
            'rate_limit_per_day' => 150,
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
    | Asking each platform whether the token of a connected channel is still
    | good, and recording the permissions and the expiry it reports. Every
    | active channel is checked once in "every_hours"; the scheduler queues
    | at most "channels_per_run" checks each hour. A channel is marked as
    | needing a reconnect only when the platform says so in so many words.
    | "queue" is the queue of the checks: the one for reading metrics, which
    | has a worker of its own, so a check never stands in front of a post.
    */

    'token_check' => [
        'enabled' => filter_var(env('NOONGUN_TOKEN_CHECK_ENABLED', true), FILTER_VALIDATE_BOOL),
        'every_hours' => (int) env('NOONGUN_TOKEN_CHECK_EVERY_HOURS', 24),
        'channels_per_run' => 50,
        'queue' => env('NOONGUN_INSIGHTS_QUEUE', 'insights'),
    ],

    /*
    | Reading the numbers of published posts (reactions, comments, shares,
    | clicks, reach). Only platforms whose adapter reads metrics are asked;
    | see docs/adr/0016-insights.md.
    |
    | "cadence" says how often a published post is read, by its age, as
    | "hours:minutes" pairs, comma separated: until the post is that many
    | hours old, read it every that many minutes. A post older than the last
    | pair is not read any more. The default reads every hour during the
    | first day, then once a day until the post is three weeks old.
    | "variants_per_run" is the most posts of one channel that one run of
    | the job reads. "rate_limit_percent" is the share of the platform's
    | rate limit for a channel that reading may use, so that publishing
    | always has calls left. "pause_minutes" is how long a channel is left
    | alone after the platform refused a read; a longer wait the platform
    | asks for is respected. "timeout" is the limit in seconds for one run
    | of the job. "queue" is the queue the job runs on; it has a supervisor
    | of its own in config/horizon.php. "gone_after" is how many times in a
    | row a platform must answer that a post is gone before it is not asked
    | about any more.
    |
    | "score" holds the weights of the engagement score, the one number
    | posts are compared by: each count times its weight, added up. Reach is
    | not part of it, so the score also works where reach is not available.
    */

    'insights' => [
        'enabled' => filter_var(env('NOONGUN_INSIGHTS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'queue' => env('NOONGUN_INSIGHTS_QUEUE', 'insights'),
        'cadence' => env('NOONGUN_INSIGHTS_CADENCE', '24:60,504:1440'),
        'variants_per_run' => (int) env('NOONGUN_INSIGHTS_VARIANTS_PER_RUN', 25),
        // The most posts handed to a platform in one call; what came back
        // is stored after each call. A post that was never read is read
        // once when it is older than the cadence reaches but not older than
        // "backfill_days"; 0 turns that off.
        'variants_per_call' => 25,
        'backfill_days' => (int) env('NOONGUN_INSIGHTS_BACKFILL_DAYS', 365),
        'rate_limit_percent' => (int) env('NOONGUN_INSIGHTS_RATE_LIMIT_PERCENT', 20),
        'pause_minutes' => (int) env('NOONGUN_INSIGHTS_PAUSE_MINUTES', 60),
        'timeout' => (int) env('NOONGUN_INSIGHTS_TIMEOUT', 45),
        'gone_after' => (int) env('NOONGUN_INSIGHTS_GONE_AFTER', 3),

        'score' => [
            'reactions' => env('NOONGUN_INSIGHTS_WEIGHT_REACTIONS', 1),
            'comments' => env('NOONGUN_INSIGHTS_WEIGHT_COMMENTS', 2),
            'shares' => env('NOONGUN_INSIGHTS_WEIGHT_SHARES', 3),
            'clicks' => env('NOONGUN_INSIGHTS_WEIGHT_CLICKS', 1),
        ],
    ],

    /*
    | Drafting with an LLM. "provider" is the provider a workspace uses until
    | its owner chooses another one. "offered" lists the providers an owner
    | may choose from, comma separated in NOONGUN_DRAFT_PROVIDERS; the
    | default provider is always among them, and a name without an entry
    | under "providers" is ignored. Each entry under "providers" names the
    | DraftGenerator class, the instance API key and the model; adding a
    | provider means one entry here. Model names, limits and prices are set
    | here and nowhere else. See docs/adr/0013-provider-per-workspace.md.
    |
    | "api_key" is the instance key of that provider, set by the operator. A
    | key stored for the instance in the database goes before it, and a
    | workspace's own key for that provider goes before both. A key is only
    | ever used with the provider it was stored for. "key_prefix" is how the
    | keys of a provider start; the key form uses it to refuse a key that
    | was pasted under the wrong provider. See
    | docs/adr/0011-drafting-contract.md.
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
    | "cached_input" and "cache_write" are optional prices for input tokens
    | a provider read from or wrote to its prompt cache; without them such
    | tokens are counted at the "input" price.
    |
    | "limits" bounds what "Propose a week" may spend. "posts_per_proposal"
    | is the most posts one proposal drafts, "runs_per_day" the most draft
    | runs a workspace may open in 24 hours, and "instance_runs_per_day" the
    | most all workspaces together may open on the instance key. Only runs
    | that reached a provider or are still open count. "max_attempts" is how many times
    | one planned post is asked for when the provider fails in a way worth
    | trying again, and "backoff" the wait in seconds before each retry,
    | comma separated; the last value repeats. "stuck_after_minutes" is how
    | long a draft run may stay unfinished before it is marked as failed.
    | "expected_output_tokens" is the size of a usual answer, thinking
    | included, for the expected cost shown before a proposal starts.
    | "lead_minutes" is how far ahead a slot time must be to get a draft, so
    | that it can be read and approved in time. "weeks_ahead" is how far
    | ahead a week can be proposed.
    |
    | "examples" sends texts the workspace approved along with a draft
    | request, so that a draft matches the voice and does not repeat what
    | was said. "per_channel" is how many for each target channel, at most
    | 10; 0 sends none. "max_characters" is the most characters all examples
    | of one request may hold together; a text is never cut. "top_share" is
    | the share of the examples of a channel, in percent, that goes to the
    | approved posts that did best instead of the most recent ones; 0 takes
    | only the most recent. "top_days" is how many days back a post may have
    | been published to be taken for how it did; 0 takes none. See
    | docs/adr/0011-drafting-contract.md.
    */

    'drafting' => [
        'provider' => env('NOONGUN_DRAFT_PROVIDER', 'anthropic'),
        'offered' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('NOONGUN_DRAFT_PROVIDERS', ''))))),
        'max_tokens' => (int) env('NOONGUN_DRAFT_MAX_TOKENS', 16000),
        'timeout' => (int) env('NOONGUN_DRAFT_TIMEOUT', 120),
        'prompt_version' => env('NOONGUN_DRAFT_PROMPT_VERSION', 'v3'),
        'currency' => env('NOONGUN_DRAFT_PRICE_CURRENCY', 'USD'),

        'providers' => [
            'anthropic' => [
                'label' => 'Anthropic',
                'generator' => 'App\\Domain\\Drafting\\Generators\\PrismDraftGenerator',
                'api_key' => env('ANTHROPIC_API_KEY'),
                'model' => env('NOONGUN_DRAFT_ANTHROPIC_MODEL', 'claude-opus-5-5'),
                'effort' => env('NOONGUN_DRAFT_ANTHROPIC_EFFORT'),
                'key_prefix' => 'sk-ant-',
            ],
            'openai' => [
                'label' => 'OpenAI',
                'generator' => 'App\\Domain\\Drafting\\Generators\\PrismDraftGenerator',
                'api_key' => env('OPENAI_API_KEY'),
                'model' => env('NOONGUN_DRAFT_OPENAI_MODEL', 'gpt-6.2-sol'),
                'effort' => env('NOONGUN_DRAFT_OPENAI_EFFORT'),
                'key_prefix' => 'sk-',
            ],
        ],

        'prices' => array_replace_recursive(
            [
                'anthropic' => [
                    'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
                    'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
                    'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
                ],
                'openai' => [
                    'gpt-6.2-astra' => ['input' => 8.00, 'output' => 40.00, 'cached_input' => 0.40, 'cache_write' => 10.00],
                    'gpt-6.2-sol' => ['input' => 2.00, 'output' => 10.00, 'cached_input' => 0.10, 'cache_write' => 2.50],
                    'gpt-6.1-terra' => ['input' => 1.00, 'output' => 5.00, 'cached_input' => 0.05, 'cache_write' => 1.25],
                    'gpt-6-luna' => ['input' => 0.10, 'output' => 0.50, 'cached_input' => 0.01, 'cache_write' => 0.125],
                ],
            ],
            (array) json_decode((string) env('NOONGUN_DRAFT_PRICES', '{}'), true),
        ),

        'examples' => [
            'per_channel' => env('NOONGUN_DRAFT_EXAMPLES', 5),
            'max_characters' => env('NOONGUN_DRAFT_EXAMPLES_MAX_CHARS', 6000),
            'top_share' => env('NOONGUN_DRAFT_EXAMPLES_TOP_SHARE', 50),
            'top_days' => env('NOONGUN_DRAFT_EXAMPLES_TOP_DAYS', 90),
        ],

        'limits' => [
            'posts_per_proposal' => (int) env('NOONGUN_DRAFT_MAX_POSTS_PER_PROPOSAL', 14),
            'runs_per_day' => (int) env('NOONGUN_DRAFT_MAX_RUNS_PER_DAY', 50),
            'instance_runs_per_day' => (int) env('NOONGUN_DRAFT_MAX_INSTANCE_RUNS_PER_DAY', 200),
            'max_attempts' => (int) env('NOONGUN_DRAFT_MAX_ATTEMPTS', 3),
            'backoff' => array_map(intval(...), explode(',', (string) env('NOONGUN_DRAFT_BACKOFF', '30,120'))),
            'stuck_after_minutes' => (int) env('NOONGUN_DRAFT_STUCK_AFTER_MINUTES', 6),
            'expected_output_tokens' => (int) env('NOONGUN_DRAFT_EXPECTED_OUTPUT_TOKENS', 1500),
            'lead_minutes' => (int) env('NOONGUN_DRAFT_LEAD_MINUTES', 60),
            'weeks_ahead' => 8,
        ],
    ],

];
