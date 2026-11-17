<?php

namespace Modules\ApiWebhooks\Services;

use App\ConversationFolder;
use App\Folder;
use App\User;
use Illuminate\Database\Eloquent\Collection;
use Modules\ApiWebhooks\Models\TicketMetadata;
use Modules\ApiWebhooks\Transformers\ConversationTransformer;

class ConversationPresenter
{
    public static function transform($conversation)
    {
        return self::page(new Collection([$conversation]), auth()->user())[0];
    }

    public static function page($conversations, $user)
    {
        if ($conversations->isEmpty()) {
            return [];
        }
        $conversations->load(['mailbox', 'user', 'customer.emails', 'created_by_user', 'followers']);
        $ids = $conversations->pluck('id')->all();
        $metadata = TicketMetadata::whereIn('conversation_id', $ids)->get()->keyBy('conversation_id');
        $unread = $user->unreadNotifications()->whereIn('conversation_id', $ids)->pluck('conversation_id')->all();
        $starred = ConversationFolder::whereIn('conversation_id', $ids)->whereIn('folder_id',
            Folder::where('user_id', $user->id)->where('type', Folder::TYPE_STARRED)->select('id'))
            ->pluck('conversation_id')->all();
        $followers = $conversations->pluck('followers')->flatten();
        $agents = User::whereIn('id', $followers->pluck('user_id')->unique()->all())->get()->keyBy('id');
        foreach ($followers as $follower) {
            $follower->setRelation('user', $agents->get($follower->user_id));
        }
        return $conversations->map(function ($conversation) use ($metadata, $unread, $starred) {
            $data = ConversationTransformer::transform($conversation);
            $extension = $metadata->get($conversation->id);
            $data['tags'] = $extension && $extension->tags ? $extension->tags : [];
            $data['custom_fields'] = $extension && $extension->custom_fields ? $extension->custom_fields : (object) [];
            $data['snoozed_until'] = $extension && $extension->snoozed_until ? $extension->snoozed_until->toIso8601String() : null;
            $data['starred'] = in_array($conversation->id, $starred);
            $data['read'] = !in_array($conversation->id, $unread);
            if (isset($conversation->sync_updated_at)) {
                $data['syncUpdatedAt'] = \Carbon\Carbon::parse($conversation->sync_updated_at, 'UTC')->toIso8601String();
            }
            return $data;
        })->all();
    }
}
