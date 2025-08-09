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
        $token = $request->bearerToken();

        // Check if the secret key and website id are empty
        if (empty($token)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        // Check if the secret key is valid
        if ($token !== config('sitesmonkey.website_secret')) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        return $next($request);
    }
}
