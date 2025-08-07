<?php

use Illuminate\Support\Facades\Route;

Route::post('/v1/sitesmonkey/status', [\MonkeySoft\SitesMonkey\Http\Controllers\Api\StatusController::class, 'getStatus'])
    ->name('v1-sitesmonkey-status')->middleware(\MonkeySoft\SitesMonkey\Http\Middleware\EnsureWebsiteSecretKeyIsValid::class);
