<?php

namespace MonkeySoft\SitesMonkey\Logging;

class SitesMonkeyLogger
{
    /**
     * Create a custom Monolog instance.
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
