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

];
