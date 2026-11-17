<?php

namespace Modules\ApiWebhooks\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ApiWebhooks\Models\DeviceToken;

class MobileSessionController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(app(\Modules\ApiWebhooks\Services\TransportSecurity::class)->isSecure($request), 403);
            return $next($request);
        });
    }

    public function challenge()
    {
        return response()->json(['csrf_token' => csrf_token(), 'authentication' => 'FreeScout session login'])
            ->header('Cache-Control', 'no-store, private');
    }

    public function login(Request $request)
    {
        $this->validateTokenRequest($request);
        // Complete FreeScout's own credential checks and login hooks; never compare passwords here.
        $response = app(\App\Http\Controllers\Auth\LoginController::class)->login($request);
        if (!auth()->check() || !auth()->user()->isActive()) {
            return response()->json(['error' => 'authentication_incomplete'], 401);
        }
        if (!\Eventy::filter('apiwebhooks.device_login_ready', true, $request, auth()->user())) {
            return response()->json(['authentication' => 'additional_authentication_required',
                'next' => $response->headers->get('Location') ?: url('/'),
                'exchange_url' => route('apiwebhooks.device-exchange')], 202);
        }
        return $this->issue($request);
    }

    public function exchange()
    {
        // Use a regular web path so native 2FA middleware cannot mistake this for a
        // server API route that is exempt from interactive authentication.
        return redirect()->route('apiwebhooks.device-exchange', [], 307);
    }

    public function issue(Request $request)
    {
        $this->validateTokenRequest($request);
        abort_unless(\Eventy::filter('apiwebhooks.device_login_ready', true, $request, auth()->user()), 401);
        try {
            list($token, $plain) = DeviceToken::issue(auth()->user(), $request->input('name'), $request->input('access_level'));
            return response()->json(['token' => $plain, 'token_type' => 'Bearer', 'expires_at' => $token->expires_at->toIso8601String()], 201)
                ->header('Cache-Control', 'no-store');
        } finally {
            // A device credential must not leave a second ambient web credential behind.
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    protected function validateTokenRequest($request)
    {
        $request->validate(['name' => 'required|string|max:100', 'access_level' => 'required|in:own,broad,root']);
    }

    public function index(Request $request)
    {
        return response()->json(['data' => DeviceToken::where('user_id', $request->user()->id)->get()]);
    }

    public function revoke(Request $request, $id)
    {
        $token = DeviceToken::where('user_id', $request->user()->id)->findOrFail($id);
        $token->revoked_at = now();
        $token->save();
        return response()->json(['revoked' => true]);
    }
}
