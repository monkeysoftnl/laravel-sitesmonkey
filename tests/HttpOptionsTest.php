<?php

use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MonkeySoft\SitesMonkey\Logging\SitesMonkeyLoggerHandler;
use Monolog\Level;
use Monolog\LogRecord;

beforeEach(function (): void {
    config()->set('sitesmonkey.website_id', 'site-1');
    config()->set('sitesmonkey.website_secret', 'the-website-secret');
    config()->set('sitesmonkey.throttle_minutes', 0);

    Http::fake();
    Http::globalOptions(['headers' => ['X-App' => 'own-option']]);
});

function assertAppHttpOptionsKept(): void
{
    Http::get('https://example.com/app-request');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://example.com/app-request'
        && $request->hasHeader('X-App', 'own-option'));
}

it('keeps the global HTTP options of the application when an error is sent', function (): void {
    (new SitesMonkeyLoggerHandler)->handle(new LogRecord(new DateTimeImmutable, 'testing', Level::Error, 'Something broke'));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/v1/create-error-message'));
    assertAppHttpOptionsKept();
});

it('keeps the global HTTP options of the application when a login is sent', function (): void {
    $user = new User;
    $user->id = 1;
    $user->email = 'admin@example.com';

    event(new Login('web', $user, false));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/v1/successful-login'));
    assertAppHttpOptionsKept();
});
