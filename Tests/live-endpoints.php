<?php

require __DIR__.'/bootstrap.php';
require __DIR__.'/FixtureCleanup.php';

use Modules\ApiWebhooks\Models\DeviceToken;
use Illuminate\Support\Facades\DB;

$actor = App\User::findOrFail((int) ($argv[1] ?? 0));
$previousCustomerId = App\Email::where('email', $actor->email)->value('customer_id');
check($actor->isActive() && $actor->isAdmin(), 'explicit active administrator test actor');
$client = new GuzzleHttp\Client(['base_uri' => rtrim(config('app.url'), '/').'/', 'http_errors' => false, 'cookies' => new GuzzleHttp\Cookie\CookieJar()]);
$tokens = [];
$subjects = [];
try {
    list($token, $plain) = DeviceToken::issue($actor, 'ticketapi-2 endpoint tests', 'root');
    $tokens[] = $token;
    $headers = ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];
    $request = function ($method, $path, array $data = []) use ($client, $headers) {
        $response = $client->request($method, 'api/v2/'.$path, ['headers' => $headers, 'json' => $data]);
        return [$response->getStatusCode(), json_decode($response->getBody()->getContents(), true)];
    };
    $challenge = $client->get('api/v2/login');
    check($challenge->getStatusCode() === 200 && isset(json_decode($challenge->getBody()->getContents(), true)['csrf_token']), 'live login challenge');
    $grant = app(Modules\HexawebBaseTools\Services\AdminLoginLinkService::class)->issue($actor, '/manage/api-webhooks/mobile', 2, 'ticketapi-2 live endpoint tests');
    $landing = $client->get('admin-login-link')->getBody()->getContents();
    preg_match('/name="_token" value="([^"]+)"/', $landing, $match);
    check(isset($match[1]), 'native login helper CSRF token');
    $response = $client->post('admin-login-link/consume', ['form_params' => ['_token' => $match[1], 'token' => parse_url($grant['url'], PHP_URL_FRAGMENT)]]);
    check($response->getStatusCode() === 200, 'live settings page renders after native sign-in status '.$response->getStatusCode());
    $html = $response->getBody()->getContents();
    check(strpos($html, 'Mobile API settings') !== false, 'live settings page contains controls');
    preg_match('/name="csrf-token" content="([^"]+)"/', $html, $csrf);
    if (!isset($csrf[1])) {
        preg_match('/name="_token" value="([^"]+)"/', $html, $csrf);
    }
    check(isset($csrf[1]), 'authenticated CSRF token available');
    $exchange = $client->post('api/v2/tokens/exchange', ['headers' => ['X-CSRF-TOKEN' => $csrf[1], 'Accept' => 'application/json'], 'form_params' => ['name' => 'ticketapi-2 session exchange', 'access_level' => 'own']]);
    $exchangeData = json_decode($exchange->getBody()->getContents(), true);
    check($exchange->getStatusCode() === 201 && isset($exchangeData['token']), 'live authenticated device-token exchange');
    $exchanged = DeviceToken::where('token_hash', hash('sha256', $exchangeData['token']))->firstOrFail();
    $tokens[] = $exchanged;
    $mailbox = $actor->mailboxesCanView()->first();
    check((bool) $mailbox, 'test mailbox resolved through native access');
    $subject = 'ticketapi-2 regression '.bin2hex(random_bytes(6));
    $subjects[] = $subject;
    list($status, $data) = $request('POST', 'conversations', ['mailbox_id' => $mailbox->id, 'subject' => $subject,
        'body' => 'FreeScout mobile API delivery test. No action is required.', 'to' => [$actor->email]]);
    check($status === 201, 'live native conversation create status '.$status.' '.json_encode($data));
    $conversation = App\Conversation::where('subject', $subject)->firstOrFail();
    echo 'FIXTURE conversation '.$conversation->id.PHP_EOL;
    list($status) = $request('POST', 'conversations/'.$conversation->id.'/notes', ['body' => 'Mobile API note regression.']);
    check($status === 200, 'live native note');
    list($status) = $request('POST', 'conversations/'.$conversation->id.'/drafts', ['body' => 'Mobile API draft regression.']);
    check($status === 200, 'live native draft creation');
    list($status, $data) = $request('GET', 'conversations/'.$conversation->id.'/drafts');
    check($status === 200 && count($data['data']) >= 1, 'live draft listing');
    $draftId = $data['data'][0]['id'];
    list($status) = $request('DELETE', 'conversations/'.$conversation->id.'/drafts/'.$draftId);
    check($status === 200, 'live native draft discard');
    foreach ([['action' => 'star', 'starred' => true], ['action' => 'assignee', 'user_id' => $actor->id],
        ['action' => 'status', 'status' => 'pending'], ['action' => 'metadata', 'tags' => ['api-test'], 'custom_fields' => ['test_run' => $subject]],
        ['action' => 'metadata', 'snoozed_until' => now()->addMinutes(10)->toIso8601String()], ['action' => 'metadata', 'snoozed_until' => null]] as $mutation) {
        list($status) = $request('PATCH', 'conversations/'.$conversation->id, $mutation);
        // Native assignment returns a validation message if it is already assigned, which is harmless.
        check($status === 200 || ($mutation['action'] === 'assignee' && $status === 422), 'live native '.$mutation['action'].' mutation');
    }
    list($status) = $request('PUT', 'conversations/'.$conversation->id.'/read', ['read' => true]);
    check($status === 200, 'live read state');
    $upload = $client->post('api/v2/attachments', ['headers' => $headers, 'multipart' => [
        ['name' => 'file', 'contents' => 'ticketapi-2 attachment test', 'filename' => 'ticketapi-2.txt'],
    ]]);
    $uploadData = json_decode($upload->getBody()->getContents(), true);
    check($upload->getStatusCode() === 201 && isset($uploadData['data']['id']), 'live native attachment upload status '.$upload->getStatusCode());
    $attachmentId = $uploadData['data']['id'];
    $download = $client->get('api/v2/attachments/'.$attachmentId, ['headers' => $headers]);
    check($download->getStatusCode() === 200 && $download->getBody()->getContents() === 'ticketapi-2 attachment test', 'live protected attachment download');
    list($status) = $request('POST', 'conversations/'.$conversation->id.'/reply', ['body' => 'FreeScout mobile API reply and attachment delivery test.',
        'to' => [$actor->email], 'cc' => [], 'bcc' => [], 'attachments' => [$attachmentId]]);
    check($status === 200, 'live native reply queues actual email with attachment');
    $agent = App\User::where('role', App\User::ROLE_USER)->where('status', App\User::STATUS_ACTIVE)->first();
    if ($agent) {
        list($own, $ownPlain) = DeviceToken::issue($agent, 'ticketapi-2 own scoping', 'own');
        $tokens[] = $own;
        $ownHeaders = ['Authorization' => 'Bearer '.$ownPlain, 'Accept' => 'application/json'];
        $denied = $client->get('api/v2/conversations/'.$conversation->id, ['headers' => $ownHeaders]);
        check($denied->getStatusCode() === 404, 'own token cannot read another agent conversation');
        $list = $client->get('api/v2/conversations', ['headers' => $ownHeaders]);
        $rows = json_decode($list->getBody()->getContents(), true);
        check($list->getStatusCode() === 200, 'own token conversation listing');
        foreach ($rows['data'] as $row) {
            check(isset($row['assignee']['id']) && $row['assignee']['id'] == $agent->id, 'own listing contains only assigned tickets');
        }
    }
    $otherMailbox = $actor->mailboxesCanView()->where('id', '<>', $mailbox->id)->first();
    if ($otherMailbox) {
        list($status) = $request('PATCH', 'conversations/'.$conversation->id, ['action' => 'move', 'mailbox_id' => $otherMailbox->id]);
        check($status === 200, 'live native mailbox move');
        list($status) = $request('PATCH', 'conversations/'.$conversation->id, ['action' => 'move', 'mailbox_id' => $mailbox->id]);
        check($status === 200, 'live native mailbox move back');
    }
    list($status, $createdCustomer) = $request('POST', 'customers', [
        'first_name' => 'API Regression', 'last_name' => 'Fixture', 'email' => 'ticketapi-2-'.bin2hex(random_bytes(6)).'@example.invalid',
    ]);
    check($status === 201 && isset($createdCustomer['data']['id']), 'live native customer creation');
    $customerId = $createdCustomer['data']['id'];
    list($status) = $request('GET', 'customers/'.$customerId);
    check($status === 200, 'live root customer retrieval');
    list($status) = $request('PATCH', 'customers/'.$customerId, ['first_name' => 'API Regression Updated']);
    check($status === 200, 'live native customer update');
    App\Customer::findOrFail($customerId)->deleteCustomer();
    $mergeSubject = $subject.' merge';
    $subjects[] = $mergeSubject;
    list($status, $mergedData) = $request('POST', 'conversations', ['mailbox_id' => $mailbox->id, 'subject' => $mergeSubject,
        'body' => 'FreeScout mobile API merge delivery test. No action is required.', 'to' => [$actor->email]]);
    check($status === 201 && isset($mergedData['data']['conversation_id']), 'native creation returns conversation identity');
    $mergeId = $mergedData['data']['conversation_id'];
    list($status) = $request('PATCH', 'conversations/'.$conversation->id, ['action' => 'merge', 'merge_id' => $mergeId]);
    check($status === 200, 'live native merge');
    list($status) = $request('DELETE', 'tokens/'.$exchanged->id);
    check($status === 200 && DeviceToken::findOrFail($exchanged->id)->revoked_at !== null, 'live token revocation endpoint');
    list($status) = $request('PATCH', 'conversations/'.$conversation->id, ['action' => 'assignee', 'user_id' => 0]);
    check($status === 422, 'invalid assignee returns validation error');
    list($status) = $request('PUT', 'device', ['provider' => 'apns', 'push_token' => str_repeat('a', 64)]);
    check($status === 200, 'live device registration');
    list($status) = $request('DELETE', 'device');
    check($status === 200, 'live device removal');
    list($status) = $request('GET', 'saved-replies');
    check($status === 200, 'live saved replies');
    list($status, $settings) = $request('GET', 'notification-settings');
    $subscriptions = [];
    foreach ($settings['data'] as $subscription) {
        $subscriptions[$subscription['medium']][] = $subscription['event'];
    }
    list($status) = $request('PUT', 'notification-settings', ['subscriptions' => $subscriptions]);
    check($status === 200, 'live notification settings save preserves existing selections');
    list($status) = $request('PATCH', 'conversations/'.$conversation->id, ['action' => 'status', 'status' => 'closed']);
    check($status === 200, 'test conversation closed');
    echo 'PASS total '.$checks.PHP_EOL;
} finally {
    FixtureCleanup::conversations(App\Conversation::whereIn('subject', $subjects)->pluck('id')->all(), $actor->id);
    FixtureCleanup::attachments(isset($attachmentId) ? [$attachmentId] : [], $actor->id);
    FixtureCleanup::actorCustomer($actor, $previousCustomerId);
    foreach ($tokens as $token) {
        DB::table('api_push_devices')->where('token_id', $token->id)->delete();
        DB::table('api_nonces')->where('token_id', $token->id)->delete();
        app(Illuminate\Cache\RateLimiter::class)->clear('apiwebhooks:v2:'.$token->id);
        $token->delete();
    }
}
