<?php

namespace Modules\ApiWebhooks\Services;

use Illuminate\Support\Facades\DB;

class AgentSyncChanges
{
    public function record($user, $conversation)
    {
        DB::table('api_agent_sync_changes')->updateOrInsert([
            'user_id' => $user->id, 'conversation_id' => $conversation->id,
        ], ['updated_at' => now()]);
    }

    public function query($query, $user)
    {
        return $query->leftJoin('api_agent_sync_changes as api_agent_changes', function ($join) use ($user) {
            $join->on('api_agent_changes.conversation_id', '=', 'conversations.id')
                ->where('api_agent_changes.user_id', '=', $user->id);
        })->select('conversations.*')->selectRaw(self::timestamp().' as sync_updated_at');
    }

    public static function timestamp()
    {
        return 'CASE WHEN api_agent_changes.updated_at > conversations.updated_at '
            .'THEN api_agent_changes.updated_at ELSE conversations.updated_at END';
    }
}
