<?php

use Illuminate\Support\Facades\Cache;
use MonkeySoft\SitesMonkey\Inventory\InventoryCollector;

/**
 * The structure of a value: field names and types, with lists described by their first element.
 *
 * @return array<string, mixed>|string
 */
function payloadShape(mixed $value): array|string
{
    if (! is_array($value)) {
        return get_debug_type($value);
    }

    if (array_is_list($value)) {
        return $value === [] ? ['list'] : ['list', payloadShape($value[0])];
    }

    ksort($value);

    return array_map('payloadShape', $value);
}

/*
 * SitesMonkey's inventory API only accepts fields it knows. The fixture is the payload the backend tests post to the
 * real endpoint (tests/Feature/Vulnerabilities/LaravelPackageContractTest.php in SitesMonkey), so this test keeps the
 * two sides in step: change what the package sends and the fixture, and with it the backend test, has to change too.
 */
it('sends exactly the fields of the shared contract fixture', function (): void {
    $base = sys_get_temp_dir().'/sm-contract-'.uniqid();
    mkdir($base);
    copy(__DIR__.'/../fixtures/composer/composer.lock', $base.'/composer.lock');
    copy(__DIR__.'/../fixtures/node/npm-v3/package-lock.json', $base.'/package-lock.json');
    $this->app->setBasePath($base);

    config()->set('sitesmonkey.website_id', '0f8fad5b-d9cb-469f-a165-70867728950e');
    config()->set('sitesmonkey.inventory.php_extensions', ['json']);
    config()->set('sitesmonkey.inventory.node_lockfiles', ['package-lock.json']);
    Cache::forever(InventoryCollector::CACHE_LAST_SUCCESS, time());

    $payload = (new InventoryCollector)->collect();
    unlink($base.'/composer.lock');
    unlink($base.'/package-lock.json');
    rmdir($base);

    $fixture = json_decode((string) file_get_contents(__DIR__.'/../fixtures/contract/laravel-inventory-payload.json'), true);

    expect(payloadShape($payload))->toBe(payloadShape($fixture));
});
