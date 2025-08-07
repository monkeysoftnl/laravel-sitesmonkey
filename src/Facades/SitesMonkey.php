<?php

namespace MonkeySoft\SitesMonkey\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \MonkeySoft\SitesMonkey\SitesMonkey
 */
class SitesMonkey extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MonkeySoft\SitesMonkey\SitesMonkey::class;
    }
}
