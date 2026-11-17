<?php

require __DIR__.'/bootstrap.php';

use Modules\ApiWebhooks\Models\DeviceToken;

$user = null;
try {
    $password = bin2hex(random_bytes(24));
    $user = new App\User();
    $user->first_name = 'API Login';
    $user->last_name = 'Regression';
    $user->email = 'ticketapi-2-'.bin2hex(random_bytes(8)).'@example.invalid';
    $user->password = bcrypt($password);
    $user->role = App\User::ROLE_USER;
    $user->status = App\User::STATUS_ACTIVE;
    $user->invite_state = App\User::INVITE_STATE_NOT_INVITED;
    $user->save();
    $client = new GuzzleHttp\Client(['base_uri' => rtrim(config('app.url'), '/').'/', 'http_errors' => false,
        'cookies' => new GuzzleHttp\Cookie\CookieJar()]);
    $challenge = $client->get('api/v2/login');
    $csrf = json_decode($challenge->getBody()->getContents(), true)['csrf_token'];
    $response = $client->post('api/v2/login', ['headers' => ['X-CSRF-TOKEN' => $csrf, 'Accept' => 'application/json'],
        'json' => ['email' => $user->email, 'password' => $password, 'name' => 'login regression', 'access_level' => 'own']]);
    $data = json_decode($response->getBody()->getContents(), true);
    check($response->getStatusCode() === 201 && isset($data['token']), 'real native password login returns device credential');
    $token = DeviceToken::where('user_id', $user->id)->firstOrFail();
    check(hash_equals($token->token_hash, hash('sha256', $data['token'])), 'login credential persisted only as hash');
    $headers = ['Authorization' => 'Bearer '.$data['token'], 'Accept' => 'application/json'];
    $response = $client->get('api/v2/me', ['headers' => $headers]);
    check($response->getStatusCode() === 200, 'new agent device authenticates');
    $response = $client->get('manage/api-webhooks/mobile', ['allow_redirects' => false]);
    check($response->getStatusCode() === 302 && strpos($response->getHeaderLine('Location'), '/login') !== false,
        'device issuance ends the ambient FreeScout web session');
    $challenge = $client->get('api/v2/login');
    $csrf = json_decode($challenge->getBody()->getContents(), true)['csrf_token'];
    $client->post('login', ['allow_redirects' => false, 'form_params' => ['_token' => $csrf,
        'email' => $user->email, 'password' => $password]]);
    $response = $client->get('manage/api-webhooks/mobile');
    check($response->getStatusCode() === 403, 'non-admin native session cannot manage mobile credentials');
    $user->status = App\User::STATUS_DISABLED;
    $user->save();
    $response = $client->get('api/v2/me', ['headers' => $headers]);
    check($response->getStatusCode() === 401, 'disabled native user invalidates existing device credential');
    echo 'PASS total '.$checks.PHP_EOL;
} finally {
    if ($user && $user->exists) {
        foreach (DeviceToken::where('user_id', $user->id)->get() as $token) {
            app(Illuminate\Cache\RateLimiter::class)->clear('apiwebhooks:v2:'.$token->id);
        }
        // Remove only this generated fixture and its native dependent rows. No real account is changed.
        DB::table('api_device_tokens')->where('user_id', $user->id)->delete();
        DB::table('subscriptions')->where('user_id', $user->id)->delete();
        DB::table('folders')->where('user_id', $user->id)->delete();
        DB::table('users')->where('id', $user->id)->where('email', $user->email)->delete();
    }
}
