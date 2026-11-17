<?php

require __DIR__.'/bootstrap.php';
require __DIR__.'/FixtureCleanup.php';

use Modules\ApiWebhooks\Models\DeviceToken;
use Modules\ApiWebhooks\Services\AgentAccess;
use Modules\ApiWebhooks\Services\AgentSyncChanges;
use Modules\ApiWebhooks\Services\ConversationPresenter;

$actor = App\User::findOrFail((int) ($argv[1] ?? 0));
$previousCustomerId = App\Email::where('email', $actor->email)->value('customer_id');
check($actor->isActive() && $actor->isAdmin(), 'explicit review test administrator');
$subject = 'ticketapi-2 regression review '.bin2hex(random_bytes(6));
$token = null;
$transaction = false;
try {
    list($token, $plain) = DeviceToken::issue($actor, 'ticketapi-2 review', 'root');
    $client = new GuzzleHttp\Client(['base_uri' => rtrim(config('app.url'), '/').'/', 'http_errors' => false]);
    $headers = ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];
    $response = $client->post('api/v2/conversations', ['headers' => $headers, 'json' => [
        'subject' => $subject, 'mailbox_id' => $actor->mailboxesCanView()->first()->id,
        'body' => 'Review regression fixture.', 'to' => [$actor->email],
    ]]);
    check($response->getStatusCode() === 201, 'review fixture created through native endpoint');
    $base = App\Conversation::where('subject', $subject)->firstOrFail();
    $base->timestamps = false;
    $base->updated_at = now()->subDay();
    $base->save();
    $sharedTimestamp = $base->fresh()->updated_at->toDateTimeString();
    $response = $client->put('api/v2/conversations/'.$base->id.'/read', ['headers' => $headers, 'json' => ['read' => true]]);
    check($response->getStatusCode() === 200, 'read mutation accepted');
    check($base->fresh()->updated_at->toDateTimeString() === $sharedTimestamp, 'read does not touch shared conversation time');
    $response = $client->patch('api/v2/conversations/'.$base->id, ['headers' => $headers, 'json' => ['action' => 'star', 'starred' => true]]);
    check($response->getStatusCode() === 200, 'star mutation accepted');
    check($base->fresh()->updated_at->toDateTimeString() === $sharedTimestamp, 'star does not touch shared conversation time');
    check(DB::table('api_agent_sync_changes')->where('user_id', $actor->id)->where('conversation_id', $base->id)->exists(), 'per-user changes recorded separately');
    $other = App\User::where('role', App\User::ROLE_ADMIN)->where('id', '<>', $actor->id)->firstOrFail();
    $otherToken = new DeviceToken(['access_level' => 'root']);
    $otherToken->setRelation('user', $other);
    $access = app(AgentAccess::class);
    $otherRow = app(AgentSyncChanges::class)->query($access->forToken($otherToken), $other)->where('conversations.id', $base->id)->firstOrFail();
    check($otherRow->sync_updated_at === $sharedTimestamp, 'another agent sync time is unchanged');
    usleep(1100000);
    $response = $client->get('api/v2/sync', ['headers' => $headers, 'query' => ['since' => now()->subSeconds(10)->toIso8601String()]]);
    $sync = json_decode($response->getBody()->getContents(), true);
    check($response->getStatusCode() === 200 && collect($sync['data'])->contains('id', $base->id), 'live sync sees the requesting agent change');
    check(isset(collect($sync['data'])->firstWhere('id', $base->id)['syncUpdatedAt']), 'sync includes its per-agent checkpoint timestamp');
    $response = $client->get('api/v2/conversations', ['headers' => $headers, 'query' => ['sort' => 'id;drop table users']]);
    check($response->getStatusCode() === 422, 'sort parameter is allowlisted');

    DB::beginTransaction();
    $transaction = true;
    $ids = [];
    for ($index = 0; $index < 70; ++$index) {
        $conversation = $base->replicate();
        $conversation->timestamps = false;
        $conversation->subject = $subject.' page '.$index;
        $conversation->last_reply_at = now()->subMinutes(70 - $index);
        $conversation->updated_at = now()->subMinutes(70 - $index);
        $conversation->threads_count = 0;
        $conversation->save();
        $ids[] = $conversation->id;
    }
    auth()->setUser($actor);
    $request = Illuminate\Http\Request::create('/api/v2/conversations', 'GET', ['per_page' => 5, 'page' => 13]);
    $request->setUserResolver(function () use ($actor) { return $actor; });
    $request->attributes->set('device_token', $token);
    $policyChecks = 0;
    Gate::after(function ($user, $ability, $result, $arguments) use (&$policyChecks) {
        if ($ability === 'view' && isset($arguments[0]) && $arguments[0] instanceof App\Conversation) {
            ++$policyChecks;
        }
    });
    DB::enableQueryLog();
    DB::flushQueryLog();
    $page = $access->page($request, $access->query($request)->whereIn('conversations.id', $ids));
    $queries = DB::getQueryLog();
    check(count($page['data']) === 5 && $policyChecks === 5, 'deep page authorizes only its five returned rows');
    check(collect($queries)->contains(function ($query) { return strpos($query['query'], 'limit 6 offset 60') !== false; }), 'deep pagination is bounded in SQL');
    check($page['data'][0]['id'] === $ids[9], 'inbox defaults to newest activity first');
    foreach (array_slice($ids, 0, 25) as $id) {
        $customer = App\Customer::create('ticketapi-2-batch-'.bin2hex(random_bytes(8)).'@example.invalid', ['first_name' => 'Batch fixture']);
        App\Conversation::where('id', $id)->update(['customer_id' => $customer->id]);
    }
    $rows = App\Conversation::whereIn('id', $ids)->orderBy('id')->limit(25)->get();
    DB::flushQueryLog();
    ConversationPresenter::page($rows->take(1), $actor);
    $singleQueries = count(DB::getQueryLog());
    DB::flushQueryLog();
    ConversationPresenter::page($rows, $actor);
    check(count(DB::getQueryLog()) <= $singleQueries + 1, 'presentation query count stays constant for 25 distinct cold customers');
    $folder = $base->mailbox->getAssesibleFolders()->first();
    DB::flushQueryLog();
    $access->folderQuery($request, $folder)->count();
    $queries = DB::getQueryLog();
    check(collect($queries)->contains(function ($query) { return strpos($query['query'], 'select count(*) as aggregate') === 0; }), 'folder count uses SQL aggregation');
    $method = new ReflectionMethod(Modules\ApiWebhooks\Http\Controllers\MobileResourcesController::class, 'customerQuery');
    $method->setAccessible(true);
    $token->access_level = 'own';
    $customerQuery = $method->invoke(app(Modules\ApiWebhooks\Http\Controllers\MobileResourcesController::class), $request);
    check(strpos($customerQuery->toSql(), 'select `conversations`.`customer_id`') !== false, 'customer scoping uses a SQL subquery');
    DB::rollBack();
    $transaction = false;
    echo 'PASS total '.$checks.PHP_EOL;
} finally {
    DB::disableQueryLog();
    if ($transaction) {
        DB::rollBack();
    }
    FixtureCleanup::conversations(App\Conversation::where('subject', $subject)->pluck('id')->all(), $actor->id);
    FixtureCleanup::actorCustomer($actor, $previousCustomerId);
    if ($token) {
        app(Illuminate\Cache\RateLimiter::class)->clear('apiwebhooks:v2:'.$token->id);
        $token->delete();
    }
}
