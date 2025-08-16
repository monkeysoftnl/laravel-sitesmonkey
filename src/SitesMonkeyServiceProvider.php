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
            ->hasConfigFile()
            ->hasRoute('api')
            ->hasCommand(SitesMonkeyCommand::class);

        $events = $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class);
        $events->listen(\Illuminate\Auth\Events\Login::class,
            \MonkeySoft\SitesMonkey\Listeners\UserIsLoggedIn::class
        );
    }
}
