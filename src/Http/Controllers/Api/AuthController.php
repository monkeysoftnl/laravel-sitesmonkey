<?php

namespace MonkeySoft\SitesMonkey\Http\Controllers\Api;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use stdClass;

class AuthController extends \MonkeySoft\SitesMonkey\Http\Controllers\Controller
{
    /**
     * Save the status message.
     */
    public function getUsers(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $model = config('sitesmonkey.auth.model');
        if (! class_exists($model)) {
            return response()->json(['error' => 'User model not found'], 500);
        }

        $dbUsers = $model::all();
        $users = [];

        foreach ($dbUsers as $dbUser) {
            $user = new stdClass;
            $user->user_login = $dbUser->email ?? 'Unknown';
            $user->display_name = $dbUser->fullName ?? 'Unknown User';
            $users[] = $user;
        }

        return response()->json($users);
    }

    public function login(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $model = config('sitesmonkey.auth.model');
        if (! class_exists($model)) {
            return response()->json(['error' => 'User model not found'], 500);
        }

        $request->validate([
            'token' => 'required',
            'action' => 'required',
            'action_data' => 'required',
        ]);

        $url = sprintf('%s/api/v1/validate-website-token', config('sitesmonkey.api_url'));

        $response = Http::asForm()->post($url, [
            'token' => $request->input('token'),
            'action' => $request->input('action'),
            'action_data' => $request->input('action_data'),
        ])->json();

        if (isset($response['error'])) {
            return response()->json(['error' => $response['error']], 401);
        }

        if (! isset($response['success']) || ! $response['success']) {
            return response()->json(['error' => 'Invalid token or action'], 401);
        }

        $actionData = $request->input('action_data');
        $actionData = base64_decode($actionData);
        $username = json_decode($actionData)->username;

        $dbUser = $model::firstWhere(['email' => $username]);
        if (! $dbUser) {
            return response()->json(['error' => 'User not found'], 404);
        }

        Auth::login($dbUser, true);

        return redirect()->intended(route(config('sitesmonkey.auth.route_after_login')));
    }
}
