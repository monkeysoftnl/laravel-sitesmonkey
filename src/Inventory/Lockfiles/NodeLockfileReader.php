<?php

namespace MonkeySoft\SitesMonkey\Inventory\Lockfiles;

use JsonException;
use MonkeySoft\SitesMonkey\Exceptions\InventoryException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Production packages from a Node lockfile: package-lock.json, npm-shrinkwrap.json, yarn.lock (classic and berry)
 * or pnpm-lock.yaml. Only name and exact version are read; registry addresses and integrity hashes are ignored.
 *
 * pnpm and Yarn berry files are YAML, which needs symfony/yaml in the application. Yarn does not say which packages
 * are development-only, so it walks the dependencies of the package.json next to the lockfile.
 */
class NodeLockfileReader
{
    private const MAX_BYTES = 30 * 1024 * 1024;

    public const FILE_NAMES = ['package-lock.json', 'npm-shrinkwrap.json', 'yarn.lock', 'pnpm-lock.yaml'];

    /**
     * @return list<array{name: string, version: string, package_manager: string}>
     *
     * @throws InventoryException
     */
    public function read(string $path): array
    {
        $file = basename($path);

        if (! in_array($file, self::FILE_NAMES, true)) {
            throw new InventoryException('Unsupported Node lockfile: '.$file);
        }

        if (! is_file($path) || ! is_readable($path) || (int) filesize($path) > self::MAX_BYTES) {
            throw new InventoryException($file.' is missing, unreadable or too large.');
        }

        $contents = (string) file_get_contents($path);

        return match ($file) {
            'package-lock.json', 'npm-shrinkwrap.json' => $this->packageLock($contents),
            'pnpm-lock.yaml' => $this->pnpmLock($contents),
            default => $this->yarnLock($contents, $this->manifest(dirname($path))),
        };
    }

    /**
     * @return list<array{name: string, version: string, package_manager: string}>
     */
    private function packageLock(string $contents): array
    {
        $lock = $this->json($contents, 'package-lock.json');
        $found = [];

        if (is_array($lock['packages'] ?? null)) {
            foreach ($lock['packages'] as $path => $package) {
                if ($path === '' || ! is_array($package) || ($package['dev'] ?? false) === true || ($package['link'] ?? false) === true) {
                    continue;
                }

                $position = strrpos((string) $path, 'node_modules/');
                $name = is_string($package['name'] ?? null) ? $package['name'] : ($position === false ? null : substr((string) $path, $position + 13));

                if ($name !== null && is_string($package['version'] ?? null)) {
                    $found[] = [$name, $package['version']];
                }
            }
        } elseif (is_array($lock['dependencies'] ?? null)) {
            $this->collectLegacy($lock['dependencies'], $found);
        } else {
            throw new InventoryException('package-lock.json has no packages.');
        }

        return $this->withManager($found, 'npm');
    }

    /**
     * Lockfile version 1: nested "dependencies" with a version each.
     *
     * @param  array<array-key, mixed>  $dependencies
     * @param  list<array{string, string}>  $found
     */
    private function collectLegacy(array $dependencies, array &$found): void
    {
        foreach ($dependencies as $name => $package) {
            if (! is_array($package) || ($package['dev'] ?? false) === true) {
                continue;
            }

            if (is_string($package['version'] ?? null) && ! str_contains($package['version'], ':')) {
                $found[] = [(string) $name, $package['version']];
            }

            if (is_array($package['dependencies'] ?? null)) {
                $this->collectLegacy($package['dependencies'], $found);
            }
        }
    }

    /**
     * pnpm lockfile versions 6 to 9: everything reachable from the "dependencies" and "optionalDependencies" of
     * every importer.
     *
     * @return list<array{name: string, version: string, package_manager: string}>
     */
    private function pnpmLock(string $contents): array
    {
        $lock = $this->yaml($contents, 'pnpm-lock.yaml');

        if ((int) ($lock['lockfileVersion'] ?? 0) < 6) {
            throw new InventoryException('pnpm-lock.yaml lockfileVersion '.($lock['lockfileVersion'] ?? 'unknown').' is not supported (6 or newer is).');
        }

        $graph = is_array($lock['snapshots'] ?? null) ? $lock['snapshots'] : (is_array($lock['packages'] ?? null) ? $lock['packages'] : []);

        $queue = [];
        foreach (is_array($lock['importers'] ?? null) ? $lock['importers'] : ['.' => $lock] as $importer) {
            foreach (['dependencies', 'optionalDependencies'] as $section) {
                foreach (is_array($importer[$section] ?? null) ? $importer[$section] : [] as $name => $spec) {
                    $version = is_array($spec) ? ($spec['version'] ?? null) : $spec;

                    if (is_string($version) && ! str_starts_with($version, 'link:') && ! str_starts_with($version, 'file:')) {
                        $queue[] = [(string) $name, $version];
                    }
                }
            }
        }

        $seen = [];
        $found = [];
        while ($queue !== []) {
            [$name, $raw] = array_pop($queue);
            $version = preg_split('/[(_]/', $raw, 2)[0];

            if (isset($seen[$name.'@'.$version])) {
                continue;
            }

            $seen[$name.'@'.$version] = true;
            $found[] = [$name, $version];

            $node = [];
            foreach (['@', '/'] as $separator) {
                foreach (['', '/'] as $prefix) {
                    if (is_array($graph[$prefix.$name.$separator.$raw] ?? null)) {
                        $node = $graph[$prefix.$name.$separator.$raw];
                        break 2;
                    }
                }
            }

            foreach (['dependencies', 'optionalDependencies'] as $section) {
                foreach (is_array($node[$section] ?? null) ? $node[$section] : [] as $child => $childVersion) {
                    if (is_string($childVersion)) {
                        $queue[] = [(string) $child, $childVersion];
                    }
                }
            }
        }

        return $this->withManager($found, 'pnpm');
    }

    /**
     * @param  array<string, mixed>|null  $manifest  decoded package.json
     * @return list<array{name: string, version: string, package_manager: string}>
     */
    private function yarnLock(string $contents, ?array $manifest): array
    {
        if ($manifest === null) {
            throw new InventoryException('yarn.lock needs the package.json next to it to tell production packages from development packages.');
        }

        $entries = str_contains($contents, '__metadata:') ? $this->yarnBerry($contents) : $this->yarnClassic($contents);

        $queue = [];
        foreach (['dependencies', 'optionalDependencies'] as $section) {
            foreach (is_array($manifest[$section] ?? null) ? $manifest[$section] : [] as $name => $range) {
                $queue[] = $name.'@'.$range;
            }
        }

        $seen = [];
        $found = [];
        while ($queue !== []) {
            $descriptor = array_pop($queue);
            [$name, $range] = $this->splitDescriptor($descriptor);
            $entry = $entries[$name.'@'.$range] ?? $entries[$name.'@npm:'.$range] ?? null;

            if ($entry === null || isset($seen[$entry['name'].'@'.$entry['version']])) {
                continue;
            }

            $seen[$entry['name'].'@'.$entry['version']] = true;
            $found[] = [$entry['name'], $entry['version']];

            foreach ($entry['dependencies'] as $child => $childRange) {
                $queue[] = $child.'@'.$childRange;
            }
        }

        return $this->withManager($found, 'yarn');
    }

    /**
     * @return array<string, array{name: string, version: string, dependencies: array<string, string>}>
     */
    private function yarnClassic(string $contents): array
    {
        $blocks = [];
        $current = null;
        $inDependencies = false;

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if ($line[0] !== ' ') {
                if ($current !== null) {
                    $blocks[] = $current;
                }

                $current = [
                    'descriptors' => array_map(fn (string $part): string => trim($part, " \t\""), explode(',', rtrim($line, ':'))),
                    'version' => null,
                    'dependencies' => [],
                ];
                $inDependencies = false;

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^  version "?([^"]+)"?$/', $line, $match) === 1) {
                $current['version'] = $match[1];
                $inDependencies = false;
            } elseif (preg_match('/^  (dependencies|optionalDependencies):$/', $line) === 1) {
                $inDependencies = true;
            } elseif ($inDependencies && preg_match('/^    "?([^"\s]+)"? "?([^"]+)"?$/', $line, $match) === 1) {
                $current['dependencies'][$match[1]] = $match[2];
            } elseif (preg_match('/^  \S/', $line) === 1) {
                $inDependencies = false;
            }
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        $entries = [];
        foreach ($blocks as $block) {
            if ($block['version'] === null) {
                continue;
            }

            [$name] = $this->splitDescriptor($block['descriptors'][0]);
            foreach ($block['descriptors'] as $descriptor) {
                $entries[$descriptor] = ['name' => $name, 'version' => $block['version'], 'dependencies' => $block['dependencies']];
            }
        }

        if ($entries === []) {
            throw new InventoryException('yarn.lock contains no packages.');
        }

        return $entries;
    }

    /**
     * @return array<string, array{name: string, version: string, dependencies: array<string, string>}>
     */
    private function yarnBerry(string $contents): array
    {
        $entries = [];

        foreach ($this->yaml($contents, 'yarn.lock') as $key => $block) {
            if ($key === '__metadata' || ! is_array($block) || ! is_string($block['version'] ?? null)) {
                continue;
            }

            $resolution = is_string($block['resolution'] ?? null) ? $block['resolution'] : '';
            if (str_contains($resolution, '@workspace:') || ($block['linkType'] ?? '') === 'soft') {
                continue;
            }

            $descriptors = array_map('trim', explode(',', (string) $key));
            [$name] = $this->splitDescriptor($descriptors[0]);

            $dependencies = [];
            foreach (['dependencies', 'optionalDependencies'] as $section) {
                foreach (is_array($block[$section] ?? null) ? $block[$section] : [] as $child => $range) {
                    $dependencies[(string) $child] = (string) $range;
                }
            }

            foreach ($descriptors as $descriptor) {
                $entries[$descriptor] = ['name' => $name, 'version' => $block['version'], 'dependencies' => $dependencies];
            }
        }

        return $entries;
    }

    /**
     * @return array{string, string}
     */
    private function splitDescriptor(string $descriptor): array
    {
        $at = strrpos($descriptor, '@');

        return $at === false || $at === 0 ? [$descriptor, ''] : [substr($descriptor, 0, $at), substr($descriptor, $at + 1)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function manifest(string $directory): ?array
    {
        $path = $directory.'/package.json';

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(string $contents, string $file): array
    {
        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InventoryException($file.' is not valid JSON.');
        }

        if (! is_array($data)) {
            throw new InventoryException($file.' has an unexpected structure.');
        }

        return $data;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function yaml(string $contents, string $file): array
    {
        if (! class_exists(Yaml::class)) {
            throw new InventoryException($file.' is a YAML file: install symfony/yaml in the application to read it.');
        }

        try {
            $data = Yaml::parse($contents);
        } catch (ParseException) {
            throw new InventoryException($file.' is not valid YAML.');
        }

        if (! is_array($data)) {
            throw new InventoryException($file.' has an unexpected structure.');
        }

        return $data;
    }

    /**
     * @param  list<array{string, string}>  $found
     * @return list<array{name: string, version: string, package_manager: string}>
     */
    private function withManager(array $found, string $manager): array
    {
        return array_map(fn (array $entry): array => ['name' => $entry[0], 'version' => $entry[1], 'package_manager' => $manager], $found);
    }
}
