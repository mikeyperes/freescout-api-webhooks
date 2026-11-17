<?php

require __DIR__.'/bootstrap.php';
require __DIR__.'/FixtureCleanup.php';

use Modules\ApiWebhooks\Models\DeviceToken;
use Modules\ApiWebhooks\Services\DeliveryWorker;
use Modules\ApiWebhooks\Services\OutboundEvents;
use Modules\ApiWebhooks\Services\PushTransport;

class RecordingPushTransport extends PushTransport
{
    public $sent = [];
    public $status = 200;
    public function send($device, array $payload)
    {
        $this->sent[] = $payload;
        return $this->status;
    }
}
$actor = App\User::findOrFail((int) ($argv[1] ?? 0));
$previousCustomerId = App\Email::where('email', $actor->email)->value('customer_id');
check($actor->isAdmin() && $actor->isActive(), 'explicit notification test administrator');
$conversation = null;
$subject = 'ticketapi-2 regression event '.bin2hex(random_bytes(6));
$subscriptions = [];
foreach ($actor->subscriptions()->get() as $subscription) {
    $subscriptions[$subscription->medium][] = $subscription->event;
}
$token = null;
$deviceId = null;
try {
    App\Subscription::saveFromArray([App\Subscription::MEDIUM_MOBILE => [App\Subscription::EVENT_CUSTOMER_REPLIED_TO_MY]], $actor->id);
    list($token, $plain) = DeviceToken::issue($actor, 'ticketapi-2 native event push', 'root');
    $client = new GuzzleHttp\Client(['base_uri' => rtrim(config('app.url'), '/').'/', 'http_errors' => false]);
    $response = $client->post('api/v2/conversations', ['headers' => ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'],
        'json' => ['mailbox_id' => $actor->mailboxesCanView()->first()->id, 'subject' => $subject,
            'body' => 'Native event regression fixture.', 'to' => [$actor->email]]]);
    check($response->getStatusCode() === 201, 'independent native event fixture created');
    $conversation = App\Conversation::where('subject', $subject)->firstOrFail();
    $deviceId = DB::table('api_push_devices')->insertGetId(['token_id' => $token->id, 'provider' => 'apns',
        'destination' => encrypt(str_repeat('a', 64)), 'created_at' => now(), 'updated_at' => now()]);
    $recording = new RecordingPushTransport();
    app()->instance(PushTransport::class, $recording);
    $conversation->changeStatus(App\Conversation::STATUS_ACTIVE, $actor);
    $thread = App\Thread::create($conversation, App\Thread::TYPE_CUSTOMER, 'Native notification regression fixture.', [
        'customer_id' => $conversation->customer_id, 'created_by_customer_id' => $conversation->customer_id,
        'state' => App\Thread::STATE_PUBLISHED, 'source_via' => App\Thread::PERSON_CUSTOMER,
        'source_type' => App\Thread::SOURCE_TYPE_EMAIL,
    ]);
    event(new App\Events\CustomerReplied($conversation, $thread));
    App\Subscription::processEvents();
    $delivery = DB::table('api_deliveries')->where('kind', 'push')->where('destination_id', $deviceId)->first();
    check((bool) $delivery, 'native customer-reply subscription event queues push');
    \Eventy::action('apiwebhooks.process_deliveries');
    check(count($recording->sent) === 1, 'native selected recipient reaches push transport');
    check($recording->sent[0]['conversation_id'] === $conversation->id, 'push identifies its permitted conversation');
    $recording->status = 503;
    app(OutboundEvents::class)->push([App\Subscription::MEDIUM_MOBILE => [[
        'conversation' => $conversation, 'users' => [$actor],
    ]]]);
    app(DeliveryWorker::class)->run();
    $retry = DB::table('api_deliveries')->where('kind', 'push')->where('destination_id', $deviceId)->orderBy('id', 'desc')->first();
    check($retry->attempts == 1 && $retry->status_code == 503 && $retry->next_attempt_at && !$retry->delivered_at, 'failed delivery schedules a retry');
    DB::table('api_deliveries')->where('id', $retry->id)->update(['next_attempt_at' => now()]);
    $recording->status = 200;
    app(DeliveryWorker::class)->run();
    $retried = DB::table('api_deliveries')->where('id', $retry->id)->first();
    check($retried->attempts == 2 && $retried->delivered_at && !$retried->claimed_at, 'retried delivery succeeds and releases its claim');
    App\Subscription::saveFromArray([App\Subscription::MEDIUM_MOBILE => [OutboundEvents::EVENT_MENTION]], $actor->id);
    check((int) $actor->subscriptions()->where('medium', App\Subscription::MEDIUM_MOBILE)->value('event') === OutboundEvents::EVENT_MENTION, 'mention preference fits the native event column');
    $response = $client->post('api/v2/conversations/'.$conversation->id.'/notes', [
        'headers' => ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'],
        'json' => ['body' => 'Explicit mention regression.', 'mentions' => [$actor->id]],
    ]);
    check($response->getStatusCode() === 200, 'note action accepts explicit mentions');
    app(DeliveryWorker::class)->run();
    check(count($recording->sent) === 4 && $recording->sent[3]['event'] === 'conversation.mention', 'explicit note mentions reach the selected push recipient');
    App\Subscription::saveFromArray([App\Subscription::MEDIUM_MOBILE => [App\Subscription::EVENT_CUSTOMER_REPLIED_TO_MY]], $actor->id);
    app(OutboundEvents::class)->push([App\Subscription::MEDIUM_MOBILE => [[
        'conversation' => $conversation, 'users' => [$actor],
    ]]]);
    App\Subscription::saveFromArray([], $actor->id);
    app(DeliveryWorker::class)->run();
    check(count($recording->sent) === 4, 'changed notification preferences suppress queued push');
    App\Subscription::saveFromArray([App\Subscription::MEDIUM_MOBILE => [App\Subscription::EVENT_CUSTOMER_REPLIED_TO_MY]], $actor->id);
    app(OutboundEvents::class)->push([App\Subscription::MEDIUM_MOBILE => [[
        'conversation' => $conversation, 'users' => [$actor],
    ]]]);
    $token->revoked_at = now();
    $token->save();
    app(DeliveryWorker::class)->run();
    check(count($recording->sent) === 4, 'revoked device suppresses queued push');
    echo 'PASS total '.$checks.PHP_EOL;
} finally {
    App\Subscription::saveFromArray($subscriptions, $actor->id);
    if ($deviceId) {
        DB::table('api_deliveries')->where('kind', 'push')->where('destination_id', $deviceId)->delete();
        DB::table('api_push_devices')->where('id', $deviceId)->delete();
    }
    if ($token) { $token->delete(); }
    FixtureCleanup::conversations(App\Conversation::where('subject', $subject)->pluck('id')->all(), $actor->id);
    FixtureCleanup::actorCustomer($actor, $previousCustomerId);
}
