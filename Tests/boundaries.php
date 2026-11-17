<?php

require __DIR__.'/bootstrap.php';

use Modules\ApiWebhooks\Models\DeviceToken;
use Modules\ApiWebhooks\Http\Middleware\MobileAuth;

$admin = App\User::findOrFail((int) ($argv[1] ?? 0));
check($admin->isAdmin() && $admin->isActive(), 'explicit administrator fixture');
$tokens = [];
try {
    list($device, $plain) = DeviceToken::issue($admin, 'ticketapi-2 boundaries', 'root');
    $tokens[] = $device;
    $client = new GuzzleHttp\Client(['base_uri' => rtrim(config('app.url'), '/').'/', 'http_errors' => false]);
    $bearer = ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];
    $response = $client->get('api/v2/tokens', ['headers' => $bearer]);
    $body = $response->getBody()->getContents();
    check(strpos($body, $plain) === false && strpos($body, 'token_hash') === false, 'token inventory contains no credentials or digests');
    foreach (app('router')->getRoutes() as $route) {
        if (strpos($route->uri(), 'api/v2/') !== 0 || !in_array(MobileAuth::class, $route->gatherMiddleware(), true)) { continue; }
        $path = preg_replace('/\{[^}]+\}/', '0', $route->uri());
        $method = $route->methods()[0];
        $response = $client->request($method, $path, ['headers' => ['Accept' => 'application/json']]);
        check($response->getStatusCode() === 401, 'authentication required '.$method.' '.$route->uri());
    }
    $response = $client->get('api/v2/conversations?per_page=0', ['headers' => $bearer]);
    check($response->getStatusCode() === 422, 'invalid page size rejected');
    $response = $client->get('api/v2/conversations?per_page=101', ['headers' => $bearer]);
    check($response->getStatusCode() === 422, 'page size capped');
    $response = $client->get('api/v2/sync?per_page=1', ['headers' => $bearer]);
    $sync = json_decode($response->getBody()->getContents(), true);
    check($response->getStatusCode() === 200 && !empty($sync['next_cursor']), 'sync produces a paged cursor');
    list($other, $otherPlain) = DeviceToken::issue($admin, 'ticketapi-2 cursor isolation', 'root');
    $tokens[] = $other;
    $response = $client->get('api/v2/sync', ['headers' => ['Authorization' => 'Bearer '.$otherPlain, 'Accept' => 'application/json'], 'query' => ['cursor' => $sync['next_cursor']]]);
    check($response->getStatusCode() === 422, 'sync cursor bound to its credential');
    list($server, $serverPlain) = DeviceToken::issue($admin, 'ticketapi-2 signature boundaries', 'root', 'server', '51.81.93.236');
    $tokens[] = $server;
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $body = json_encode(['body' => 'Signature body regression.']);
    $signed = ['X-Api-Key' => $serverPlain, 'X-Api-Timestamp' => $timestamp, 'X-Api-Nonce' => $nonce,
        'Content-Type' => 'application/json', 'Accept' => 'application/json'];
    $signed['X-Api-Signature'] = hash_hmac('sha256', implode("\n", ['POST', '/api/v2/conversations/0/notes', $timestamp, $nonce, hash('sha256', $body)]), $serverPlain);
    $response = $client->post('api/v2/conversations/0/notes', ['headers' => $signed, 'body' => $body]);
    check($response->getStatusCode() === 404, 'valid signed body reaches scoped resource lookup');
    $signed['X-Api-Nonce'] = bin2hex(random_bytes(16));
    $signed['X-Api-Signature'] = hash_hmac('sha256', implode("\n", ['POST', '/api/v2/conversations/0/notes', $timestamp, $signed['X-Api-Nonce'], hash('sha256', $body)]), $serverPlain);
    $response = $client->post('api/v2/conversations/0/notes', ['headers' => $signed, 'body' => json_encode(['body' => 'Tampered body.'])]);
    check($response->getStatusCode() === 401, 'signed body tampering rejected');
    foreach ([-301, 60] as $offset) {
        $timestamp = (string) (time() + $offset);
        $nonce = bin2hex(random_bytes(16));
        $headers = ['X-Api-Key' => $serverPlain, 'X-Api-Timestamp' => $timestamp, 'X-Api-Nonce' => $nonce,
            'X-Api-Signature' => hash_hmac('sha256', implode("\n", ['GET', '/api/v2/me', $timestamp, $nonce, hash('sha256', '')]), $serverPlain), 'Accept' => 'application/json'];
        $response = $client->get('api/v2/me', ['headers' => $headers]);
        check($response->getStatusCode() === 401, 'timestamp window rejects offset '.$offset);
    }
    $response = $client->get('api/v2/me', ['headers' => ['X-Api-Key' => $serverPlain, 'Accept' => 'application/json']]);
    check($response->getStatusCode() === 401, 'unsigned server key rejected');
    $response = $client->get('api/v2/me', ['headers' => ['Authorization' => 'Bearer '.$serverPlain, 'Accept' => 'application/json']]);
    check($response->getStatusCode() === 401, 'server key cannot masquerade as device token');
    $response = $client->get('api/v2/me', ['headers' => ['X-Api-Key' => $plain, 'Accept' => 'application/json']]);
    check($response->getStatusCode() === 401, 'device token cannot masquerade as server key');
    $limiter = app(Illuminate\Cache\RateLimiter::class);
    $limit = Modules\ApiWebhooks\Services\ApiSettings::value('requests_per_minute');
    for ($attempt = $limiter->attempts('apiwebhooks:v2:'.$device->id); $attempt < $limit; ++$attempt) {
        $limiter->hit('apiwebhooks:v2:'.$device->id, 1);
    }
    $response = $client->get('api/v2/me', ['headers' => $bearer]);
    check($response->getStatusCode() === 429 && $response->hasHeader('Retry-After'), 'per-token cache limiter enforced');
    $response = $client->get('api/v2/me', ['headers' => ['Authorization' => 'Bearer '.$otherPlain, 'Accept' => 'application/json']]);
    check($response->getStatusCode() === 200, 'another token has an independent rate bucket');
    $agent = App\User::where('role', App\User::ROLE_USER)->where('status', App\User::STATUS_ACTIVE)->firstOrFail();
    try {
        DeviceToken::issue($agent, 'forbidden root', 'root');
        throw new RuntimeException('non-admin root credential was issued');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        check($exception->getStatusCode() === 403, 'non-admin cannot obtain root access');
    }
    list($broad, $broadPlain) = DeviceToken::issue($agent, 'ticketapi-2 broad policy', 'broad');
    $tokens[] = $broad;
    $response = $client->get('api/v2/conversations', ['headers' => ['Authorization' => 'Bearer '.$broadPlain, 'Accept' => 'application/json']]);
    $data = json_decode($response->getBody()->getContents(), true);
    check($response->getStatusCode() === 200, 'broad listing available');
    auth()->setUser($agent);
    foreach ($data['data'] as $row) {
        check($agent->can('view', App\Conversation::findOrFail($row['id'])), 'broad result passes native permission policy');
    }
    $inaccessible = App\Conversation::where('user_id', '<>', $agent->id)->get()->first(function ($conversation) use ($agent) {
        return !$agent->can('view', $conversation);
    });
    check((bool) $inaccessible, 'inaccessible conversation fixture found');
    foreach (['', '/threads', '/drafts'] as $suffix) {
        $response = $client->get('api/v2/conversations/'.$inaccessible->id.$suffix,
            ['headers' => ['Authorization' => 'Bearer '.$broadPlain, 'Accept' => 'application/json']]);
        check($response->getStatusCode() === 404, 'broad token cannot bypass native restrictions '.$suffix);
    }
    $insecure = Illuminate\Http\Request::create('http://example.invalid/api/v2/me', 'GET');
    try {
        app(MobileAuth::class)->handle($insecure, function () { return response()->json([]); });
        throw new RuntimeException('HTTP authentication was allowed');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        check($exception->getStatusCode() === 403, 'HTTPS required before credential processing');
    }
    echo 'PASS total '.$checks.PHP_EOL;
} finally {
    foreach ($tokens as $token) {
        DB::table('api_nonces')->where('token_id', $token->id)->delete();
        app(Illuminate\Cache\RateLimiter::class)->clear('apiwebhooks:v2:'.$token->id);
        $token->delete();
    }
}
