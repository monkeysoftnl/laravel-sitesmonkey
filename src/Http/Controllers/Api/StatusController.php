<?php

namespace MonkeySoft\SitesMonkey\Http\Controllers\Api;

class StatusController extends \MonkeySoft\SitesMonkey\Http\Controllers\Controller
{
    /**
     * Save the status message.
     */
    public function getStatus(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'php_version' => phpversion(),
            'laravel_version' => app()->version(),
        ]);
    }
}
