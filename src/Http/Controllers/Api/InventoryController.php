<?php

namespace MonkeySoft\SitesMonkey\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use MonkeySoft\SitesMonkey\Exceptions\InventoryException;
use MonkeySoft\SitesMonkey\Http\Controllers\Controller;
use MonkeySoft\SitesMonkey\Inventory\InventoryCollector;

class InventoryController extends Controller
{
    /**
     * The inventory, for SitesMonkey to fetch when no push came in.
     */
    public function show(InventoryCollector $collector): JsonResponse
    {
        try {
            return response()->json($collector->collect());
        } catch (InventoryException) {
            return response()->json(['error' => 'The installed packages could not be determined.'], 503);
        }
    }
}
