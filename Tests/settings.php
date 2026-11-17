<?php

require __DIR__.'/bootstrap.php';
require __DIR__.'/NativeSession.php';

use Modules\ApiWebhooks\Models\DeviceToken;
use Modules\ApiWebhooks\Services\ApiSettings;

$actor = App\User::findOrFail((int) ($argv[1] ?? 0));
check($actor->isAdmin() && $actor->isActive(), 'explicit settings test administrator');
list($client, $csrf) = NativeSession::open($actor, '/manage/api-webhooks/mobile');
$post = function ($path, array $values = []) use ($client, &$csrf) {
    $response = $client->post('manage/api-webhooks/mobile/'.$path, [
        'headers' => ['Referer' => rtrim(config('app.url'), '/').'/manage/api-webhooks/mobile'],
        'form_params' => array_merge(['_token' => $csrf], $values),
    ]);
    $html = $response->getBody()->getContents();
    if ($response->getStatusCode() === 200) {
        $csrf = NativeSession::csrf($html);
    }
    return [$response->getStatusCode(), $html];
};
$server = null;
$device = null;
$webhookId = null;
$savedReplyId = null;
$challenge = bin2hex(random_bytes(32));
$key2Before = DB::table('api_keys')->where('id', 2)->first(['scopes', 'allowed_ips']);
try {
    $values = [];
    foreach (ApiSettings::limits() as $field => $definition) {
        $values[$field] = ApiSettings::value($field);
    }
    list($status) = $post('settings', $values);
    check($status === 200, 'live limits save without changing provider credentials');
    $name = 'ticketapi-2 server UI '.bin2hex(random_bytes(5));
    list($status, $html) = $post('servers', ['name' => $name, 'user_id' => $actor->id,
        'access_level' => 'root', 'allowed_ips' => '51.81.93.236']);
    check($status === 200, 'live server credential creation form');
    $server = DeviceToken::where('name', $name)->firstOrFail();
    preg_match('/Copy this server credential now.*?<div[^>]*>([a-f0-9]{64})<\/div>/s', $html, $match);
    check(isset($match[1]) && hash_equals($server->token_hash, hash('sha256', $match[1])), 'UI shows raw server credential once');
    $plain = $match[1];
    list($status, $html) = $post('servers/'.$server->id.'/test', ['candidate' => $plain]);
    check($status === 200 && strpos($html, 'Signed server test returned HTTP 200') !== false, 'live server Test button performs signed HTTP request');
    check(strpos($html, $plain) === false, 'server credential is not shown a second time');
    list($status) = $post('servers/'.$server->id.'/revoke');
    check($status === 200 && $server->fresh()->revoked_at !== null, 'live server Revoke button');
    $name = 'ticketapi-2 webhook UI '.bin2hex(random_bytes(5));
    list($status) = $post('webhooks', ['name' => $name,
        'url' => rtrim(config('app.url'), '/').'/api/v2/webhook-diagnostic/'.$challenge]);
    check($status === 200, 'live webhook creation form');
    $webhook = DB::table('api_webhook_subscriptions')->where('name', $name)->first();
    check((bool) $webhook, 'webhook settings persisted');
    $webhookId = $webhook->id;
    Cache::put('apiwebhooks:diagnostic:'.$challenge, $webhook->secret, 5);
    list($status) = $post('webhooks/'.$webhookId.'/test');
    check($status === 200, 'live webhook Test button');
    app(Modules\ApiWebhooks\Services\DeliveryWorker::class)->run();
    $delivery = DB::table('api_deliveries')->where('kind', 'webhook')->where('destination_id', $webhookId)->where('payload', json_encode(['event' => 'api.test']))->first();
    check($delivery && $delivery->status_code === 204, 'admin Test sends a real verified HTTPS webhook');
    list($status) = $post('webhooks/'.$webhookId.'/disable');
    check($status === 200 && !DB::table('api_webhook_subscriptions')->where('id', $webhookId)->value('active'), 'live webhook Disable button');
    $name = 'ticketapi-2 saved reply '.bin2hex(random_bytes(5));
    list($status) = $post('saved-replies', ['name' => $name, 'body' => 'A saved mobile reply regression fixture.', 'user_id' => $actor->id]);
    check($status === 200, 'live saved reply creation form');
    $savedReplyId = DB::table('api_saved_replies')->where('name', $name)->value('id');
    list($device, $devicePlain) = DeviceToken::issue($actor, 'ticketapi-2 saved replies UI', 'root');
    $response = $client->get('api/v2/saved-replies', ['headers' => ['Authorization' => 'Bearer '.$devicePlain, 'Accept' => 'application/json']]);
    $data = json_decode($response->getBody()->getContents(), true);
    check(collect($data['data'])->contains('id', $savedReplyId), 'new saved reply visible through live agent API');
    list($status) = $post('saved-replies/'.$savedReplyId.'/delete');
    check($status === 200 && !DB::table('api_saved_replies')->where('id', $savedReplyId)->exists(), 'live saved reply Delete button');
    foreach (['apns', 'fcm'] as $provider) {
        list($status, $html) = $post('push/test', ['provider' => $provider]);
        check($status === 200, 'live '.$provider.' Test action returns a visible result');
    }
    $key2After = DB::table('api_keys')->where('id', 2)->first(['scopes', 'allowed_ips']);
    check(json_encode($key2Before) === json_encode($key2After), 'key 2 scopes and allowlist remain exactly unchanged');
    echo 'PASS total '.$checks.PHP_EOL;
} finally {
    Cache::forget('apiwebhooks:diagnostic:'.$challenge);
    Cache::forget('apiwebhooks:diagnostic-receipt:'.$challenge);
    if ($server) {
        DB::table('api_nonces')->where('token_id', $server->id)->delete();
        $server->delete();
    }
    if ($device) { $device->delete(); }
    if ($webhookId) {
        DB::table('api_deliveries')->where('kind', 'webhook')->where('destination_id', $webhookId)->delete();
        DB::table('api_webhook_subscriptions')->where('id', $webhookId)->delete();
    }
    if ($savedReplyId) { DB::table('api_saved_replies')->where('id', $savedReplyId)->delete(); }
}
