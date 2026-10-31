# Laravel Package for SitesMonkey Monitoring

[![Latest Version on Packagist](https://img.shields.io/packagist/v/monkeysoft/laravel-sitesmonkey.svg?style=flat-square)](https://packagist.org/packages/monkeysoft/laravel-sitesmonkey)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/monkeysoftnl/laravel-sitesmonkey/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/monkeysoftnl/laravel-sitesmonkey/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/monkeysoftnl/laravel-sitesmonkey/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/monkeysoftnl/laravel-sitesmonkey/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/monkeysoft/laravel-sitesmonkey.svg?style=flat-square)](https://packagist.org/packages/monkeysoft/laravel-sitesmonkey)

Connects a Laravel application to [SitesMonkey](https://sitesmonkey.com), the website monitoring platform of MonkeySoft. Once installed, SitesMonkey can:

- **Receive errors** from your application through a Laravel log channel, with bot noise filtered out and duplicates throttled.
- **Check the status** of the application: PHP, Laravel and database version.
- **Monitor vulnerabilities** in the packages you run, from an hourly signed inventory of Composer (and optionally Node) packages.
- **Record logins**: every successful login is reported to SitesMonkey.
- **Log in as a user** ("login as") from the SitesMonkey dashboard, with a one-time token that SitesMonkey validates.

## Requirements

- PHP 8.3 or higher
- Laravel 10, 11, 12 or 13
- A website in SitesMonkey (website id and secret key)
- The Laravel scheduler (`php artisan schedule:run` every minute) for vulnerability monitoring
- Optional: `symfony/yaml` to read `pnpm-lock.yaml` and Yarn Berry lockfiles

## Installation

Install the package via Composer:

```bash
composer require monkeysoft/laravel-sitesmonkey
```

The service provider and the `SitesMonkey` facade are registered automatically through package discovery.

Add the credentials of your website in SitesMonkey to `.env`:

```dotenv
SITESMONKEY_WEBSITE_ID=<website id from SitesMonkey>
SITESMONKEY_SECRET_KEY=<website secret key from SitesMonkey>
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="laravel-sitesmonkey-config"
```

## Configuration

All settings live in `config/sitesmonkey.php` and can mostly be set through environment variables.

| Key | Environment variable | Default | Description |
| --- | --- | --- | --- |
| `enabled` | `SITESMONKEY_ENABLED` | `true` | Turns sending errors and logins on or off. |
| `website_id` | `SITESMONKEY_WEBSITE_ID` | `''` | The id of the website in SitesMonkey. |
| `website_secret` | `SITESMONKEY_SECRET_KEY` | `''` | The secret key of the website. Used to send errors and logins, and to protect the routes SitesMonkey calls. |
| `api_url` | `SITESMONKEY_API_URL` | `https://api.sitesmonkey.com` | The SitesMonkey API. |
| `auth.model` | `SITESMONKEY_AUTH_MODEL` | `\App\Models\User` | The user model for "login as" and the user list. |
| `auth.route_after_login` | `SITESMONKEY_AUTH_ROUTE_AFTER_LOGIN` | `dashboard` | Route **name** to redirect to after "login as". |
| `auth.impersonatable_query` | – | `null` | Closure that limits which users SitesMonkey may list and log in as. See [Login as](#login-as). |
| `minimal_log_level` | `SITESMONKEY_MINIMAL_LOG_LEVEL` | `400` | Lowest Monolog level that is sent (`400` = error, `500` = critical). |
| `ignore.exceptions` | – | `[]` | Exception classes (and subclasses) that are never sent. |
| `ignore.messages` | – | `[]` | Regular expressions matched against the log message and exception message; a match is never sent. |
| `ignore_guest_livewire_payload_errors` | `SITESMONKEY_IGNORE_GUEST_LIVEWIRE_ERRORS` | `true` | Skip crashes caused by guests posting broken Livewire payloads (bots). |
| `throttle_minutes` | `SITESMONKEY_THROTTLE_MINUTES` | `10` | The same error is sent at most once per this many minutes. `0` sends every one. |
| `inventory.enabled` | `SITESMONKEY_INVENTORY_ENABLED` | `true` | Turns vulnerability monitoring on or off. |
| `inventory.schedule` | `SITESMONKEY_INVENTORY_CRON` | `null` | Cron expression for the push. `null` picks a fixed minute of every hour, different per website. |
| `inventory.php_extensions` | – | short allowlist | PHP extensions whose name and version are reported. |
| `inventory.node_lockfiles` | – | `[]` | Node lockfiles (relative to the application) whose production packages are reported. |

## Error reporting

Add a SitesMonkey channel to `config/logging.php`:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'sitesmonkey'],
        'ignore_exceptions' => false,
    ],

    'sitesmonkey' => [
        'driver' => 'custom',
        'via' => \MonkeySoft\SitesMonkey\Logging\SitesMonkeyLogger::class,
    ],

    // ...
],
```

Every log record at or above `minimal_log_level` (default: error) is sent to SitesMonkey with its level, channel, message and the exception including the stack trace. Nothing is sent when `enabled` is `false` or when the website id or secret key is missing. When SitesMonkey cannot be reached, the error is dropped silently so logging never breaks your application (timeout: 5 seconds).

### Filtering noise

Public sites are hit by bots with broken or tampered requests. To keep those from turning into reports and notifications:

- **Guest Livewire errors**: a type, JSON or `ErrorException` on `livewire/update` from a visitor who is not logged in is skipped. Real Livewire components never send such a payload. Turn off with `SITESMONKEY_IGNORE_GUEST_LIVEWIRE_ERRORS=false`.
- **Throttling**: the same error (same exception class, exception message and log message) is sent once per `throttle_minutes`. The first occurrence is always sent. This uses the application cache.
- **Ignore lists**: never send certain errors.

```php
'ignore' => [
    'exceptions' => [
        \Illuminate\Session\TokenMismatchException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
    ],
    'messages' => [
        '/^Unable to connect to Redis/',
    ],
],
```

## Status endpoint

SitesMonkey checks the application through:

```
GET /api/v1/sitesmonkey/status
X-SitesMonkey-Key: <SITESMONKEY_SECRET_KEY>
```

It returns the application name, description and version (`app.name`, `app.description`, `app.version`), the PHP and Laravel version, the database driver and, for MySQL and MariaDB, the database server version.

## Vulnerability monitoring

The package sends SitesMonkey the list of software your application runs, so SitesMonkey can warn you when a package has a known vulnerability. It is sent every hour (at a fixed minute that is different for every website) through the Laravel scheduler, so make sure `php artisan schedule:run` runs every minute. The scheduled task runs in the background, on one server, and never overlaps itself.

1. Nothing new to create: the inventory is signed with the website id and secret key you already set for this package.
2. Make sure your `.env` has them:

```dotenv
SITESMONKEY_WEBSITE_ID=<website id from SitesMonkey>
SITESMONKEY_SECRET_KEY=<secret key from SitesMonkey>
```

3. Check what will be sent, then send it once:

```bash
php artisan sitesmonkey:inventory:push --dry-run
php artisan sitesmonkey:inventory:push
```

The push is only scheduled when `inventory.enabled` is `true` and the website id, key id and key secret are all set. To choose your own moment, set a cron expression:

```dotenv
SITESMONKEY_INVENTORY_CRON="15 */6 * * *"
```

Every request is signed with your key (HMAC-SHA256 over method, path, body hash, timestamp and a one-time nonce) and sent over HTTPS without following redirects.

**What is sent:** the Laravel and PHP version, the name and version of every production package in `composer.lock` (not `packages-dev`), a hash of `composer.lock`, the name and version of a short list of PHP extensions (`inventory.php_extensions`), and, when you configure it, the production packages of Node lockfiles. **What is never sent:** `.env`, configuration values, credentials, tokens, source code or database contents. SitesMonkey rejects fields it does not know, so nothing can be added by accident.

Example payload:

```json
{
  "schema_version": 1,
  "type": "laravel",
  "collected_at": "2026-01-01T00:00:00+00:00",
  "php_version": "8.4.1",
  "laravel_version": "11.9.0",
  "composer_packages": [{"name": "laravel/framework", "version": "v11.9.0"}],
  "php_extensions": [{"name": "intl", "version": "8.4.1"}],
  "site_id": "0f8fad5b-d9cb-469f-a165-70867728950e",
  "lockfile_hash": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
  "node_packages": [{"name": "lodash", "version": "4.17.20", "package_manager": "npm"}],
  "last_successful_inventory_at": "2026-01-01T00:00:00+00:00"
}
```

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

SitesMonkey can also fetch the same list from `GET /api/v1/sitesmonkey/inventory`, with the website secret (`SITESMONKEY_SECRET_KEY`) in the `X-SitesMonkey-Key` header, like the status route. This route is limited to 30 requests per minute.

### Troubleshooting

`php artisan sitesmonkey:inventory:push` explains why a push failed:

| Message | Cause |
| --- | --- |
| SitesMonkey inventory is not configured | `SITESMONKEY_WEBSITE_ID` or `SITESMONKEY_SECRET_KEY` is missing, or inventory is disabled. |
| The installed packages could not be determined | No readable `composer.lock` or Composer installed data; nothing was sent. |
| Another inventory push is already running | A push holds the cache lock (max. 5 minutes). |
| SitesMonkey did not accept the inventory (HTTP …) | Wrong key, revoked key, clock out of sync, or a payload SitesMonkey rejects. |
| SitesMonkey could not be reached | Network or DNS problem. |

Warnings (for example a Node lockfile that could not be read) are shown in the console and never sent.

## Login tracking

The package listens to Laravel's `Illuminate\Auth\Events\Login` event. On every successful login it sends SitesMonkey the IP address, user agent, and the id, name and email address of the user. This happens only when `enabled` is `true` and the website id and secret key are set. Errors while sending are ignored.

## Login as

From the SitesMonkey dashboard you can log in to the application as one of its users, for support.

```
GET /api/v1/sitesmonkey/users
X-SitesMonkey-Key: <SITESMONKEY_SECRET_KEY>
```

lists the users SitesMonkey may log in as (`email` as `user_login`, `fullName` as `display_name`).

```
GET /v1/sitesmonkey/login?token=...&action=login_as&action_data=...
```

redeems a one-time token. The package asks SitesMonkey to validate the token for this website (`website_id`), looks up the user by email address from `action_data` (base64 JSON with a `username`), logs in without "remember me" and redirects to the intended URL or the `auth.route_after_login` route. Only the `login_as` action is accepted and the route is limited to 10 requests per minute.

By default every user can be impersonated. Limit this with a closure that returns a query builder:

```php
'auth' => [
    'impersonatable_query' => fn (string $model) => $model::query()->where('is_admin', true),
],
```

## Authentication of SitesMonkey requests

The status, inventory and users routes require the website secret key. Send it in the `X-SitesMonkey-Key` header (preferred, because an OAuth layer in front of the application may claim the `Authorization` header) or as a bearer token. The key is compared in constant time. A missing or wrong key returns `401 Invalid credentials`.

## Routes overview

| Method | Path | Protection | Purpose |
| --- | --- | --- | --- |
| `GET` | `/api/v1/sitesmonkey/status` | Website secret | Application status |
| `GET` | `/api/v1/sitesmonkey/inventory` | Website secret, 30/min | Package inventory |
| `GET` | `/api/v1/sitesmonkey/users` | Website secret | Users for "login as" |
| `GET` | `/v1/sitesmonkey/login` | One-time token, 10/min | Redeem "login as" token |

## Commands

| Command | Description |
| --- | --- |
| `php artisan sitesmonkey:inventory:push` | Send the inventory to SitesMonkey now. |
| `php artisan sitesmonkey:inventory:push --dry-run` | Show the inventory as JSON without sending it. |

## Testing

```bash
composer test          # Pest
composer analyse       # PHPStan / Larastan
composer format        # Laravel Pint
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [MonkeySoft](https://github.com/monkeysoftnl)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
