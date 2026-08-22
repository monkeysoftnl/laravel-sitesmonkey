<?php

use Illuminate\Support\Facades\Route;
use MonkeySoft\SitesMonkey\Http\Controllers\Api\AuthController;
use MonkeySoft\SitesMonkey\Http\Controllers\Api\StatusController;
use MonkeySoft\SitesMonkey\Http\Middleware\EnsureWebsiteSecretKeyIsValid;

Route::get('/api/v1/sitesmonkey/status', [StatusController::class, 'getStatus'])
    ->name('v1-sitesmonkey-status')->middleware(EnsureWebsiteSecretKeyIsValid::class);

Route::get('/api/v1/sitesmonkey/users', [AuthController::class, 'getUsers'])
    ->name('v1-sitesmonkey-users')->middleware(EnsureWebsiteSecretKeyIsValid::class);

Route::get('/v1/sitesmonkey/login', [AuthController::class, 'login'])
    ->name('v1-sitesmonkey-login')->middleware('throttle:10,1');
