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

];
