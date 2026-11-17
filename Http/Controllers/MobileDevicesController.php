<?php

namespace Modules\ApiWebhooks\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class MobileDevicesController extends Controller
{
    public function register(Request $request)
    {
        $request->validate(['provider' => 'required|in:apns,fcm', 'push_token' => 'required|string|max:4096']);
        $token = $request->attributes->get('device_token');
        abort_unless($token->kind === 'device', 403);
        if ($request->input('provider') === 'apns') {
            abort_unless(preg_match('/\A[a-fA-F0-9]{64,200}\z/D', $request->input('push_token')), 422);
        }
        DB::table('api_push_devices')->updateOrInsert(['token_id' => $token->id], [
            'provider' => $request->input('provider'), 'destination' => encrypt($request->input('push_token')),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['registered' => true]);
    }

    public function remove(Request $request)
    {
        DB::table('api_push_devices')->where('token_id', $request->attributes->get('device_token')->id)->delete();
        return response()->json(['registered' => false]);
    }
}
