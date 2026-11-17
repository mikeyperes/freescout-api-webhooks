<?php

namespace Modules\ApiWebhooks\Services;

use Illuminate\Support\Facades\DB;
use Modules\ApiWebhooks\Models\DeviceToken;

class DeliveryWorker
{
    public function run()
    {
        // Keep native queue jobs bounded even when remote providers are slow.
        $deadline = microtime(true) + ApiSettings::DELIVERY_DEADLINE_SECONDS;
        for ($batch = 0; $batch < ApiSettings::DELIVERY_BATCH_SIZE && microtime(true) < $deadline; ++$batch) {
            $delivery = DB::transaction(function () {
                $row = DB::table('api_deliveries')->whereNull('delivered_at')->where('attempts', '<', ApiSettings::DELIVERY_ATTEMPTS)
                    ->where('next_attempt_at', '<=', now())->where(function ($query) {
                        $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(ApiSettings::DELIVERY_CLAIM_MINUTES));
                    })->orderBy('id')->lockForUpdate()->first();
                if ($row) {
                    DB::table('api_deliveries')->where('id', $row->id)->update(['claimed_at' => now(), 'attempts' => $row->attempts + 1]);
                }
                return $row;
            });
            if (!$delivery) {
                return;
            }
            $status = 0;
            try {
                $status = $delivery->kind === 'webhook' ? $this->webhook($delivery) : $this->push($delivery);
            } catch (\Exception $exception) {
                // Never retain provider responses, credentials, tokens or request payloads in errors.
                $status = 0;
            }
            $successful = $status >= 200 && $status < 300;
            DB::table('api_deliveries')->where('id', $delivery->id)->update([
                'status_code' => $status,
                'claimed_at' => null,
                'delivered_at' => $successful ? now() : null,
                'next_attempt_at' => !$successful && $status !== 410 && $delivery->attempts + 1 < ApiSettings::DELIVERY_ATTEMPTS
                    ? now()->addSeconds(ApiSettings::DELIVERY_RETRY_SECONDS * pow(2, $delivery->attempts)) : null,
                'updated_at' => now(),
            ]);
        }
    }

    protected function webhook($delivery)
    {
        $subscription = DB::table('api_webhook_subscriptions')->where('id', $delivery->destination_id)->where('active', true)->first();
        return $subscription ? app(WebhookTransport::class)->send($subscription, $delivery) : 410;
    }

    protected function push($delivery)
    {
        $device = DB::table('api_push_devices')->where('id', $delivery->destination_id)->first();
        $token = $device ? DeviceToken::where('id', $device->token_id)->whereNull('revoked_at')->where('expires_at', '>', now())->first() : null;
        if (!$token || !$token->user || !$token->user->isActive()
            || ($token->access_level === 'root' && !$token->user->isAdmin())) {
            return 410;
        }
        $payload = json_decode($delivery->payload, true);
        $conversation = \App\Conversation::find($payload['conversation_id'] ?? null);
        if (!$conversation || !app(AgentAccess::class)->canSee($token, $conversation)) {
            return 410;
        }
        if (($payload['preferences_hash'] ?? '') !== OutboundEvents::preferencesHash($token->user)) {
            return 410;
        }
        unset($payload['preferences_hash']);
        return app(PushTransport::class)->send($device, $payload);
    }
}
