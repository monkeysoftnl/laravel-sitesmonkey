<?php

namespace MonkeySoft\SitesMonkey\Inventory;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MonkeySoft\SitesMonkey\Exceptions\InventoryException;

/**
 * Sends the inventory to SitesMonkey as a signed request: HMAC-SHA256 over method, path, query, body hash,
 * timestamp and a single-use nonce, made with the key of this website. The request never follows redirects, so a
 * signed body cannot be forwarded to another address.
 */
class InventoryPusher
{
    public const CACHE_LAST_RESULT = 'sitesmonkey:inventory:last_result';

    private const LOCK = 'sitesmonkey:inventory:push';

    public function __construct(private readonly InventoryCollector $collector) {}

    public static function isConfigured(): bool
    {
        return config('sitesmonkey.inventory.enabled') === true
            && (string) config('sitesmonkey.website_id') !== ''
            && (string) config('sitesmonkey.inventory.key_id') !== ''
            && (string) config('sitesmonkey.inventory.key_secret') !== '';
    }

    /**
     * @return array{success: bool, reason: string, http_status: int|null}
     */
    public function push(): array
    {
        if (! self::isConfigured() || ! preg_match('/^[A-Za-z0-9-]{1,64}$/', (string) config('sitesmonkey.website_id'))) {
            return $this->finish(false, 'not_configured');
        }

        $store = Cache::getStore();
        $lock = $store instanceof LockProvider ? Cache::lock(self::LOCK, 300) : null;

        if ($lock !== null && ! $lock->get()) {
            return ['success' => false, 'reason' => 'locked', 'http_status' => null];
        }

        try {
            return $this->send();
        } finally {
            $lock?->release();
        }
    }

    /**
     * @return array{success: bool, reason: string, http_status: int|null}
     */
    private function send(): array
    {
        try {
            $body = (string) json_encode($this->collector->collect(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (InventoryException) {
            return $this->finish(false, 'collection_failed');
        }

        $siteId = (string) config('sitesmonkey.website_id');
        $url = rtrim((string) config('sitesmonkey.api_url'), '/').'/api/v1/sites/'.rawurlencode($siteId).'/inventory';
        $path = (string) parse_url($url, PHP_URL_PATH);

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = implode("\n", ['POST', $path, '', hash('sha256', $body), $timestamp, $nonce]);

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-SitesMonkey-Key-Id' => (string) config('sitesmonkey.inventory.key_id'),
                'X-SitesMonkey-Timestamp' => $timestamp,
                'X-SitesMonkey-Nonce' => $nonce,
                'X-SitesMonkey-Signature' => 'sha256='.hash_hmac('sha256', $canonical, (string) config('sitesmonkey.inventory.key_secret')),
            ])
                ->withBody($body, 'application/json')
                ->connectTimeout(10)
                ->timeout(20)
                ->withoutRedirecting()
                ->post($url);
        } catch (ConnectionException) {
            return $this->finish(false, 'transport_error');
        }

        if ($response->successful()) {
            Cache::forever(InventoryCollector::CACHE_LAST_SUCCESS, time());

            return $this->finish(true, 'sent', $response->status());
        }

        return $this->finish(false, 'rejected', $response->status());
    }

    /**
     * @return array{success: bool, reason: string, http_status: int|null}
     */
    private function finish(bool $success, string $reason, ?int $status = null): array
    {
        Cache::forever(self::CACHE_LAST_RESULT, $status === null ? $reason : $reason.':'.$status);

        return ['success' => $success, 'reason' => $reason, 'http_status' => $status];
    }
}
