<?php

namespace Modules\ApiWebhooks\Services;

use App\Subscription;
use Illuminate\Support\Facades\DB;
use Modules\ApiWebhooks\Models\DeviceToken;

class OutboundEvents
{
    const EVENT_MENTION = 1001;

    public function webhook($event)
    {
        if (empty($event->conversation)) {
            return;
        }
        $payload = ['event' => class_basename($event), 'conversation_id' => $event->conversation->id];
        foreach (DB::table('api_webhook_subscriptions')->where('active', true)->get(['id']) as $destination) {
            $this->enqueue('webhook', $destination->id, $payload);
        }
    }

    public function push(array $notify)
    {
        foreach ($notify[Subscription::MEDIUM_MOBILE] ?? [] as $notification) {
            foreach ($notification['users'] as $user) {
                $this->pushToUser($user, $notification['conversation'], 'conversation.updated');
            }
        }
    }

    public function mentioned($event)
    {
        $request = request();
        if (!$request->attributes->get('device_token') || !$request->is('api/v2/conversations/*/notes')
            || $event->thread->created_by_user_id != $request->user()->id) {
            return;
        }
        foreach ($request->input('mentions', []) as $id) {
            $user = \App\User::find($id);
            if ($user && $user->subscriptions()->where('medium', Subscription::MEDIUM_MOBILE)->where('event', self::EVENT_MENTION)->exists()) {
                $this->pushToUser($user, $event->conversation, 'conversation.mention');
            }
        }
    }

    protected function pushToUser($user, $conversation, $event)
    {
        if (!$user->isActive() || !$user->can('view', $conversation)) {
            return;
        }
        $tokens = DeviceToken::where('user_id', $user->id)->where('kind', 'device')
            ->whereNull('revoked_at')->where('expires_at', '>', now())->get();
        foreach ($tokens as $token) {
            if ($token->access_level === 'own' && $conversation->user_id != $user->id) {
                continue;
            }
            $device = DB::table('api_push_devices')->where('token_id', $token->id)->first();
            if ($device) {
                $this->enqueue('push', $device->id, ['event' => $event, 'conversation_id' => $conversation->id,
                    'preferences_hash' => self::preferencesHash($user)]);
            }
        }
    }

    public function enqueue($kind, $destination, array $payload)
    {
        DB::table('api_deliveries')->insert([
            'event_id' => (string) \Ramsey\Uuid\Uuid::uuid4(),
            'kind' => $kind,
            'destination_id' => $destination,
            'payload' => json_encode($payload),
            'attempts' => 0,
            'next_attempt_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function preferencesHash($user)
    {
        return hash('sha256', $user->subscriptions()->where('medium', Subscription::MEDIUM_MOBILE)->orderBy('event')->pluck('event')->toJson());
    }
}
