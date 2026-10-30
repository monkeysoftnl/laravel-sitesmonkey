<?php

namespace MonkeySoft\SitesMonkey;

use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use MonkeySoft\SitesMonkey\Commands\PushInventoryCommand;
use MonkeySoft\SitesMonkey\Commands\SitesMonkeyCommand;
use MonkeySoft\SitesMonkey\Inventory\InventoryCollector;
use MonkeySoft\SitesMonkey\Inventory\InventoryPusher;
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
            ->hasCommand(SitesMonkeyCommand::class)
            ->hasCommand(PushInventoryCommand::class);

        $events = $this->app->make(Dispatcher::class);
        $events->listen(Login::class,
            UserIsLoggedIn::class
        );
    }

    public function packageRegistered(): void
    {
        // One collector per run, so the command can show the warnings of the push it started.
        $this->app->singleton(InventoryCollector::class);
    }

    public function packageBooted(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! InventoryPusher::isConfigured()) {
                return;
            }

            // Without a configured schedule, every website gets its own fixed minute so SitesMonkey does not receive
            // all inventories at the same moment.
            $cron = config('sitesmonkey.inventory.schedule')
                ?: sprintf('%d * * * *', crc32((string) config('sitesmonkey.website_id')) % 60);

            $schedule->command(PushInventoryCommand::class)
                ->cron((string) $cron)
                ->name('sitesmonkey-inventory')
                ->withoutOverlapping(30)
                ->onOneServer()
                ->runInBackground();
        });
    }
}
