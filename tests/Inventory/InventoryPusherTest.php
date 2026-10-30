<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MonkeySoft\SitesMonkey\Inventory\InventoryCollector;
use MonkeySoft\SitesMonkey\Inventory\InventoryPusher;

const PUSHER_KEY_ID = 'smk_abcdefghijklmnopqrstuvwx';
const PUSHER_SECRET = 'SecretSecretSecretSecretSecretSecretSecretSecret0123456789abcd';

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/sm-push-'.uniqid();
    mkdir($this->base);
    copy(__DIR__.'/../fixtures/composer/composer.lock', $this->base.'/composer.lock');
    $this->app->setBasePath($this->base);

    Cache::flush();
    // The status is read per request, so a test can change it ($this->status = 0 simulates a connection failure).
    $this->status = 202;
    Http::fake(fn () => $this->status === 0
        ? throw new ConnectionException('cURL error 28: timeout')
        : Http::response(['status' => 'x'], $this->status));

    config()->set('sitesmonkey.enabled', true);
    config()->set('sitesmonkey.website_id', '0f8fad5b-d9cb-469f-a165-70867728950e');
    config()->set('sitesmonkey.api_url', 'https://sitesmonkey.test');
    config()->set('sitesmonkey.inventory.enabled', true);
    config()->set('sitesmonkey.inventory.key_id', PUSHER_KEY_ID);
    config()->set('sitesmonkey.inventory.key_secret', PUSHER_SECRET);
    config()->set('sitesmonkey.inventory.node_lockfiles', []);
});

afterEach(function (): void {
    @unlink($this->base.'/composer.lock');
    @rmdir($this->base);
});

function pusher(): InventoryPusher
{
    return app(InventoryPusher::class);
}

it('posts the inventory with a signature over the exact body', function (): void {
    $result = pusher()->push();

    Http::assertSentCount(1);
    $request = Http::recorded()[0][0];
    $canonical = implode("\n", [
        'POST',
        '/api/v1/sites/0f8fad5b-d9cb-469f-a165-70867728950e/inventory',
        '',
        hash('sha256', $request->body()),
        $request->header('X-SitesMonkey-Timestamp')[0],
        $request->header('X-SitesMonkey-Nonce')[0],
    ]);

    expect($result)->toBe(['success' => true, 'reason' => 'sent', 'http_status' => 202])
        ->and($request->url())->toBe('https://sitesmonkey.test/api/v1/sites/0f8fad5b-d9cb-469f-a165-70867728950e/inventory')
        ->and($request->method())->toBe('POST')
        ->and($request->header('X-SitesMonkey-Key-Id')[0])->toBe(PUSHER_KEY_ID)
        ->and($request->header('X-SitesMonkey-Signature')[0])->toBe('sha256='.hash_hmac('sha256', $canonical, PUSHER_SECRET))
        ->and(abs((int) $request->header('X-SitesMonkey-Timestamp')[0] - time()))->toBeLessThan(5)
        ->and(json_decode($request->body(), true)['type'])->toBe('laravel');
});

it('uses a fresh nonce for every push', function (): void {
    pusher()->push();
    pusher()->push();

    $nonces = array_map(fn (array $pair): string => $pair[0]->header('X-SitesMonkey-Nonce')[0], Http::recorded()->all());

    expect($nonces)->toHaveCount(2)->and($nonces[0])->not->toBe($nonces[1]);
});

it('never sends the secret itself', function (): void {
    pusher()->push();

    $request = Http::recorded()[0][0];

    expect($request->body())->not->toContain(PUSHER_SECRET)
        ->and(json_encode($request->headers()))->not->toContain(PUSHER_SECRET);
});

it('remembers the last success and sends it with the next inventory', function (): void {
    pusher()->push();
    pusher()->push();

    [$first, $second] = array_map(fn (array $pair): array => json_decode($pair[0]->body(), true), Http::recorded()->all());

    expect(Cache::get(InventoryCollector::CACHE_LAST_SUCCESS))->toBeInt()
        ->and($first)->not->toHaveKey('last_successful_inventory_at')
        ->and($second)->toHaveKey('last_successful_inventory_at');
});

it('does not record a success when SitesMonkey rejects the inventory', function (int $status): void {
    $this->status = $status;

    $result = pusher()->push();

    expect($result)->toBe(['success' => false, 'reason' => 'rejected', 'http_status' => $status])
        ->and(Cache::has(InventoryCollector::CACHE_LAST_SUCCESS))->toBeFalse()
        ->and(Cache::get(InventoryPusher::CACHE_LAST_RESULT))->toBe('rejected:'.$status);
})->with([401, 409, 422, 429, 500]);

it('reports a connection problem without recording a success', function (): void {
    $this->status = 0;

    $result = pusher()->push();

    expect($result)->toBe(['success' => false, 'reason' => 'transport_error', 'http_status' => null])
        ->and(Cache::has(InventoryCollector::CACHE_LAST_SUCCESS))->toBeFalse()
        ->and(Cache::get(InventoryPusher::CACHE_LAST_RESULT))->not->toContain('timeout');
});

it('sends nothing when the packages cannot be determined', function (): void {
    file_put_contents($this->base.'/composer.lock', '{not json');

    expect(pusher()->push()['reason'])->toBe('collection_failed');

    Http::assertNothingSent();
});

it('sends nothing when it is not configured', function (string $setting): void {
    config()->set($setting, $setting === 'sitesmonkey.inventory.enabled' ? false : '');

    expect(pusher()->push()['reason'])->toBe('not_configured');

    Http::assertNothingSent();
})->with([
    'switched off' => 'sitesmonkey.inventory.enabled',
    'no website id' => 'sitesmonkey.website_id',
    'no key id' => 'sitesmonkey.inventory.key_id',
    'no key secret' => 'sitesmonkey.inventory.key_secret',
]);

it('refuses a website id that could change the url path', function (): void {
    config()->set('sitesmonkey.website_id', '../../admin');

    expect(pusher()->push()['reason'])->toBe('not_configured');

    Http::assertNothingSent();
});

it('does not start a second push while one is running', function (): void {
    $lock = Cache::lock('sitesmonkey:inventory:push', 60);
    $lock->get();

    expect(pusher()->push()['reason'])->toBe('locked');

    Http::assertNothingSent();
    $lock->release();
});

it('releases the lock after a push', function (): void {
    pusher()->push();

    expect(pusher()->push()['reason'])->toBe('sent');
});
