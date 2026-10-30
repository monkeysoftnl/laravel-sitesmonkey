# Laravel Package for SitesMonkey Monitoring

[![Latest Version on Packagist](https://img.shields.io/packagist/v/monkeysoft/laravel-sitesmonkey.svg?style=flat-square)](https://packagist.org/packages/monkeysoft/laravel-sitesmonkey)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/monkeysoftnl/laravel-sitesmonkey/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/monkeysoftnl/laravel-sitesmonkey/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/monkeysoftnl/laravel-sitesmonkey/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/monkeysoftnl/laravel-sitesmonkey/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/monkeysoft/laravel-sitesmonkey.svg?style=flat-square)](https://packagist.org/packages/monkeysoft/laravel-sitesmonkey)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Support us

[<img src="https://github-ads.s3.eu-central-1.amazonaws.com/laravel-sitesmonkey.jpg?t=1" width="419px" />](https://spatie.be/github-ad-click/laravel-sitesmonkey)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Installation

You can install the package via composer:

```bash
composer require monkeysoftnl/laravel-sitesmonkey
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-sitesmonkey-config"
```

This is the contents of the published config file:

```php
return [
    'enabled' => env('SITESMONKEY_ENABLED', true),
    'website_id' => env('SITESMONKEY_WEBSITE_ID', ''),
    'website_secret' => env('SITESMONKEY_SECRET_KEY', ''),
    'api_url' => env('SITESMONKEY_API_URL', 'https://api.sitesmonkey.com'),
    //
    'auth' => [
        'model' => env('SITESMONKEY_AUTH_MODEL', '\App\Models\User'),
        'route_after_login' => env('SITESMONKEY_AUTH_ROUTE_AFTER_LOGIN', 'dashboard'),
    ],
    'minimal_log_level' => env('SITESMONKEY_MINIMAL_LOG_LEVEL', 400),
    'ignore' => ['exceptions' => [], 'messages' => []],
    'ignore_guest_livewire_payload_errors' => env('SITESMONKEY_IGNORE_GUEST_LIVEWIRE_ERRORS', true),
    'throttle_minutes' => env('SITESMONKEY_THROTTLE_MINUTES', 10),
    'inventory' => [
        'enabled' => env('SITESMONKEY_INVENTORY_ENABLED', true),
        'key_id' => env('SITESMONKEY_INVENTORY_KEY_ID', ''),
        'key_secret' => env('SITESMONKEY_INVENTORY_KEY_SECRET', ''),
        'schedule' => env('SITESMONKEY_INVENTORY_CRON'),
        'php_extensions' => [/* a short allowlist */],
        'node_lockfiles' => [],
    ],
];
```

## Usage

Add SitesMonkey to your logging configuration in `config/logging.php`:
```php
        'sitesMonkey' => [
            'driver' => 'custom',
            'via' => \MonkeySoft\SitesMonkey\Logging\SitesMonkeyLogger::class,
        ],
```

## Vulnerability monitoring

The package sends SitesMonkey the list of software your application runs, so SitesMonkey can warn you when a package has a known vulnerability. It is sent every hour (at a fixed minute that is different for every website) through the Laravel scheduler, so make sure `php artisan schedule:run` runs every minute.

1. In SitesMonkey, create a connector key for the website. The secret is shown once.
2. Add the key to your `.env`:

```dotenv
SITESMONKEY_WEBSITE_ID=<website id from SitesMonkey>
SITESMONKEY_INVENTORY_KEY_ID=smk_...
SITESMONKEY_INVENTORY_KEY_SECRET=...
```

3. Check what will be sent, then send it once:

```bash
php artisan sitesmonkey:inventory:push --dry-run
php artisan sitesmonkey:inventory:push
```

Every request is signed with your key (HMAC with a timestamp and a one-time nonce) and sent over HTTPS without following redirects.

**What is sent:** the Laravel and PHP version, the name and version of every production package in `composer.lock` (not `packages-dev`), the name and version of a short list of PHP extensions (`inventory.php_extensions`), and, when you configure it, the production packages of Node lockfiles. **What is never sent:** `.env`, configuration values, credentials, tokens, source code or database contents. SitesMonkey rejects fields it does not know, so nothing can be added by accident.

Without a `composer.lock` the packages Composer recorded as installed are used. When the packages cannot be determined at all, nothing is sent: an empty list would look like everything was removed.

### Node packages

Most Laravel applications only use Node to build assets, so Node packages are not reported by default. If your production runtime includes Node packages (for example server-side rendering), list the lockfiles in `config/sitesmonkey.php`:

```php
'inventory' => [
    'node_lockfiles' => ['package-lock.json'],
],
```

`package-lock.json`, `npm-shrinkwrap.json` and `yarn.lock` (next to a `package.json`) work out of the box. `pnpm-lock.yaml` and Yarn Berry lockfiles are YAML and need `symfony/yaml` (`composer require symfony/yaml`). If a configured lockfile cannot be read, Node packages are left out of that inventory and SitesMonkey keeps what it knew before. Files outside your application folder are never read.

### Pulling instead of pushing

SitesMonkey can also fetch the same list from `GET /api/v1/sitesmonkey/inventory`, with the website secret (`SITESMONKEY_SECRET_KEY`) in the `X-SitesMonkey-Key` header, like the status route.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [MonkeySoft](https://github.com/monkeysoftnl)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
