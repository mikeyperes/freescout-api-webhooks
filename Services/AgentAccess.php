<?php

namespace Modules\ApiWebhooks\Services;

use App\Conversation;

class AgentAccess
{
    public function query($request)
    {
        return $this->forToken($request->attributes->get('device_token'));
    }

    public function forToken($token)
    {
        $user = $token->user;
        $query = Conversation::whereIn('conversations.mailbox_id', $user->mailboxesCanView()->pluck('id')->all());
        if ($token->access_level === 'own') {
            $query->where('conversations.user_id', $user->id);
        }
        if ($user->canSeeOnlyAssignedConversations()) {
            $query->where(function ($restricted) use ($user) {
                $restricted->where('conversations.created_by_user_id', $user->id);
                $restricted->orWhere(function ($assigned) use ($user) {
                    if (!\Eventy::filter('folder.only_assigned_condition', false, $assigned, $user->id)) {
                        $assigned->where('conversations.user_id', $user->id);
                    }
                });
            });
        }
        return $query;
    }

    public function canSee($token, $conversation, $ability = 'view')
    {
        return $token->user->isActive()
            && $token->user->can($ability, $conversation)
            && $this->forToken($token)->where('conversations.id', $conversation->id)->exists();
    }

    public function conversation($request, $id, $ability = 'view')
    {
        $conversation = $this->query($request)->findOrFail($id);
        abort_unless($request->user()->can($ability, $conversation), 404);
        return $conversation;
    }

    public function folderQuery($request, $folder)
    {
        return $this->constrainFolder($this->query($request), $folder, $request->user());
    }

    public function constrainFolder($query, $folder, $user)
    {
        $native = Conversation::getQueryByFolder($folder, $user->id);
        if ($native instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
            $native = $native->getQuery();
        }
        return $query->where('conversations.mailbox_id', $folder->mailbox_id)
            ->whereIn('conversations.id', $native->select('conversations.id'));
    }

    public function pageSize($request)
    {
        $request->validate(['per_page' => 'integer|min:1|max:100', 'page' => 'integer|min:1']);
        return (int) $request->input('per_page', 50);
    }

    public function slice($request, $query)
    {
        $limit = $this->pageSize($request);
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        if ($hasMore) {
            $rows->pop();
        }
        return ['rows' => $rows, 'has_more' => $hasMore];
    }

    public function visibleRows($request, $rows)
    {
        // SQL applies all supported visibility rules; the final policy checks only this page.
        return $rows->filter(function ($record) use ($request) {
            return $request->user()->can('view', $record);
        })->values();
    }

    public function page($request, $query)
    {
        $request->validate(['sort' => 'in:activity,last_reply_at,updated_at,created_at', 'direction' => 'in:asc,desc']);
        $size = $this->pageSize($request);
        $page = (int) $request->input('page', 1);
        $sort = $request->input('sort', 'activity');
        $direction = $request->input('direction', 'desc');
        if ($sort === 'activity') {
            $query->orderByRaw('COALESCE(conversations.last_reply_at, conversations.updated_at) '.$direction);
        } else {
            $query->orderBy('conversations.'.$sort, $direction);
        }
        $result = $this->slice($request, $query->orderBy('conversations.id', $direction)->offset(($page - 1) * $size));
        return ['data' => ConversationPresenter::page($this->visibleRows($request, $result['rows']), $request->user()),
            'has_more' => $result['has_more'], 'page' => $page];
    }
}
