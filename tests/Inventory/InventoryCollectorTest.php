<?php

use Illuminate\Support\Facades\Cache;
use MonkeySoft\SitesMonkey\Exceptions\InventoryException;
use MonkeySoft\SitesMonkey\Inventory\InventoryCollector;

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/sm-app-'.uniqid();
    mkdir($this->base.'/app', 0777, true);
    copy(__DIR__.'/../fixtures/composer/composer.lock', $this->base.'/composer.lock');

    $this->app->setBasePath($this->base);

    config()->set('sitesmonkey.website_id', 'site-1');
    config()->set('sitesmonkey.inventory.node_lockfiles', []);
    config()->set('sitesmonkey.inventory.php_extensions', ['json', 'pcre']);
    Cache::flush();
});

afterEach(function (): void {
    $remove = function (string $path) use (&$remove): void {
        foreach (glob($path.'/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (in_array(basename($entry), ['.', '..'], true)) {
                continue;
            }
            is_dir($entry) ? $remove($entry) : unlink($entry);
        }
        rmdir($path);
    };
    $remove($this->base);
});

it('reports laravel and php versions with the production composer packages', function (): void {
    $payload = (new InventoryCollector)->collect();

    expect($payload)->toMatchArray([
        'schema_version' => 1,
        'type' => 'laravel',
        'site_id' => 'site-1',
        'php_version' => PHP_VERSION,
        'laravel_version' => app()->version(),
        'lockfile_hash' => hash_file('sha256', $this->base.'/composer.lock'),
    ])->and($payload['composer_packages'])->toBe([
        ['name' => 'laravel/framework', 'version' => 'v11.9.0'],
        ['name' => 'Monolog/Monolog', 'version' => '3.5.0'],
        ['name' => 'vendor/branch-pinned', 'version' => 'dev-main'],
    ]);
});

it('only contains allowlisted fields and none of the applications secrets', function (): void {
    file_put_contents($this->base.'/.env', "APP_KEY=base64:SECRET-ENV-VALUE\nDB_PASSWORD=hunter2-database\n");
    file_put_contents($this->base.'/app/Secret.php', '<?php // SOURCE-CODE-MARKER');
    config()->set('app.key', 'base64:SECRET-APP-KEY');
    config()->set('database.connections.testing.password', 'hunter2-database');
    config()->set('sitesmonkey.website_secret', 'website-secret-value');
    config()->set('sitesmonkey.inventory.key_secret', 'inventory-secret-value');

    $payload = (new InventoryCollector)->collect();
    $json = json_encode($payload);

    expect(array_keys($payload))->toEqualCanonicalizing(['schema_version', 'type', 'collected_at', 'php_version', 'laravel_version', 'composer_packages', 'php_extensions', 'site_id', 'lockfile_hash'])
        ->and(array_keys($payload['composer_packages'][0]))->toBe(['name', 'version'])
        ->and($json)->not->toContain('SECRET-ENV-VALUE')->not->toContain('SECRET-APP-KEY')->not->toContain('hunter2')
        ->not->toContain('website-secret-value')->not->toContain('inventory-secret-value')->not->toContain('SOURCE-CODE-MARKER')
        ->not->toContain('github.com');
});

it('reports only the configured php extensions', function (): void {
    $names = array_column((new InventoryCollector)->collect()['php_extensions'], 'name');

    expect($names)->toBe(['json', 'pcre']);
});

it('leaves out the site id until one is configured', function (): void {
    config()->set('sitesmonkey.website_id', '');

    expect((new InventoryCollector)->collect())->not->toHaveKey('site_id');
});

it('includes the time of the last successful push once there was one', function (): void {
    expect((new InventoryCollector)->collect())->not->toHaveKey('last_successful_inventory_at');

    Cache::forever(InventoryCollector::CACHE_LAST_SUCCESS, time() - 3600);

    expect((new InventoryCollector)->collect())->toHaveKey('last_successful_inventory_at');
});

it('makes odd versions fit SitesMonkey and skips package names it would reject', function (): void {
    file_put_contents($this->base.'/composer.lock', json_encode(['packages' => [
        ['name' => 'vendor/spaced', 'version' => '1.0 beta 2'],
        ['name' => 'vendor/unknown', 'version' => '???'],
        ['name' => 'bad name with spaces', 'version' => '1.0'],
    ]]));

    expect((new InventoryCollector)->collect()['composer_packages'])->toBe([
        ['name' => 'vendor/spaced', 'version' => '1.0-beta-2'],
        ['name' => 'vendor/unknown', 'version' => 'unknown'],
    ]);
});

describe('composer packages', function (): void {
    it('refuses to report when composer.lock is not valid, instead of reporting an empty list', function (): void {
        file_put_contents($this->base.'/composer.lock', '{not json');

        (new InventoryCollector)->collect();
    })->throws(InventoryException::class);

    it('falls back to the installed packages when there is no composer.lock', function (): void {
        unlink($this->base.'/composer.lock');
        $collector = new InventoryCollector;

        $payload = $collector->collect();

        expect(array_column($payload['composer_packages'], 'name'))->toContain('spatie/laravel-package-tools')
            ->and($payload)->not->toHaveKey('lockfile_hash')
            ->and($collector->warnings[0])->toContain('composer.lock was not found');
    });
});

describe('node packages', function (): void {
    it('reports nothing about node unless lockfiles are configured', function (): void {
        expect((new InventoryCollector)->collect())->not->toHaveKey('node_packages');
    });

    it('reports the production packages of a configured lockfile', function (): void {
        copy(__DIR__.'/../fixtures/node/npm-v3/package-lock.json', $this->base.'/package-lock.json');
        config()->set('sitesmonkey.inventory.node_lockfiles', ['package-lock.json']);

        $packages = (new InventoryCollector)->collect()['node_packages'];

        expect(array_column($packages, 'version', 'name'))->toBe(['@scope/pkg' => '1.0.0', 'bar' => '2.0.0', 'lodash' => '4.17.20', 'real-name' => '3.1.0'])
            ->and(array_unique(array_column($packages, 'package_manager')))->toBe(['npm']);
    });

    it('leaves node out completely when a configured lockfile cannot be read, so earlier results stay', function (): void {
        copy(__DIR__.'/../fixtures/node/npm-v3/package-lock.json', $this->base.'/package-lock.json');
        copy(__DIR__.'/../fixtures/node/yarn-classic/yarn.lock', $this->base.'/yarn.lock');
        config()->set('sitesmonkey.inventory.node_lockfiles', ['package-lock.json', 'yarn.lock']);
        $collector = new InventoryCollector;

        $payload = $collector->collect();

        expect($payload)->not->toHaveKey('node_packages')
            ->and($collector->warnings[0])->toContain('package.json');
    });

    it('does not read lockfiles outside the application', function (): void {
        $outside = dirname($this->base).'/sm-outside-'.uniqid();
        mkdir($outside);
        copy(__DIR__.'/../fixtures/node/npm-v3/package-lock.json', $outside.'/package-lock.json');
        config()->set('sitesmonkey.inventory.node_lockfiles', ['../'.basename($outside).'/package-lock.json']);
        $collector = new InventoryCollector;

        $payload = $collector->collect();
        unlink($outside.'/package-lock.json');
        rmdir($outside);

        expect($payload)->not->toHaveKey('node_packages')
            ->and($collector->warnings[0])->toContain('not found inside the application');
    });

    it('does not accept a file that is not a lockfile', function (): void {
        file_put_contents($this->base.'/.env', 'APP_KEY=base64:SECRET-ENV-VALUE');
        config()->set('sitesmonkey.inventory.node_lockfiles', ['.env']);
        $collector = new InventoryCollector;

        $payload = $collector->collect();

        expect($payload)->not->toHaveKey('node_packages')
            ->and(json_encode([$payload, $collector->warnings]))->not->toContain('SECRET-ENV-VALUE');
    });
});
