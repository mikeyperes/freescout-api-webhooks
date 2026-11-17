<?php

namespace Modules\ApiWebhooks\Http\Controllers;

use App\User;
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
        $request->validate(['name' => 'required|string|max:100', 'access_level' => 'required|in:own,broad,root']);
        // Complete FreeScout's own credential checks and login hooks; never compare passwords here.
        $response = app(\App\Http\Controllers\Auth\LoginController::class)->login($request);
        if (!auth()->check() || !auth()->user()->isActive()) {
            return response()->json(['error' => 'authentication_incomplete'], 401);
        }
        foreach (\Module::all() as $module) {
            if ($module->enabled() && preg_match('/two.?factor|2fa/i', $module->getName())) {
                // Complete the existing FreeScout TOTP/recovery flow in the same cookie jar.
                // Device issuance then runs under the normal protected web route, including
                // FreeScout's middleware.web.custom_handle hooks. No 2FA state is invented here.
                return response()->json(['authentication' => 'native_two_factor',
                    'next' => $response->headers->get('Location') ?: url('/'),
                    'exchange_url' => route('apiwebhooks.device-exchange')], 202);
            }
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
        $request->validate(['name' => 'required|string|max:100', 'access_level' => 'required|in:own,broad,root']);
        list($token, $plain) = DeviceToken::issue(auth()->user(), $request->input('name'), $request->input('access_level'));
        return response()->json(['token' => $plain, 'token_type' => 'Bearer', 'expires_at' => $token->expires_at->toIso8601String()], 201)
            ->header('Cache-Control', 'no-store');
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
