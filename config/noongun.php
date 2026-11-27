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
            // Images. A stored image is a JPEG or a PNG, and LinkedIn takes
            // both. The numbers are LinkedIn's where it documents one, see
            // docs/platform-notes/linkedin.md. It names no size in bytes:
            // 5 MB is a careful figure, because LinkedIn cannot be asked
            // whether it could process a file.
            'max_images' => (int) env('LINKEDIN_MAX_IMAGES', 20),
            'max_image_bytes' => (int) env('LINKEDIN_MAX_IMAGE_BYTES', 5242880),
            'max_image_pixels' => (int) env('LINKEDIN_MAX_IMAGE_PIXELS', 36152319),
            'alt_text_length' => (int) env('LINKEDIN_ALT_TEXT_LENGTH', 4086),
            // Seconds an uploaded image is used for a later attempt before
            // it is sent again. LinkedIn does not say how long it keeps an
            // image that is on no post.
            'image_lifetime' => (int) env('LINKEDIN_IMAGE_LIFETIME', 86400),
            // Hosts an image may be uploaded to, comma separated. Each name
            // allows itself and every name below it. LinkedIn names the
            // address in a response, and the image and the token are sent
            // only to an https address on one of these.
            'upload_hosts' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('LINKEDIN_UPLOAD_HOSTS', 'linkedin.com')),
            ))),
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
    | "media_timeout" is the limit in seconds for sending one image to a
    | platform, all calls for that image together. "media_uploads_per_job" is
    | how many images one run of the job sends before it hands the rest to a
    | successor. The time limit of a job grows by media_timeout x
    | media_uploads_per_job when its post has images.
    */

    'publishing' => [
        'queue' => env('NOONGUN_PUBLISH_QUEUE', 'default'),
        'max_attempts' => (int) env('NOONGUN_PUBLISH_MAX_ATTEMPTS', 5),
        'backoff' => array_map(intval(...), explode(',', (string) env('NOONGUN_PUBLISH_BACKOFF', '60,300,900,3600'))),
        'timeout' => (int) env('NOONGUN_PUBLISH_TIMEOUT', 30),
        'calls_per_job' => (int) env('NOONGUN_PUBLISH_CALLS_PER_JOB', 3),
        'lock_seconds' => (int) env('NOONGUN_PUBLISH_LOCK_SECONDS', 120),
        'stuck_after_minutes' => (int) env('NOONGUN_PUBLISH_STUCK_AFTER_MINUTES', 30),
        'media_timeout' => (int) env('NOONGUN_PUBLISH_MEDIA_TIMEOUT', 60),
        'media_uploads_per_job' => (int) env('NOONGUN_PUBLISH_MEDIA_UPLOADS_PER_JOB', 2),
    ],

    /*
    | Images on posts. See docs/adr/0017-images-on-posts.md.
    |
    | "disk" names a disk in config/filesystems.php. Files are private: they
    | are read through the application and never through a public address,
    | so the "public" disk is refused.
    |
    | "max_bytes" is the largest file one upload may be. PHP must allow it as
    | well: upload_max_filesize and post_max_size in docker/app/php.ini, and
    | the request_body limit of the web server in docker-compose.yml. The
    | smallest of them is what applies.
    | "max_megapixels" and "max_side" are checked from the header of the
    | file, before the image is decoded: decoding takes four bytes of memory
    | for every pixel. "memory_limit" is the PHP memory limit while one image
    | is processed and must cover max_megapixels x 4 MB plus a margin.
    | An image with a longer side than "stored_side" is scaled down to it.
    | "thumbnail_side" is the longest side of the small copy the lists show.
    |
    | "quota_bytes" is the most all stored images of one workspace may take
    | together; 0 means no limit. "uploads_per_minute" is per user.
    | "max_per_variant" is the most images one text may carry, whatever its
    | platform would take.
    | An image that no post uses is deleted "unused_grace_hours" after the
    | clean-up first saw it unused.
    */

    'media' => [
        'disk' => env('NOONGUN_MEDIA_DISK', 'media'),
        'max_bytes' => (int) env('NOONGUN_MEDIA_MAX_BYTES', 20 * 1024 * 1024),
        'max_megapixels' => (float) env('NOONGUN_MEDIA_MAX_MEGAPIXELS', 50),
        'max_side' => (int) env('NOONGUN_MEDIA_MAX_SIDE', 16000),
        'memory_limit' => env('NOONGUN_MEDIA_MEMORY_LIMIT', '512M'),
        'stored_side' => (int) env('NOONGUN_MEDIA_STORED_SIDE', 4096),
        'thumbnail_side' => (int) env('NOONGUN_MEDIA_THUMBNAIL_SIDE', 480),
        'jpeg_quality' => (int) env('NOONGUN_MEDIA_JPEG_QUALITY', 90),
        'quota_bytes' => (int) env('NOONGUN_MEDIA_QUOTA_BYTES', 2 * 1024 * 1024 * 1024),
        'uploads_per_minute' => (int) env('NOONGUN_MEDIA_UPLOADS_PER_MINUTE', 30),
        'max_per_variant' => (int) env('NOONGUN_MEDIA_MAX_PER_VARIANT', 10),
        'unused_grace_hours' => (int) env('NOONGUN_MEDIA_UNUSED_GRACE_HOURS', 24),
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
    | "examples" sends the texts the workspace approved most recently along
    | with a draft request, so that a draft matches the voice and does not
    | repeat what was said. "per_channel" is how many for each target
    | channel, at most 10; 0 sends none. "max_characters" is the most
    | characters all examples of one request may hold together; the oldest
    | are left out first and a text is never cut.
    */

    'drafting' => [
        'provider' => env('NOONGUN_DRAFT_PROVIDER', 'anthropic'),
        'offered' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('NOONGUN_DRAFT_PROVIDERS', ''))))),
        'max_tokens' => (int) env('NOONGUN_DRAFT_MAX_TOKENS', 16000),
        'timeout' => (int) env('NOONGUN_DRAFT_TIMEOUT', 120),
        'prompt_version' => env('NOONGUN_DRAFT_PROMPT_VERSION', 'v2'),
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
