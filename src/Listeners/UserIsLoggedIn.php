<?php

namespace MonkeySoft\SitesMonkey\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Http;

class UserIsLoggedIn
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        try {
            if (! config('sitesmonkey.enabled')) {
                return; // Skip logging if SitesMonkey is not enabled
            }
            // Ensure the required configuration is set
            if (empty(config('sitesmonkey.website_id')) || empty(config('sitesmonkey.website_secret'))) {
                return; // Skip logging if website ID or secret key is not set
            }

            Http::globalOptions([
                'timeout' => 5, /* in seconds (default 30s) */
            ]);

            $logginUrl = sprintf(
                '%s/api/v1/successful-login',
                config('sitesmonkey.api_url')
            );

            // Send the log record to the SitesMonkey API
            $result = Http::post($logginUrl, [
                'website_id' => config('sitesmonkey.website_id'),
                'website_secret_key' => config('sitesmonkey.website_secret'),
                'ip_address' => request()->ip() ?? '',
                'user_agent' => request()->header('User-Agent') ?? '',
                'user_id' => $event->user->id ?? $event->user->getKey() ?? '',
                'user_name' => $event->user->name ?? $event->user->fullName ?? '',
                'user_email' => $event->user->email ?? '',
                'action' => 'successful_login',
            ])->throw()->json();
        } catch (\Exception $e) {
            // Handle any exceptions that occur during the HTTP request
            // You might want to log this exception or handle it in some way
        }
    }
}
