<?php

namespace Modules\ApiWebhooks\Http\Controllers;

use App\Option;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\ApiWebhooks\Models\DeviceToken;
use Modules\ApiWebhooks\Services\OutboundEvents;
use Modules\ApiWebhooks\Services\PushTransport;
use Modules\ApiWebhooks\Services\WebhookTransport;

class MobileSettingsController extends ApiKeysController
{
    public function dashboard()
    {
        return response()->view('apiwebhooks::mobile-settings', [
            'webhooks' => DB::table('api_webhook_subscriptions')->get(['id', 'name', 'url', 'active']),
            'deliveries' => DB::table('api_deliveries')->orderBy('id', 'desc')->limit(100)->get(['id', 'kind', 'destination_id', 'attempts', 'status_code', 'next_attempt_at', 'delivered_at']),
            'serverTokens' => DeviceToken::where('kind', 'server')->get(),
            'users' => User::nonDeleted()->get(['id', 'first_name', 'last_name']),
            'mailboxes' => \App\Mailbox::all(['id', 'name']),
            'savedReplies' => DB::table('api_saved_replies')->orderBy('name')->get(),
            'apnsPresent' => (bool) Option::get('apiwebhooks_apns_credentials'),
            'fcmPresent' => (bool) Option::get('apiwebhooks_fcm_credentials'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function save(Request $request)
    {
        try {
            app(\Modules\ApiWebhooks\Services\ApiSettings::class)->save($request);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            // Never flash provider private keys into unencrypted old-input session storage.
            return back()->withErrors($exception->validator);
        }
        return back()->with('flash_success', 'Mobile API settings saved.');
    }

    public function serverToken(Request $request)
    {
        $request->validate(['user_id' => 'required|integer', 'name' => 'required|string|max:100',
            'access_level' => 'required|in:root,own,broad', 'allowed_ips' => 'required|string|max:1000']);
        $user = User::findOrFail($request->input('user_id'));
        list($token, $plain) = DeviceToken::issue($user, $request->input('name'), $request->input('access_level'), 'server', $request->input('allowed_ips'));
        return back()->with('mobile_new_token', encrypt($plain))->with('flash_success', 'Server credential created. Copy it now; it will not be shown again.');
    }

    public function testServer(Request $request, $id)
    {
        $token = DeviceToken::where('kind', 'server')->findOrFail($id);
        $plain = $request->input('candidate');
        if (!is_string($plain) || !preg_match('/\A[a-f0-9]{64}\z/D', $plain)
            || !hash_equals($token->token_hash, hash('sha256', $plain))) {
            return back()->with('flash_error', 'The supplied credential does not match this server key.');
        }
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $url = rtrim(config('app.url'), '/').'/api/v2/me';
        $signed = Request::create($url, 'GET', [], [], [], [], '');
        $signed->headers->set('X-Api-Timestamp', $timestamp);
        $signed->headers->set('X-Api-Nonce', $nonce);
        $signature = hash_hmac('sha256', \Modules\ApiWebhooks\Http\Middleware\MobileAuth::canonical($signed), $plain);
        try {
            $client = new \GuzzleHttp\Client(['http_errors' => false, 'allow_redirects' => false, 'timeout' => 15]);
            $response = $client->get($url, ['headers' => ['X-Api-Key' => $plain,
                'X-Api-Timestamp' => $timestamp, 'X-Api-Nonce' => $nonce,
                'X-Api-Signature' => $signature, 'Accept' => 'application/json']]);
            $status = $response->getStatusCode();
            return back()->with($status === 200 ? 'flash_success' : 'flash_error', 'Signed server test returned HTTP '.$status.'. The server must be in this credential’s allowlist.');
        } catch (\Exception $exception) {
            return back()->with('flash_error', 'The signed test could not connect. Test from an allowed client using the API documentation.');
        }
    }

    public function revokeServer($id)
    {
        $token = DeviceToken::where('kind', 'server')->findOrFail($id);
        $token->revoked_at = now();
        $token->save();
        return back()->with('flash_success', 'Server credential revoked.');
    }

    public function webhook(Request $request)
    {
        $request->validate(['name' => 'required|string|max:100', 'url' => 'required|url|max:2000']);
        app(WebhookTransport::class)->destination($request->input('url'));
        $plain = bin2hex(random_bytes(32));
        DB::table('api_webhook_subscriptions')->insert([
            'name' => $request->input('name'), 'url' => $request->input('url'), 'secret' => encrypt($plain),
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return back()->with('mobile_webhook_secret', encrypt($plain))->with('flash_success', 'Webhook created. Copy its signing secret now.');
    }

    public function deleteWebhook($id)
    {
        DB::table('api_webhook_subscriptions')->where('id', $id)->update(['active' => false, 'updated_at' => now()]);
        return back()->with('flash_success', 'Webhook disabled.');
    }

    public function testWebhook($id)
    {
        abort_unless(DB::table('api_webhook_subscriptions')->where('id', $id)->where('active', true)->exists(), 404);
        app(OutboundEvents::class)->enqueue('webhook', $id, ['event' => 'api.test']);
        return back()->with('flash_success', 'Fixed webhook test queued. Check the delivery log.');
    }

    public function testPush(Request $request)
    {
        $request->validate(['provider' => 'required|in:apns,fcm']);
        $tokens = DeviceToken::where('user_id', auth()->id())->where('kind', 'device')->whereNull('revoked_at')->where('expires_at', '>', now())->pluck('id');
        $device = DB::table('api_push_devices')->whereIn('token_id', $tokens)->where('provider', $request->input('provider'))->first();
        if (!$device) {
            return back()->with('flash_error', 'Register your own '.$request->input('provider').' device before testing.');
        }
        try {
            $status = app(PushTransport::class)->send($device, ['event' => 'api.test']);
            return back()->with($status >= 200 && $status < 300 ? 'flash_success' : 'flash_error', 'Push test returned HTTP '.$status.'.');
        } catch (\Exception $exception) {
            return back()->with('flash_error', 'Push test failed. Check the configured credentials and device registration.');
        }
    }

    public function savedReply(Request $request)
    {
        $request->validate(['name' => 'required|string|max:100', 'body' => 'required|string|max:100000',
            'mailbox_id' => 'nullable|integer|exists:mailboxes,id', 'user_id' => 'nullable|integer|exists:users,id']);
        DB::table('api_saved_replies')->insert(array_merge($request->only(['name', 'body', 'mailbox_id', 'user_id']), ['created_at' => now(), 'updated_at' => now()]));
        return back()->with('flash_success', 'Saved reply created.');
    }

    public function deleteSavedReply($id)
    {
        DB::table('api_saved_replies')->where('id', $id)->delete();
        return back()->with('flash_success', 'Saved reply removed.');
    }
}
