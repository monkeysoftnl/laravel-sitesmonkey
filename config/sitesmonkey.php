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
    ],
];
