<?php

use MonkeySoft\SitesMonkey\Exceptions\InventoryException;
use MonkeySoft\SitesMonkey\Inventory\Lockfiles\ComposerLockReader;

it('reads production packages from composer.lock and skips dev packages', function (): void {
    $packages = (new ComposerLockReader)->fromLockfile(__DIR__.'/../fixtures/composer/composer.lock');

    expect(array_column($packages, 'version', 'name'))->toBe([
        'laravel/framework' => 'v11.9.0',
        'Monolog/Monolog' => '3.5.0',
        'vendor/branch-pinned' => 'dev-main',
    ]);
});

it('only returns name and version of a package, never its source', function (): void {
    $package = (new ComposerLockReader)->fromLockfile(__DIR__.'/../fixtures/composer/composer.lock')[0];

    expect(array_keys($package))->toBe(['name', 'version']);
});

it('fails when composer.lock is missing, invalid or has no packages', function (string $contents): void {
    $path = tempnam(sys_get_temp_dir(), 'lock');
    file_put_contents($path, $contents);

    try {
        (new ComposerLockReader)->fromLockfile($path);
        $this->fail('Expected an InventoryException.');
    } catch (InventoryException $exception) {
        expect($exception->getMessage())->not->toContain('SECRET-VALUE');
    } finally {
        unlink($path);
    }
})->with([
    'invalid json' => ['{"SECRET-VALUE": '],
    'no packages list' => ['{"SECRET-VALUE": []}'],
    'not an object' => ['"SECRET-VALUE"'],
]);

it('fails for a path that does not exist', function (): void {
    (new ComposerLockReader)->fromLockfile('/nonexistent/composer.lock');
})->throws(InventoryException::class, 'missing');

it('falls back to the installed packages without dev requirements or the root package', function (): void {
    $names = array_column((new ComposerLockReader)->fromInstalledPackages(), 'name');

    expect($names)->toContain('spatie/laravel-package-tools')
        ->not->toContain('pestphp/pest')
        ->not->toContain('monkeysoft/laravel-sitesmonkey');
});
