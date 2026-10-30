<?php

use MonkeySoft\SitesMonkey\Exceptions\InventoryException;
use MonkeySoft\SitesMonkey\Inventory\Lockfiles\NodeLockfileReader;

/**
 * @param  list<array{name: string, version: string, package_manager: string}>  $packages
 * @return list<string>
 */
function nodeList(array $packages): array
{
    $list = array_map(fn (array $package): string => $package['name'].'@'.$package['version'], $packages);
    sort($list);

    return $list;
}

it('reads package-lock.json v3 without dev, link and root entries', function (): void {
    $packages = (new NodeLockfileReader)->read(__DIR__.'/../fixtures/node/npm-v3/package-lock.json');

    expect(nodeList($packages))->toBe(['@scope/pkg@1.0.0', 'bar@2.0.0', 'lodash@4.17.20', 'real-name@3.1.0'])
        ->and($packages[0]['package_manager'])->toBe('npm');
});

it('reads package-lock.json v1 including nested dependencies', function (): void {
    expect(nodeList((new NodeLockfileReader)->read(__DIR__.'/../fixtures/node/npm-v1/package-lock.json')))->toBe(['accepts@1.3.7', 'express@4.17.1']);
});

it('walks yarn classic dependencies from the package.json and skips dev dependencies', function (): void {
    $packages = (new NodeLockfileReader)->read(__DIR__.'/../fixtures/node/yarn-classic/yarn.lock');

    expect(nodeList($packages))->toBe(['@scope/util@1.1.2', 'accepts@1.3.8', 'express@4.18.2', 'mime-types@2.1.35'])
        ->and($packages[0]['package_manager'])->toBe('yarn');
});

it('walks yarn berry dependencies and skips workspaces', function (): void {
    expect(nodeList((new NodeLockfileReader)->read(__DIR__.'/../fixtures/node/yarn-berry/yarn.lock')))->toBe(['accepts@1.3.8', 'express@4.18.2']);
});

it('reads only production packages reachable from the pnpm importers', function (): void {
    expect(nodeList((new NodeLockfileReader)->read(__DIR__.'/../fixtures/node/pnpm/pnpm-lock.yaml')))
        ->toBe(['accepts@1.3.8', 'express@4.18.2', 'react-dom@18.2.0', 'react@18.2.0']);
});

it('refuses old pnpm lockfile versions instead of guessing', function (): void {
    (new NodeLockfileReader)->read(__DIR__.'/../fixtures/node/pnpm-old/pnpm-lock.yaml');
})->throws(InventoryException::class, 'not supported');

it('refuses yarn.lock without a package.json next to it', function (): void {
    $directory = sys_get_temp_dir().'/yarn-only-'.uniqid();
    mkdir($directory);
    copy(__DIR__.'/../fixtures/node/yarn-classic/yarn.lock', $directory.'/yarn.lock');

    try {
        (new NodeLockfileReader)->read($directory.'/yarn.lock');
    } finally {
        unlink($directory.'/yarn.lock');
        rmdir($directory);
    }
})->throws(InventoryException::class, 'package.json');

it('refuses files that are not a known lockfile name', function (): void {
    (new NodeLockfileReader)->read(__DIR__.'/../fixtures/composer/composer.lock');
})->throws(InventoryException::class, 'Unsupported');

it('does not echo file contents in errors', function (): void {
    $directory = sys_get_temp_dir().'/lock-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.'/package-lock.json', '{"SECRET-VALUE": ');

    try {
        (new NodeLockfileReader)->read($directory.'/package-lock.json');
        $this->fail('Expected an InventoryException.');
    } catch (InventoryException $exception) {
        expect($exception->getMessage())->not->toContain('SECRET-VALUE');
    } finally {
        unlink($directory.'/package-lock.json');
        rmdir($directory);
    }
});
