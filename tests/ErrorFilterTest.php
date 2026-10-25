<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MonkeySoft\SitesMonkey\Logging\SitesMonkeyLoggerHandler;
use Monolog\Level;
use Monolog\LogRecord;

beforeEach(function (): void {
    Cache::flush();
    Http::fake(['*' => Http::response(['success' => true])]);
    config()->set('sitesmonkey.enabled', true);
    config()->set('sitesmonkey.website_id', 'site-1');
    config()->set('sitesmonkey.website_secret', 'secret');
    config()->set('sitesmonkey.api_url', 'https://sitesmonkey.test');
    config()->set('sitesmonkey.minimal_log_level', 400);
});

function logRecord(Throwable $exception, string $message = 'Something broke'): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'laravel', Level::Error, $message, ['exception' => $exception]);
}

function handle(LogRecord $record): void
{
    (new SitesMonkeyLoggerHandler)->handle($record);
}

function asRequestTo(string $uri): void
{
    app()->instance('request', Request::create($uri, 'POST'));
}

it('sends an error', function (): void {
    handle(logRecord(new RuntimeException('boom')));

    Http::assertSentCount(1);
});

it('sends a record without an exception instead of failing silently', function (): void {
    handle(new LogRecord(new DateTimeImmutable, 'laravel', Level::Error, 'plain message'));

    Http::assertSent(fn ($request) => $request['error_data'] === '' && $request['error_message'] === 'plain message');
});

it('sends the same error only once within the throttle period', function (): void {
    handle(logRecord(new RuntimeException('boom')));
    handle(logRecord(new RuntimeException('boom')));
    handle(logRecord(new RuntimeException('another')));

    Http::assertSentCount(2);
});

it('sends every error when throttling is turned off', function (): void {
    config()->set('sitesmonkey.throttle_minutes', 0);

    handle(logRecord(new RuntimeException('boom')));
    handle(logRecord(new RuntimeException('boom')));

    Http::assertSentCount(2);
});

it('skips ignored exception classes and message patterns', function (): void {
    config()->set('sitesmonkey.ignore.exceptions', [DomainException::class]);
    config()->set('sitesmonkey.ignore.messages', ['/bot probe/i']);

    handle(logRecord(new DomainException('x')));
    handle(logRecord(new RuntimeException('Bot Probe on /wp-login')));

    Http::assertNothingSent();
});

it('skips a crash caused by a broken Livewire payload from a visitor who is not logged in', function (): void {
    asRequestTo('/livewire/update');

    handle(logRecord(new TypeError('Argument #1 ($notification) must be of type array, int given')));

    Http::assertNothingSent();
});

it('still sends a Livewire crash of a logged in user and the same crash elsewhere', function (): void {
    asRequestTo('/livewire/update');
    Auth::shouldReceive('check')->andReturn(true);
    handle(logRecord(new TypeError('first')));

    asRequestTo('/checkout');
    Auth::shouldReceive('check')->andReturn(false);
    handle(logRecord(new TypeError('second')));

    Http::assertSentCount(2);
});

it('can send Livewire payload crashes again when the option is turned off', function (): void {
    config()->set('sitesmonkey.ignore_guest_livewire_payload_errors', false);
    asRequestTo('/livewire/update');

    handle(logRecord(new TypeError('tampered')));

    Http::assertSentCount(1);
});
