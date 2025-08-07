<?php

namespace MonkeySoft\SitesMonkey\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWebsiteSecretKeyIsValid
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $inputSecretKey = $request->input('website_secret_key');
        $inputWebsiteId = $request->input('website_id');

        // Check if the secret key and website id are empty
        if (empty($inputWebsiteId)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }
        if (empty($inputSecretKey)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        // Check if website_id is set to the configured website ID
        if (! $inputWebsiteId == config('sitesmonkey.website_id')) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        // Check if the secret key is valid
        if (! $inputSecretKey == config('sitesmonkey.website_secret_key')) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        return $next($request);
    }
}
