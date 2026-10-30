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

    // Vulnerability monitoring: the list of installed packages is sent to SitesMonkey every hour, signed with a key that
    // belongs to this website. Create the key in SitesMonkey (Website > Connector key) and put it in .env.
    'inventory' => [
        'enabled' => env('SITESMONKEY_INVENTORY_ENABLED', true),
        'key_id' => env('SITESMONKEY_INVENTORY_KEY_ID', ''),
        'key_secret' => env('SITESMONKEY_INVENTORY_KEY_SECRET', ''),

        // Cron expression of the push. Null picks a fixed minute of every hour that is different per website.
        'schedule' => env('SITESMONKEY_INVENTORY_CRON'),

        // Only these PHP extensions are reported (name and version).
        'php_extensions' => [
            'bcmath', 'curl', 'dom', 'exif', 'fileinfo', 'gd', 'gmp', 'iconv', 'imagick', 'intl', 'libxml', 'mbstring',
            'mysqlnd', 'openssl', 'pcre', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'redis', 'sodium', 'sqlite3', 'xml',
            'xmlreader', 'xmlwriter', 'zend-opcache', 'zip', 'zlib',
        ],

        // Node lockfiles (relative to the application) whose production packages are part of what you run, for
        // example ['package-lock.json']. Leave empty when Node is only used to build assets. Supports
        // package-lock.json, npm-shrinkwrap.json, yarn.lock and pnpm-lock.yaml (YAML files need symfony/yaml).
        'node_lockfiles' => [],
    ],
];
