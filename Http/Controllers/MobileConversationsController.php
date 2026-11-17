<?php

namespace Modules\ApiWebhooks\Http\Controllers;

use App\Conversation;
use App\Mailbox;
use App\Thread;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ApiWebhooks\Services\AgentAccess;
use Modules\ApiWebhooks\Services\NativeTicketActions;
use Modules\ApiWebhooks\Transformers\ConversationTransformer;
use Modules\ApiWebhooks\Services\ConversationPresenter;
use Modules\ApiWebhooks\Transformers\ThreadTransformer;

class MobileConversationsController extends Controller
{
    protected $access;
    protected $actions;

    public function __construct(AgentAccess $access, NativeTicketActions $actions)
    {
        $this->access = $access;
        $this->actions = $actions;
    }

    public function index(Request $request)
    {
        $request->validate(['mailbox_id' => 'integer|min:1', 'status' => 'in:active,pending,closed,spam', 'folder_id' => 'integer|min:1']);
        $query = $this->access->query($request);
        if ($request->input('snoozed') === '1') {
            $query->whereIn('id', \Modules\ApiWebhooks\Models\TicketMetadata::where('snoozed_until', '>', now())->select('conversation_id'));
        }
        if ($request->filled('mailbox_id')) {
            $query->where('mailbox_id', $request->input('mailbox_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', array_search($request->input('status'), ConversationTransformer::$statusMap, true));
        }
        if ($request->filled('folder_id')) {
            $folder = \App\Folder::findOrFail($request->input('folder_id'));
            abort_unless($request->user()->can('view', $folder->mailbox), 404);
            abort_if($folder->user_id && $folder->user_id != $request->user()->id, 404);
            $query->where('mailbox_id', $folder->mailbox_id);
            $native = Conversation::getQueryByFolder($folder, $request->user()->id);
            $query->whereIn('id', $native->select('conversations.id'));
        }
        return response()->json($this->access->page($request, $query, [ConversationPresenter::class, 'transform']));
    }

    public function show(Request $request, $id)
    {
        $conversation = $this->access->conversation($request, $id);
        $data = ConversationPresenter::transform($conversation);
        return response()->json(['data' => $data]);
    }

    public function threads(Request $request, $id)
    {
        $conversation = $this->access->conversation($request, $id);
        $request->validate(['per_page' => 'integer|min:1|max:100', 'page' => 'integer|min:1']);
        $threads = $conversation->threads()->where('state', Thread::STATE_PUBLISHED)
            ->orderBy('id')->paginate($request->input('per_page', 50));
        return response()->json(['data' => $threads->getCollection()->map(function ($thread) {
            return ThreadTransformer::transform($thread);
        }), 'page' => $threads->currentPage(), 'has_more' => $threads->hasMorePages()]);
    }

    public function create(Request $request)
    {
        $request->validate([
            'mailbox_id' => 'required|integer', 'subject' => 'required|string|max:998',
            'body' => 'required|string', 'to' => 'required|array|min:1', 'to.*' => 'email',
            'cc' => 'array', 'cc.*' => 'email', 'bcc' => 'array', 'bcc.*' => 'email',
        ]);
        $mailbox = Mailbox::findOrFail($request->input('mailbox_id'));
        abort_unless($request->user()->can('view', $mailbox), 404);
        $values = $request->only(['mailbox_id', 'subject', 'body', 'to', 'cc', 'bcc']);
        $values['is_create'] = 1;
        $values['user_id'] = $request->user()->id;
        $values['attachments'] = $this->actions->attachments($request);
        return $this->actions->run($request, 'send_reply', $values);
    }

    public function reply(Request $request, $id)
    {
        return $this->send($request, $id, false, false);
    }

    public function note(Request $request, $id)
    {
        return $this->send($request, $id, true, false);
    }

    public function draft(Request $request, $id)
    {
        return $this->send($request, $id, false, true);
    }

    protected function send($request, $id, $note, $draft)
    {
        $conversation = $this->access->conversation($request, $id, 'update');
        $request->validate([
            'body' => 'required|string', 'to' => 'array', 'to.*' => 'email',
            'cc' => 'array', 'cc.*' => 'email', 'bcc' => 'array', 'bcc.*' => 'email',
        ]);
        if ($note) {
            $request->validate(['mentions' => 'array|max:30', 'mentions.*' => 'integer|distinct']);
            $visibleUsers = $request->user()->whichUsersCanView($request->user()->mailboxesCanView())->pluck('id')->all();
            foreach ($request->input('mentions', []) as $userId) {
                $mentioned = \App\User::find($userId);
                abort_unless($mentioned && in_array((int) $userId, array_map('intval', $visibleUsers), true)
                    && $mentioned->isActive() && $mentioned->can('view', $conversation), 422);
            }
        }
        $values = $request->only(['body', 'to', 'cc', 'bcc']);
        $values['conversation_id'] = $conversation->id;
        $values['mailbox_id'] = $conversation->mailbox_id;
        $values['is_note'] = $note ? 1 : 0;
        $values['user_id'] = $conversation->user_id ?: -1;
        $values['attachments'] = $this->actions->attachments($request, $conversation);
        return $this->actions->run($request, $draft ? 'save_draft' : 'send_reply', $values);
    }

    public function update(Request $request, $id)
    {
        $conversation = $this->access->conversation($request, $id, 'update');
        $request->validate([
            'action' => 'required|in:status,assignee,move,star,merge,metadata',
            'status' => 'required_if:action,status|in:active,pending,closed,spam',
            'user_id' => 'required_if:action,assignee|integer|min:-1',
            'mailbox_id' => 'required_if:action,move|integer',
            'starred' => 'required_if:action,star|boolean',
            'merge_id' => 'required_if:action,merge|integer',
        ]);
        $values = ['conversation_id' => $conversation->id];
        switch ($request->input('action')) {
            case 'metadata':
                return response()->json(['data' => app(\Modules\ApiWebhooks\Services\TicketExtensions::class)->update($request, $conversation)]);
            case 'status':
                $values['status'] = array_search($request->input('status'), ConversationTransformer::$statusMap, true);
                return $this->actions->run($request, 'conversation_change_status', $values);
            case 'assignee':
                $assigneeId = (int) $request->input('user_id');
                if ($assigneeId !== -1) {
                    $assignee = \App\User::find($assigneeId);
                    abort_unless($assignee && $assignee->isActive() && $conversation->mailbox->userHasAccess($assignee), 422);
                }
                $values['user_id'] = $assigneeId;
                return $this->actions->run($request, 'conversation_change_user', $values);
            case 'move':
                $destination = Mailbox::findOrFail($request->input('mailbox_id'));
                abort_unless($request->user()->can('view', $destination), 404);
                $values['mailbox_id'] = $destination->id;
                return $this->actions->run($request, 'conversation_move', $values);
            case 'star':
                $values['sub_action'] = $request->input('starred') ? 'star' : 'unstar';
                return $this->actions->run($request, 'star_conversation', $values);
            case 'merge':
                $other = $this->access->conversation($request, $request->input('merge_id'), 'update');
                abort_if($other->id == $conversation->id, 422);
                $values['merge_conversation_id'] = [$other->id];
                return $this->actions->run($request, 'conversation_merge', $values);
        }
    }

    public function drafts(Request $request, $id)
    {
        $conversation = $this->access->conversation($request, $id);
        $threads = $conversation->threads()->where('state', Thread::STATE_DRAFT)
            ->where('created_by_user_id', $request->user()->id)->orderBy('id')->get();
        return response()->json(['data' => $threads->map(function ($thread) { return ThreadTransformer::transform($thread); })]);
    }

    public function deleteDraft(Request $request, $id, $draftId)
    {
        $conversation = $this->access->conversation($request, $id, 'update');
        $thread = $conversation->threads()->where('state', Thread::STATE_DRAFT)
            ->where('created_by_user_id', $request->user()->id)->findOrFail($draftId);
        return $this->actions->run($request, 'discard_draft', [
            'conversation_id' => $conversation->id, 'thread_id' => $thread->id, 'mailbox_id' => $conversation->mailbox_id,
        ]);
    }

    public function readState(Request $request, $id)
    {
        $conversation = $this->access->conversation($request, $id);
        $request->validate(['read' => 'required|boolean']);
        $request->user()->notifications()->where('conversation_id', $conversation->id)
            ->update(['read_at' => $request->input('read') ? now() : null]);
        $request->user()->clearWebsiteNotificationsCache();
        $conversation->touch();
        return response()->json(['read' => (bool) $request->input('read')]);
    }
}
