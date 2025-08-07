<?php

namespace MonkeySoft\SitesMonkey\Logging;

use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;

class SitesMonkeyLoggerHandler extends AbstractProcessingHandler
{
    public function write(\Monolog\LogRecord $record): void
    {
        try {
            Http::post(config('sitesmonkey.api_url'), [
                'website_id' => config('sitesmonkey.website_id'),
                'website_secret_key' => config('sitesmonkey.website_secret'),
                'error_code' => $record->level->value,
                'error_data' => $record->context,
                'category' => $record->channel,
                'error_message' => $record->formatted,
            ]);
        } catch (\Exception $e) {
            // Handle any exceptions that occur during the HTTP request
            // You might want to log this exception or handle it in some way
        }
    }
}
