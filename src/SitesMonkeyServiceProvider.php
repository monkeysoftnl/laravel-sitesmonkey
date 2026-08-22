<?php

namespace MonkeySoft\SitesMonkey;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Events\Dispatcher;
use MonkeySoft\SitesMonkey\Commands\SitesMonkeyCommand;
use MonkeySoft\SitesMonkey\Listeners\UserIsLoggedIn;
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

        $events = $this->app->make(Dispatcher::class);
        $events->listen(Login::class,
            UserIsLoggedIn::class
        );
    }
}
