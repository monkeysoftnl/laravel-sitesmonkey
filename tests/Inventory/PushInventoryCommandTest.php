<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/sm-cmd-'.uniqid();
    mkdir($this->base);
    copy(__DIR__.'/../fixtures/composer/composer.lock', $this->base.'/composer.lock');
    $this->app->setBasePath($this->base);

    Cache::flush();
    $this->status = 202;
    Http::fake(fn () => Http::response(['status' => 'x'], $this->status));

    config()->set('sitesmonkey.website_id', '0f8fad5b-d9cb-469f-a165-70867728950e');
    config()->set('sitesmonkey.api_url', 'https://sitesmonkey.test');
    config()->set('sitesmonkey.inventory.enabled', true);
    config()->set('sitesmonkey.inventory.key_id', 'smk_abcdefghijklmnopqrstuvwx');
    config()->set('sitesmonkey.inventory.key_secret', 'SecretSecretSecretSecretSecretSecretSecretSecret0123456789abcd');
});

afterEach(function (): void {
    @unlink($this->base.'/composer.lock');
    @rmdir($this->base);
});

it('sends the inventory and reports it', function (): void {
    $this->artisan('sitesmonkey:inventory:push')->expectsOutputToContain('Inventory sent')->assertExitCode(0);

    Http::assertSentCount(1);
});

it('shows what would be sent without sending it', function (): void {
    $this->artisan('sitesmonkey:inventory:push --dry-run')->expectsOutputToContain('laravel/framework')->assertExitCode(0);

    Http::assertNothingSent();
});

it('explains what is missing when it is not configured', function (): void {
    config()->set('sitesmonkey.inventory.key_id', '');

    $this->artisan('sitesmonkey:inventory:push')->expectsOutputToContain('SITESMONKEY_INVENTORY_KEY_ID')->assertExitCode(1);
});

it('fails when SitesMonkey rejects the inventory', function (): void {
    $this->status = 401;

    $this->artisan('sitesmonkey:inventory:push')->expectsOutputToContain('HTTP 401')->assertExitCode(1);
});

it('fails the dry run without leaking file contents when the packages cannot be read', function (): void {
    file_put_contents($this->base.'/composer.lock', '{"SECRET-VALUE": ');

    $this->artisan('sitesmonkey:inventory:push --dry-run')->doesntExpectOutputToContain('SECRET-VALUE')->assertExitCode(1);
});

describe('schedule', function (): void {
    function inventoryEvents(): array
    {
        app()->forgetInstance(Schedule::class);

        return array_values(array_filter(
            app(Schedule::class)->events(),
            fn ($event): bool => $event->description === 'sitesmonkey-inventory',
        ));
    }

    it('runs hourly at a fixed minute per website once configured', function (): void {
        $events = inventoryEvents();

        expect($events)->toHaveCount(1)
            ->and($events[0]->expression)->toBe(sprintf('%d * * * *', crc32('0f8fad5b-d9cb-469f-a165-70867728950e') % 60))
            ->and($events[0]->withoutOverlapping)->toBeTrue()
            ->and($events[0]->onOneServer)->toBeTrue();
    });

    it('uses the configured cron expression', function (): void {
        config()->set('sitesmonkey.inventory.schedule', '15 3 * * *');

        expect(inventoryEvents()[0]->expression)->toBe('15 3 * * *');
    });

    it('is not scheduled until the key is configured or when switched off', function (string $setting, mixed $value): void {
        config()->set($setting, $value);

        expect(inventoryEvents())->toBe([]);
    })->with([
        'no key' => ['sitesmonkey.inventory.key_secret', ''],
        'switched off' => ['sitesmonkey.inventory.enabled', false],
    ]);
});
