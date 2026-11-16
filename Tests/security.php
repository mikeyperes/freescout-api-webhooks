<?php

// Run as the FreeScout account: php Modules/ApiWebhooks/Tests/security.php
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\ApiWebhooks\Models\ApiKey;
use Modules\ApiWebhooks\Models\ApiLog;
use Modules\ApiWebhooks\Http\Middleware\ApiAuth;

$checks = 0;
function check($condition, $message)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
    echo 'PASS '.$message.PHP_EOL;
}

$key = null;
try {
    $raw = ApiKey::generateKey();
    $key = ApiKey::create([
        'name' => 'ticketapi-2 temporary security test',
        'api_key' => $raw,
        'api_secret' => hash('sha256', ApiKey::generateSecret()),
        'scopes' => ['read', 'users:write'],
        'active' => true,
        'allowed_ips' => '51.81.93.236',
        'created_by_user_id' => App\User::where('role', App\User::ROLE_ADMIN)->value('id'),
    ]);
    check($key->api_key === hash('sha256', $raw), 'key stored as SHA-256');
    check(!array_key_exists('api_key', $key->toArray()), 'digest excluded from serialization');
    check(!$key->hasScope('admin'), 'admin authority requires explicit scope');
    check(ApiAuth::requiredScope('deleteConversation', 'DELETE') === 'conversations:delete', 'delete has independent scope');
    check(ApiAuth::requiredScope('sendTestEmail', 'POST') === 'admin', 'test email requires admin scope');
    check(!$key->isIpAllowed('209.42.35.47'), 'unlisted IP denied');
    check(ApiKey::ipInCidr('192.0.2.5', '192.0.2.0/24'), 'valid CIDR allowed');
    check(!ApiKey::ipInCidr('192.0.2.5', '192.0.2.0/99'), 'invalid CIDR denied');
    $controller = new \Modules\ApiWebhooks\Http\Controllers\ApiKeysController();
    $guard = $controller->getMiddleware()[0]['middleware'];
    $nonAdmin = new App\User();
    $nonAdmin->role = App\User::ROLE_USER;
    auth()->setUser($nonAdmin);
    try {
        $guard(Illuminate\Http\Request::create('/manage/api-webhooks'), function () { return true; });
        throw new RuntimeException('non-admin management access allowed');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        check($exception->getStatusCode() === 403, 'management rejects non-admin session');
    } finally {
        auth()->setUser(App\User::findOrFail($key->created_by_user_id));
    }
    $client = new GuzzleHttp\Client(['base_uri' => rtrim(config('app.url'), '/').'/', 'http_errors' => false]);
    $response = $client->get('api/v1/mailboxes');
    check($response->getStatusCode() === 401, 'live HTTP rejects missing key');
    $headers = ['X-Api-Key' => $raw, 'Accept' => 'application/json'];
    foreach (['mailboxes', 'users', 'customers', 'conversations', 'emails'] as $resource) {
        $response = $client->get('api/v1/'.$resource, ['headers' => $headers]);
        check($response->getStatusCode() === 200, 'live HTTP GET '.$resource);
    }
    $response = $client->delete('api/v1/conversations/0', ['headers' => $headers]);
    check($response->getStatusCode() === 403, 'live HTTP read key cannot delete');
    $response = $client->post('api/v1/mailboxes/0/send-test', ['headers' => $headers]);
    check($response->getStatusCode() === 403, 'live HTTP non-admin cannot send test email');
    $response = $client->post('api/v1/users', ['headers' => $headers, 'json' => [
        'first_name' => 'Security', 'last_name' => 'Probe', 'email' => 'ticketapi-2-'.bin2hex(random_bytes(8)).'@example.invalid',
        'password' => bin2hex(random_bytes(16)), 'role' => 'admin',
    ]]);
    check($response->getStatusCode() === 403, 'live HTTP cannot create admin without admin scope');
    $request = Illuminate\Http\Request::create('/api/v1/users?password=secret', 'POST', ['nested' => ['password' => 'secret'], 'body' => 'private']);
    ApiLog::log($key->id, $request, 200, 'private response');
    $log = ApiLog::where('api_key_id', $key->id)->orderBy('id', 'desc')->first();
    check($log->request_body === null && $log->request_headers === null && $log->query_string === null, 'request content never logged');
    check($log->response_summary === 'HTTP 200', 'response content never logged');
    echo 'PASS total '.$checks.PHP_EOL;
} finally {
    if ($key) {
        ApiLog::where('api_key_id', $key->id)->delete();
        app(Illuminate\Cache\RateLimiter::class)->clear('apiwebhooks:v1:'.$key->id);
        $key->delete();
    }
}
