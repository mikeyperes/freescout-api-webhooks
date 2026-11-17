<?php

namespace Modules\ApiWebhooks\Services;

use App\Attachment;
use Illuminate\Http\Request;

class NativeTicketActions
{
    public function run($request, $action, array $values)
    {
        $input = Request::create('/api/v2/native', 'POST', array_merge($values, ['action' => $action, 'x_embed' => 1]));
        $input->setUserResolver(function () use ($request) { return $request->user(); });
        // FreeScout's controller owns reply/send logic, events, counters and attachment handling.
        $created = [];
        $capture = function ($conversation, $thread) use (&$created) {
            $created = ['conversation_id' => $conversation->id, 'thread_id' => $thread->id];
        };
        $hooks = ['conversation.created_by_user_can_undo', 'conversation.user_replied_can_undo', 'conversation.note_added'];
        foreach ($hooks as $hook) {
            \Eventy::addAction($hook, $capture, 100, 2);
        }
        try {
            $response = app(\App\Http\Controllers\ConversationsController::class)->ajax($input);
        } finally {
            foreach ($hooks as $hook) {
                \Eventy::removeAction($hook, $capture, 100);
            }
        }
        $result = json_decode($response->getContent(), true);
        if (!isset($result['status']) || $result['status'] !== 'success') {
            return response()->json(['error' => 'native_action_failed', 'message' => $result['msg'] ?? 'FreeScout refused the action.'], 422);
        }
        if ($action === 'star_conversation') {
            app(AgentSyncChanges::class)->record($request->user(), \App\Conversation::findOrFail($values['conversation_id']));
        }
        return response()->json(['data' => array_merge($result, $created)], !empty($values['is_create']) ? 201 : 200);
    }

    public function attachments($request, $conversation = null)
    {
        $ids = $request->input('attachments', []);
        abort_unless(is_array($ids) && count($ids) <= 50, 422);
        $result = [];
        foreach ($ids as $id) {
            $attachment = Attachment::findOrFail($id);
            if ($attachment->thread_id) {
                abort_unless($conversation && $attachment->thread && $attachment->thread->conversation_id == $conversation->id, 404);
                abort_unless($attachment->thread->state == \App\Thread::STATE_PUBLISHED
                    || $attachment->thread->created_by_user_id == $request->user()->id, 404);
            } else {
                abort_unless($attachment->user_id == $request->user()->id, 404);
            }
            $result[] = encrypt($attachment->id);
        }
        return $result;
    }
}
