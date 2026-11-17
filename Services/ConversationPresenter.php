<?php

namespace Modules\ApiWebhooks\Services;

use Modules\ApiWebhooks\Models\TicketMetadata;
use Modules\ApiWebhooks\Transformers\ConversationTransformer;

class ConversationPresenter
{
    public static function transform($conversation)
    {
        $data = ConversationTransformer::transform($conversation);
        $metadata = TicketMetadata::find($conversation->id);
        $data['tags'] = $metadata && $metadata->tags ? $metadata->tags : [];
        $data['custom_fields'] = $metadata && $metadata->custom_fields ? $metadata->custom_fields : (object) [];
        $data['snoozed_until'] = $metadata && $metadata->snoozed_until ? $metadata->snoozed_until->toIso8601String() : null;
        $data['starred'] = $conversation->isStarredByUser(auth()->id());
        $data['read'] = !auth()->user()->unreadNotifications()->where('conversation_id', $conversation->id)->exists();
        return $data;
    }
}
