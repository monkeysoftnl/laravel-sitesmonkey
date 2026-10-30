<?php

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/sm-route-'.uniqid();
    mkdir($this->base);
    copy(__DIR__.'/../fixtures/composer/composer.lock', $this->base.'/composer.lock');
    $this->app->setBasePath($this->base);

    config()->set('sitesmonkey.website_id', 'site-1');
    config()->set('sitesmonkey.website_secret', 'the-website-secret');
});

afterEach(function (): void {
    @unlink($this->base.'/composer.lock');
    @rmdir($this->base);
});

it('returns the inventory for a request with the website secret', function (): void {
    $this->getJson('/api/v1/sitesmonkey/inventory', ['X-SitesMonkey-Key' => 'the-website-secret'])
        ->assertOk()
        ->assertJsonPath('type', 'laravel')
        ->assertJsonPath('site_id', 'site-1')
        ->assertJsonPath('composer_packages.0.name', 'laravel/framework');
});

it('returns 401 without the website secret', function (?string $key): void {
    $headers = $key === null ? [] : ['X-SitesMonkey-Key' => $key];

    $this->getJson('/api/v1/sitesmonkey/inventory', $headers)->assertUnauthorized();
})->with([
    'no key' => [null],
    'wrong key' => ['not-the-secret'],
]);

it('returns 503 without leaking details when the packages cannot be determined', function (): void {
    file_put_contents($this->base.'/composer.lock', '{"SECRET-VALUE": ');

    $response = $this->getJson('/api/v1/sitesmonkey/inventory', ['X-SitesMonkey-Key' => 'the-website-secret'])->assertStatus(503);

    expect($response->getContent())->not->toContain('SECRET-VALUE');
});
