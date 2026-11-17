<?php

require __DIR__.'/bootstrap.php';

use Illuminate\Support\Facades\DB;
use Modules\ApiWebhooks\Services\WebhookTransport;
use Modules\ApiWebhooks\Services\OutboundEvents;
use Modules\ApiWebhooks\Services\DeliveryWorker;
use Modules\ApiWebhooks\Services\PushTransport;

class FixturePushTransport extends PushTransport
{
    public $fixtures = [];
    protected function credential($name) { return json_encode($this->fixtures[$name]); }
}
$transport = new WebhookTransport();
foreach (['http://example.com', 'https://127.0.0.1', 'https://169.254.169.254', 'https://100.64.0.1',
    'https://[::1]', 'https://example.com:8443', 'https://user:pass@example.com'] as $url) {
    try {
        $transport->destination($url);
        throw new RuntimeException('unsafe destination accepted');
    } catch (InvalidArgumentException $exception) {
        check(true, 'unsafe webhook destination rejected '.$url);
    }
}
$secret = bin2hex(random_bytes(32));
$challenge = bin2hex(random_bytes(32));
$id = null;
try {
    Cache::put('apiwebhooks:diagnostic:'.$challenge, encrypt($secret), 5);
    $id = DB::table('api_webhook_subscriptions')->insertGetId([
        'name' => 'ticketapi-2 temporary loopback signature test',
        'url' => rtrim(config('app.url'), '/').'/api/v2/webhook-diagnostic/'.$challenge,
        'secret' => encrypt($secret), 'active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(OutboundEvents::class)->enqueue('webhook', $id, ['event' => 'api.test']);
    app(DeliveryWorker::class)->run();
    $delivery = DB::table('api_deliveries')->where('kind', 'webhook')->where('destination_id', $id)->first();
    check($delivery && $delivery->status_code === 204 && $delivery->delivered_at !== null, 'real HTTPS signed webhook delivered');
    check(Cache::get('apiwebhooks:diagnostic-receipt:'.$challenge) === $delivery->event_id, 'live receiver verified HMAC and event ID');
} finally {
    Cache::forget('apiwebhooks:diagnostic:'.$challenge);
    Cache::forget('apiwebhooks:diagnostic-receipt:'.$challenge);
    if ($id) {
        DB::table('api_deliveries')->where('kind', 'webhook')->where('destination_id', $id)->delete();
        DB::table('api_webhook_subscriptions')->where('id', $id)->delete();
    }
}
$ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
openssl_pkey_export($ec, $ecPrivate);
$rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
openssl_pkey_export($rsa, $rsaPrivate);
$history = [];
$stack = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([
    new GuzzleHttp\Psr7\Response(200),
    new GuzzleHttp\Psr7\Response(200, [], json_encode(['access_token' => 'fixture-access-token'])),
    new GuzzleHttp\Psr7\Response(200),
]));
$stack->push(GuzzleHttp\Middleware::history($history));
$push = new FixturePushTransport(new GuzzleHttp\Client(['handler' => $stack]));
$push->fixtures = [
    'apns_credentials' => ['team_id' => 'TESTTEAM', 'key_id' => 'TESTKEY', 'topic' => 'test.mobile', 'private_key' => $ecPrivate],
    'fcm_credentials' => ['client_email' => 'test@example.invalid', 'project_id' => 'test-project', 'private_key' => $rsaPrivate],
];
check($push->send((object) ['provider' => 'apns', 'destination' => encrypt(str_repeat('a', 64))], ['event' => 'api.test']) === 200, 'APNs transport builds signed request');
$authorization = $history[0]['request']->getHeaderLine('authorization');
$jwt = explode('.', substr($authorization, 7));
$decode = function ($value) { return base64_decode(strtr($value, '-_', '+/')); };
check(count($jwt) === 3 && strlen($decode($jwt[2])) === 64, 'APNs ES256 JOSE signature has correct width');
check(json_decode($decode($jwt[0]), true)['alg'] === 'ES256', 'APNs JWT algorithm');
check($history[0]['request']->getHeaderLine('apns-topic') === 'test.mobile', 'APNs topic header');
check($push->send((object) ['provider' => 'fcm', 'destination' => encrypt('fixture-device')], ['event' => 'api.test', 'conversation_id' => 1]) === 200, 'FCM OAuth and message transport');
parse_str($history[1]['request']->getBody()->getContents(), $oauth);
$jwt = explode('.', $oauth['assertion']);
check(openssl_verify($jwt[0].'.'.$jwt[1], $decode($jwt[2]), openssl_pkey_get_details($rsa)['key'], OPENSSL_ALGO_SHA256) === 1, 'FCM RS256 assertion cryptographically verified');
$sent = json_decode($history[2]['request']->getBody()->getContents(), true);
check($sent['message']['data']['conversation_id'] === '1', 'FCM data values serialized as strings');
echo 'PASS total '.$checks.PHP_EOL;
