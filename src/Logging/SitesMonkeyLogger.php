<?php

namespace MonkeySoft\SitesMonkey\Logging;

use App\Logging\SitesMonkeyLoggerHandler;
use Monolog\Logger;

class SitesMonkeyLogger
{
    /**
     * Create a custom Monolog instance.
     */
    public function __invoke(array $config): Logger
    {
        return new Logger(
            config('app.name'),
            [
                new SitesMonkeyLoggerHandler,
            ]
        );
    }
}
