<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
        $table->string('password')->nullable();
        $table->timestamps();
    });
    User::unguard();
    User::create(['email' => 'admin@example.com']);

    Route::get('/dashboard', fn () => 'dashboard')->name('dashboard');

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('sitesmonkey.website_id', 'site-1');
    config()->set('sitesmonkey.auth.model', User::class);
    config()->set('auth.providers.users.model', User::class);
});

function loginAsUrl(string $username = 'admin@example.com'): string
{
    return '/v1/sitesmonkey/login?'.http_build_query([
        'token' => 'one-time-token',
        'action' => 'login_as',
        'action_data' => base64_encode((string) json_encode(['username' => $username])),
    ]);
}

it('starts a session that survives the login-as redirect', function (): void {
    Http::fake(['*/api/v1/validate-website-token' => Http::response(['success' => true])]);

    $this->get(loginAsUrl())
        ->assertRedirect('/dashboard')
        ->assertCookie(config('session.cookie'));

    expect(Auth::id())->toBe(1);
});

it('does not log in when SitesMonkey rejects the token', function (): void {
    Http::fake(['*/api/v1/validate-website-token' => Http::response(['error' => 'Invalid token'])]);

    $this->get(loginAsUrl())->assertUnauthorized();

    expect(Auth::check())->toBeFalse();
});
