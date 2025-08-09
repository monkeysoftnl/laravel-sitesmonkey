<?php

namespace MonkeySoft\SitesMonkey;

use MonkeySoft\SitesMonkey\Commands\SitesMonkeyCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SitesMonkeyServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-sitesmonkey')
            ->hasConfigFile('sitesmonkey')
            ->hasRoute('api')
            ->hasCommand(SitesMonkeyCommand::class);
    }
}
