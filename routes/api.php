<?php

use Illuminate\Support\Facades\Route;

Route::get('/api/v1/sitesmonkey/status', [\MonkeySoft\SitesMonkey\Http\Controllers\Api\StatusController::class, 'getStatus'])
    ->name('v1-sitesmonkey-status')->middleware(\MonkeySoft\SitesMonkey\Http\Middleware\EnsureWebsiteSecretKeyIsValid::class);

Route::get('/api/v1/sitesmonkey/users', [\MonkeySoft\SitesMonkey\Http\Controllers\Api\AuthController::class, 'getUsers'])
    ->name('v1-sitesmonkey-users')->middleware(\MonkeySoft\SitesMonkey\Http\Middleware\EnsureWebsiteSecretKeyIsValid::class);

Route::get('/v1/sitesmonkey/login', [\MonkeySoft\SitesMonkey\Http\Controllers\Api\AuthController::class, 'login'])
    ->name('v1-sitesmonkey-login');
