<?php

namespace MonkeySoft\SitesMonkey\Logging;

use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;

class SitesMonkeyLoggerHandler extends AbstractProcessingHandler
{
    public function write(\Monolog\LogRecord $record): void
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
                '%s/api/v1/create-error-message',
                config('sitesmonkey.api_url')
            );

            // Send the log record to the SitesMonkey API
            $result = Http::post($logginUrl, [
                'website_id' => config('sitesmonkey.website_id'),
                'website_secret_key' => config('sitesmonkey.website_secret'),
                'error_code' => $record->level->value,
                'error_data' => (string) $record->context['exception'],
                'category' => $record->channel,
                'error_message' => $record->message,
            ])->throw()->json();
        } catch (\Exception $e) {
            // Handle any exceptions that occur during the HTTP request
            // You might want to log this exception or handle it in some way
        }
    }
}
