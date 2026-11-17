<?php

require __DIR__.'/bootstrap.php';

use Modules\ApiWebhooks\Models\DeviceToken;
use Modules\ApiWebhooks\Http\Middleware\MobileAuth;
use Illuminate\Support\Facades\DB;

$tokens = [];
try {
    $admin = App\User::where('role', App\User::ROLE_ADMIN)->where('status', App\User::STATUS_ACTIVE)->firstOrFail();
    list($device, $plain) = DeviceToken::issue($admin, 'ticketapi-2 HTTP test', 'broad');
    $tokens[] = $device;
    $client = new GuzzleHttp\Client(['base_uri' => rtrim(config('app.url'), '/').'/', 'http_errors' => false]);
    $headers = ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];
    check($device->token_hash === hash('sha256', $plain), 'device credential hashed');
    $response = $client->get('api/v2/me');
    check($response->getStatusCode() === 401, 'v2 unauthenticated HTTP rejected');
    foreach (['me', 'tokens', 'mailboxes', 'conversations', 'customers', 'users', 'notifications', 'notification-settings', 'sync'] as $path) {
        $response = $client->get('api/v2/'.$path, ['headers' => $headers]);
        check($response->getStatusCode() === 200, 'v2 live GET '.$path.' status '.$response->getStatusCode());
    }
    $conversation = App\Conversation::where('state', App\Conversation::STATE_PUBLISHED)->first();
    if ($conversation) {
        foreach (['', '/threads', '/drafts'] as $suffix) {
            $response = $client->get('api/v2/conversations/'.$conversation->id.$suffix, ['headers' => $headers]);
            check($response->getStatusCode() === 200, 'v2 live conversation '.$suffix);
        }
    }
    list($server, $serverPlain) = DeviceToken::issue($admin, 'ticketapi-2 signed test', 'root', 'server', '51.81.93.236');
    $tokens[] = $server;
    $nonce = bin2hex(random_bytes(16));
    $timestamp = (string) time();
    $signed = ['X-Api-Key' => $serverPlain, 'X-Api-Timestamp' => $timestamp, 'X-Api-Nonce' => $nonce, 'Accept' => 'application/json'];
    $canonical = implode("\n", ['GET', '/api/v2/me', $timestamp, $nonce, hash('sha256', '')]);
    $signed['X-Api-Signature'] = hash_hmac('sha256', $canonical, $serverPlain);
    $response = $client->get('api/v2/me', ['headers' => $signed]);
    check($response->getStatusCode() === 200, 'signed HMAC live request accepted');
    $response = $client->get('api/v2/me', ['headers' => $signed]);
    check($response->getStatusCode() === 401, 'reused nonce rejected');
    $response = $client->get('api/v2/me?changed=1', ['headers' => $signed]);
    check($response->getStatusCode() === 401, 'query tampering rejected');
    $server->allowed_ips = '192.0.2.1';
    $server->save();
    $signed['X-Api-Nonce'] = bin2hex(random_bytes(16));
    $response = $client->get('api/v2/me', ['headers' => $signed]);
    check($response->getStatusCode() === 403, 'server IP allowlist enforced');
    $device->revoked_at = now();
    $device->save();
    $response = $client->get('api/v2/me', ['headers' => $headers]);
    check($response->getStatusCode() === 401, 'revoked device rejected');
    $previous = DB::table('api_audit_chain')->where('id', 1)->value('anchor_hash');
    $linksValid = true;
    $hashesValid = true;
    foreach (DB::table('api_audit_entries')->orderBy('id')->cursor() as $entry) {
        $linksValid = $linksValid && $entry->previous_hash === $previous;
        $hashesValid = $hashesValid && hash_equals($entry->entry_hash, hash_hmac('sha256', $previous."\n".$entry->metadata, config('app.key')));
        $previous = $entry->entry_hash;
    }
    check($linksValid, 'all retained audit links verified');
    check($hashesValid, 'all retained audit hashes verified');
    check($previous === DB::table('api_audit_chain')->where('id', 1)->value('tail_hash'), 'audit tail matches');
    echo 'PASS total '.$checks.PHP_EOL;
} finally {
    foreach ($tokens as $token) {
        DB::table('api_nonces')->where('token_id', $token->id)->delete();
        app(Illuminate\Cache\RateLimiter::class)->clear('apiwebhooks:v2:'.$token->id);
        $token->delete();
    }
}
