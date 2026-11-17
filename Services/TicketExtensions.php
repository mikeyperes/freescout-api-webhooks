<?php

namespace Modules\ApiWebhooks\Services;

use App\Conversation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\ApiWebhooks\Models\TicketMetadata;

class TicketExtensions
{
    public function update($request, $conversation)
    {
        $request->validate([
            'tags' => 'array|max:100', 'tags.*' => 'string|max:100',
            'custom_fields' => 'array|max:100', 'snoozed_until' => 'nullable|date|after:now',
        ]);
        foreach ($request->input('custom_fields', []) as $name => $value) {
            abort_unless(is_string($name) && strlen($name) <= 100
                && (is_scalar($value) || is_null($value)) && strlen((string) $value) <= 4000, 422);
        }
        return DB::transaction(function () use ($request, $conversation) {
            // Lock the owning conversation, including first-time metadata creation.
            Conversation::where('id', $conversation->id)->lockForUpdate()->firstOrFail();
            $metadata = TicketMetadata::firstOrNew(['conversation_id' => $conversation->id]);
            if ($request->has('tags')) {
                $metadata->tags = array_values(array_unique($request->input('tags')));
            }
            if ($request->has('custom_fields')) {
                $metadata->custom_fields = $request->input('custom_fields');
            }
            if ($request->exists('snoozed_until')) {
                if ($request->input('snoozed_until')) {
                    if (!$metadata->snoozed_until) {
                        $metadata->resume_status = $conversation->status;
                    }
                    $metadata->snoozed_until = Carbon::parse($request->input('snoozed_until'))->setTimezone('UTC');
                    $conversation->changeStatus(Conversation::STATUS_PENDING, $request->user());
                } else {
                    $metadata->snoozed_until = null;
                    if ($metadata->resume_status) {
                        $conversation->changeStatus($metadata->resume_status, $request->user());
                    }
                    $metadata->resume_status = null;
                }
            }
            $metadata->save();
            $conversation->touch();
            return $metadata;
        });
    }

    public function wakeDue()
    {
        foreach (TicketMetadata::whereNotNull('snoozed_until')->where('snoozed_until', '<=', now())->limit(100)->get() as $candidate) {
            DB::transaction(function () use ($candidate) {
                $metadata = TicketMetadata::where('conversation_id', $candidate->conversation_id)->lockForUpdate()->first();
                if (!$metadata || !$metadata->snoozed_until || $metadata->snoozed_until->isFuture()) {
                    return;
                }
                $conversation = Conversation::find($metadata->conversation_id);
                if ($conversation && $conversation->status == Conversation::STATUS_PENDING && $metadata->resume_status) {
                    $conversation->changeStatus($metadata->resume_status);
                }
                $metadata->snoozed_until = null;
                $metadata->resume_status = null;
                $metadata->save();
                if ($conversation) {
                    $conversation->touch();
                }
            });
        }
    }
}
