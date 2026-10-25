<?php

return [
    'enabled' => env('SITESMONKEY_ENABLED', true),
    'website_id' => env('SITESMONKEY_WEBSITE_ID', ''),
    'website_secret' => env('SITESMONKEY_SECRET_KEY', ''),
    'api_url' => env('SITESMONKEY_API_URL', 'https://api.sitesmonkey.com'),
    //
    'auth' => [
        'model' => env('SITESMONKEY_AUTH_MODEL', '\App\Models\User'),
        'route_after_login' => env('SITESMONKEY_AUTH_ROUTE_AFTER_LOGIN', 'dashboard'),

        /**
         * Limits which accounts SitesMonkey may list and impersonate. Leave null
         * to expose every user, or set a closure returning a query builder:
         *
         *   'impersonatable_query' => fn (string $model) => $model::query()->where('is_admin', true),
         */
        'impersonatable_query' => null,
    ],
    'minimal_log_level' => env('SITESMONKEY_MINIMAL_LOG_LEVEL', 400),

    // Errors that are never sent: exception classes (and their subclasses) and regular expressions that are matched
    // against the log message and the exception message.
    'ignore' => [
        'exceptions' => [],
        'messages' => [],
    ],

    // Skip crashes that a visitor who is not logged in causes with a broken request to Livewire (bots).
    'ignore_guest_livewire_payload_errors' => env('SITESMONKEY_IGNORE_GUEST_LIVEWIRE_ERRORS', true),

    // The same error (same exception and message) is sent at most once per this many minutes. 0 sends every one.
    'throttle_minutes' => env('SITESMONKEY_THROTTLE_MINUTES', 10),
];
