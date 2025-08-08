<?php

namespace MonkeySoft\SitesMonkey\Logging;

use Monolog\Logger;

class SitesMonkeyLogger
{
    /**
     * Create a custom Monolog instance.
     * @param array $config
     * @return Logger
     */
    public function __invoke(array $config): \Monolog\Logger
    {
        return new \Monolog\Logger(
            config('app.name'),
            [
                new SitesMonkeyLoggerHandler,
            ]
        );
    }
}
