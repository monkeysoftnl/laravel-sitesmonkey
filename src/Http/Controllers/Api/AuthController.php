<?php

namespace MonkeySoft\SitesMonkey\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MonkeySoft\SitesMonkey\Http\Controllers\Controller;
use stdClass;

class AuthController extends Controller
{
    /**
     * List the accounts SitesMonkey may impersonate.
     */
    public function getUsers(Request $request): JsonResponse
    {
        $model = config('sitesmonkey.auth.model');
        if (! class_exists($model)) {
            return response()->json(['error' => 'User model not found'], 500);
        }

        $users = [];

        foreach ($this->impersonatableUsers($model) as $dbUser) {
            $user = new stdClass;
            $user->user_login = $dbUser->email ?? 'Unknown';
            $user->display_name = $dbUser->fullName ?? 'Unknown User';
            $users[] = $user;
        }

        return response()->json($users);
    }

    public function login(Request $request): JsonResponse|RedirectResponse
    {
        $model = config('sitesmonkey.auth.model');
        if (! class_exists($model)) {
            return response()->json(['error' => 'User model not found'], 500);
        }

        $request->validate([
            'token' => 'required|string',
            'action' => 'required|string',
            'action_data' => 'required|string',
        ]);

        // This endpoint exists solely to redeem a login-as token; refusing any
        // other action stops a token minted elsewhere being spent on a session.
        if ($request->input('action') !== 'login_as') {
            return response()->json(['error' => 'Invalid token or action'], 401);
        }

        $websiteId = config('sitesmonkey.website_id');

        if (empty($websiteId)) {
            Log::warning('SitesMonkey: refusing login-as because no website_id is configured.');

            return response()->json(['error' => 'Invalid token or action'], 401);
        }

        $url = sprintf('%s/api/v1/validate-website-token', config('sitesmonkey.api_url'));

        $response = Http::asForm()->post($url, [
            'token' => $request->input('token'),
            'action' => $request->input('action'),
            'action_data' => $request->input('action_data'),
            // Lets SitesMonkey confirm the token was issued for this site, so it
            // cannot be redeemed against a different installation.
            'website_id' => $websiteId,
        ])->json();

        if (isset($response['error'])) {
            return response()->json(['error' => $response['error']], 401);
        }

        if (! isset($response['success']) || ! $response['success']) {
            return response()->json(['error' => 'Invalid token or action'], 401);
        }

        $username = $this->resolveUsername($request->input('action_data'));

        if ($username === null) {
            return response()->json(['error' => 'Invalid token or action'], 401);
        }

        $dbUser = $model::firstWhere(['email' => $username]);
        if (! $dbUser) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // No "remember me": an impersonation session must end with the browser
        // session, not linger in a long-lived cookie.
        Auth::login($dbUser, false);

        return redirect()->intended(route(config('sitesmonkey.auth.route_after_login')));
    }

    /**
     * Decode the base64 JSON payload and pull out a usable username.
     */
    private function resolveUsername(string $actionData): ?string
    {
        $decoded = base64_decode($actionData, true);

        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);

        if (! is_array($payload) || ! isset($payload['username']) || ! is_string($payload['username'])) {
            return null;
        }

        $username = trim($payload['username']);

        return $username === '' ? null : $username;
    }

    /**
     * Resolve which accounts may be listed and impersonated.
     *
     * Applications that should not expose every user can point
     * `sitesmonkey.auth.impersonatable_query` at a closure returning a query
     * builder limited to, say, admins only.
     *
     * @param  class-string  $model
     * @return iterable<object>
     */
    private function impersonatableUsers(string $model): iterable
    {
        $resolver = config('sitesmonkey.auth.impersonatable_query');

        if (is_callable($resolver)) {
            return $resolver($model)->get();
        }

        return $model::all();
    }
}
