<?php

namespace MonkeySoft\SitesMonkey\Inventory\Lockfiles;

use Composer\InstalledVersions;
use JsonException;
use MonkeySoft\SitesMonkey\Exceptions\InventoryException;

/**
 * The production packages of the application: the "packages" list of composer.lock, never "packages-dev". Only the
 * name and version of each package are read.
 */
class ComposerLockReader
{
    private const MAX_BYTES = 30 * 1024 * 1024;

    /**
     * @return list<array{name: string, version: string}>
     *
     * @throws InventoryException
     */
    public function fromLockfile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path) || (int) filesize($path) > self::MAX_BYTES) {
            throw new InventoryException('composer.lock is missing, unreadable or too large.');
        }

        try {
            $lock = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InventoryException('composer.lock is not valid JSON.');
        }

        if (! is_array($lock) || ! is_array($lock['packages'] ?? null)) {
            throw new InventoryException('composer.lock has no packages list.');
        }

        $packages = [];
        foreach ($lock['packages'] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                $packages[] = ['name' => $package['name'], 'version' => $package['version']];
            }
        }

        return $packages;
    }

    /**
     * Fallback for deployments that do not ship composer.lock: what Composer itself recorded as installed, without
     * packages that were only installed as a development requirement.
     *
     * @return list<array{name: string, version: string}>
     *
     * @throws InventoryException
     */
    public function fromInstalledPackages(): array
    {
        if (! class_exists(InstalledVersions::class)) {
            throw new InventoryException('Composer installed-package data is not available.');
        }

        $root = InstalledVersions::getRootPackage()['name'];
        $packages = [];

        foreach (InstalledVersions::getInstalledPackages() as $name) {
            $version = InstalledVersions::getPrettyVersion($name);

            if ($name === $root || $version === null || ! InstalledVersions::isInstalled($name, false)) {
                continue;
            }

            $packages[] = ['name' => $name, 'version' => $version];
        }

        return $packages;
    }
}
