<?php

namespace MonkeySoft\SitesMonkey\Inventory;

use Illuminate\Support\Facades\Cache;
use MonkeySoft\SitesMonkey\Exceptions\InventoryException;
use MonkeySoft\SitesMonkey\Inventory\Lockfiles\ComposerLockReader;
use MonkeySoft\SitesMonkey\Inventory\Lockfiles\NodeLockfileReader;

/**
 * Collects what SitesMonkey needs to find vulnerable software: Laravel and PHP version, the production packages of
 * composer.lock, a short allowlist of PHP extensions and, when configured, the production packages of Node lockfiles.
 *
 * The payload is built field by field. Nothing else is read or sent: no .env, configuration values, credentials,
 * source code or database contents. SitesMonkey rejects fields it does not know, so a new field has to be added on
 * both sides deliberately.
 */
class InventoryCollector
{
    public const SCHEMA_VERSION = 1;

    public const CACHE_LAST_SUCCESS = 'sitesmonkey:inventory:last_success';

    private const MAX_PACKAGES = 5000;

    private const PACKAGE_NAME_PATTERN = '/^[A-Za-z0-9@][A-Za-z0-9._\/@-]{0,213}$/';

    private const SLUG_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/';

    /**
     * Problems that did not stop the inventory but left something out, for the console. Never sent.
     *
     * @var list<string>
     */
    public array $warnings = [];

    public function __construct(
        private readonly ComposerLockReader $composer = new ComposerLockReader,
        private readonly NodeLockfileReader $node = new NodeLockfileReader,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws InventoryException when the Composer packages cannot be determined: an empty list would look like
     *                            "everything was removed" to SitesMonkey
     */
    public function collect(): array
    {
        $this->warnings = [];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'type' => 'laravel',
            'collected_at' => gmdate('c'),
            'php_version' => $this->cleanVersion(PHP_VERSION),
            'laravel_version' => $this->cleanVersion(app()->version()),
            'composer_packages' => $this->composerPackages(),
            'php_extensions' => $this->phpExtensions(),
        ];

        $siteId = (string) config('sitesmonkey.website_id');
        if ($siteId !== '') {
            $payload['site_id'] = $siteId;
        }

        $lockfile = base_path('composer.lock');
        if (is_file($lockfile) && ($hash = hash_file('sha256', $lockfile)) !== false) {
            $payload['lockfile_hash'] = $hash;
        }

        $nodePackages = $this->nodePackages();
        if ($nodePackages !== null) {
            $payload['node_packages'] = $nodePackages;
        }

        $lastSuccess = (int) Cache::get(self::CACHE_LAST_SUCCESS, 0);
        if ($lastSuccess > 0) {
            $payload['last_successful_inventory_at'] = gmdate('c', $lastSuccess);
        }

        return $payload;
    }

    /**
     * @return list<array{name: string, version: string}>
     */
    private function composerPackages(): array
    {
        $lockfile = base_path('composer.lock');

        if (is_file($lockfile)) {
            $raw = $this->composer->fromLockfile($lockfile);
        } else {
            $this->warnings[] = 'composer.lock was not found; the packages Composer recorded as installed are used instead.';
            $raw = $this->composer->fromInstalledPackages();
        }

        $packages = [];
        foreach ($raw as $package) {
            if (! preg_match(self::PACKAGE_NAME_PATTERN, $package['name'])) {
                continue;
            }

            $version = $this->cleanVersion($package['version']);
            $packages[strtolower($package['name']).'|'.$version] = ['name' => $package['name'], 'version' => $version];
        }

        ksort($packages);

        return array_slice(array_values($packages), 0, self::MAX_PACKAGES);
    }

    /**
     * Only extensions on the configured allowlist, with the name made lowercase and url-safe.
     *
     * @return list<array{name: string, version: string}>
     */
    private function phpExtensions(): array
    {
        $allowed = array_map(fn (mixed $name): string => $this->slug((string) $name), (array) config('sitesmonkey.inventory.php_extensions', []));
        $extensions = [];

        foreach (get_loaded_extensions() as $extension) {
            $slug = $this->slug($extension);

            if (! in_array($slug, $allowed, true) || ! preg_match(self::SLUG_PATTERN, $slug)) {
                continue;
            }

            $version = phpversion($extension);
            $extensions[$slug] = ['name' => $slug, 'version' => $this->cleanVersion($version === false ? PHP_VERSION : $version)];
        }

        ksort($extensions);

        return array_values($extensions);
    }

    /**
     * Node packages of the configured lockfiles, or null when nothing is configured or any file could not be read.
     * Null leaves the field out, which tells SitesMonkey nothing about Node: earlier results stay as they are.
     *
     * @return list<array{name: string, version: string, package_manager: string}>|null
     */
    private function nodePackages(): ?array
    {
        $configured = array_filter((array) config('sitesmonkey.inventory.node_lockfiles', []), 'is_string');

        if ($configured === []) {
            return null;
        }

        $root = realpath(base_path());
        $packages = [];

        foreach ($configured as $relative) {
            $path = realpath(base_path($relative));

            if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                $this->warnings[] = 'Node lockfile "'.$relative.'" was not found inside the application; Node packages are not reported.';

                return null;
            }

            try {
                foreach ($this->node->read($path) as $package) {
                    if (! preg_match(self::PACKAGE_NAME_PATTERN, $package['name'])) {
                        continue;
                    }

                    $version = $this->cleanVersion($package['version']);
                    $packages[$package['package_manager'].'|'.strtolower($package['name']).'|'.$version] = [
                        'name' => $package['name'],
                        'version' => $version,
                        'package_manager' => $package['package_manager'],
                    ];
                }
            } catch (InventoryException $exception) {
                $this->warnings[] = $exception->getMessage().' Node packages are not reported.';

                return null;
            }
        }

        ksort($packages);

        return array_slice(array_values($packages), 0, self::MAX_PACKAGES);
    }

    private function slug(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9._-]+/', '-', strtolower($name)), '-');
    }

    /**
     * Versions must fit SitesMonkey's pattern. One that cannot be expressed is reported as "unknown" instead of
     * being left out, so the package is still listed and a match is flagged for review.
     */
    private function cleanVersion(string $version): string
    {
        $version = (string) preg_replace('/[^0-9A-Za-z.+~_-]/', '', (string) preg_replace('/\s+/', '-', trim($version)));
        $version = substr(ltrim($version, '.+~_-'), 0, 64);

        return $version === '' ? 'unknown' : $version;
    }
}
