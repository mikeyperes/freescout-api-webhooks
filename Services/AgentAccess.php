<?php

namespace Modules\ApiWebhooks\Services;

use App\Conversation;

class AgentAccess
{
    public function query($request)
    {
        $user = $request->user();
        $query = Conversation::whereIn('mailbox_id', $user->mailboxesCanView()->pluck('id')->all());
        if ($request->attributes->get('device_token')->access_level === 'own') {
            $query->where('user_id', $user->id);
        }
        if ($user->canSeeOnlyAssignedConversations()) {
            $query->where(function ($restricted) use ($user) {
                $restricted->where('created_by_user_id', $user->id);
                $restricted->orWhere(function ($assigned) use ($user) {
                    if (!\Eventy::filter('folder.only_assigned_condition', false, $assigned, $user->id)) {
                        $assigned->where('user_id', $user->id);
                    }
                });
            });
        }
        return $query;
    }

    public function conversation($request, $id, $ability = 'view')
    {
        $conversation = $this->query($request)->findOrFail($id);
        abort_unless($request->user()->can($ability, $conversation), 404);
        return $conversation;
    }

    public function page($request, $query, $transform)
    {
        $request->validate(['per_page' => 'integer|min:1|max:100', 'page' => 'integer|min:1']);
        // Evaluate FreeScout's policy, including AssignedOnly hooks, before exposing rows or counts.
        // Stream candidates rather than trusting mailbox membership alone.
        $perPage = (int) $request->input('per_page', 50);
        $page = (int) $request->input('page', 1);
        $skip = ($page - 1) * $perPage;
        $items = [];
        $visible = 0;
        foreach ($query->orderBy('id')->cursor() as $conversation) {
            if (!$request->user()->can('view', $conversation)) {
                continue;
            }
            if ($visible++ < $skip) {
                continue;
            }
            $items[] = call_user_func($transform, $conversation);
            if (count($items) > $perPage) {
                break;
            }
        }
        return ['data' => array_slice($items, 0, $perPage), 'has_more' => count($items) > $perPage, 'page' => $page];
    }
}
