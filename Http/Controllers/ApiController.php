<?php

namespace Modules\ApiWebhooks\Http\Controllers;

use App\Conversation;
use App\Customer;
use App\Email;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ApiWebhooks\Transformers\ConversationTransformer;
use Modules\ApiWebhooks\Transformers\CustomerTransformer;
use Modules\ApiWebhooks\Transformers\MailboxTransformer;
use Modules\ApiWebhooks\Transformers\ThreadTransformer;
use Modules\ApiWebhooks\Transformers\UserTransformer;

class ApiController extends Controller
{
    // ── Conversations ────────────────────────────────────────────

    public function listConversations(Request $request)
    {
        $query = Conversation::query();

        if ($request->filled('mailbox_id')) {
            $query->where('mailbox_id', (int) $request->input('mailbox_id'));
        }
        if ($request->filled('status')) {
            $statusMap = array_flip(ConversationTransformer::$statusMap);
            $status = $statusMap[$request->input('status')] ?? null;
            if ($status) {
                $query->where('status', $status);
            }
        }
        if ($request->filled('state')) {
            $stateMap = array_flip(ConversationTransformer::$stateMap);
            $state = $stateMap[$request->input('state')] ?? null;
            if ($state) {
                $query->where('state', $state);
            }
        }
        if ($request->filled('assignee')) {
            $query->where('user_id', (int) $request->input('assignee'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->input('customer_id'));
        }
        if ($request->filled('search')) {
            $s = $request->input('search');
            $query->where(function ($q) use ($s) {
                $q->where('subject', 'LIKE', '%' . $s . '%')
                  ->orWhere('preview', 'LIKE', '%' . $s . '%')
                  ->orWhere('number', '=', (int) $s)
                  ->orWhere('customer_email', 'LIKE', '%' . $s . '%');
            });
        }

        $query->orderBy($request->input('sort_by', 'updated_at'), $request->input('order', 'desc'));

        $perPage = min((int) ($request->input('per_page', 50)), 200);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'page'   => $paginator->currentPage(),
            'pages'  => $paginator->lastPage(),
            'total'  => $paginator->total(),
            'data'   => $paginator->getCollection()->map(function ($c) {
                return ConversationTransformer::transform($c, false);
            }),
        ]);
    }

    public function getConversation($id)
    {
        $c = Conversation::find($id);
        if (!$c) {
            return response()->json(['status' => 'error', 'message' => 'Conversation not found.'], 404);
        }
        return response()->json([
            'status' => 'success',
            'data'   => ConversationTransformer::transform($c, true),
        ]);
    }

    public function createConversation(Request $request)
    {
        $request->validate([
            'mailbox_id'     => 'required|integer',
            'subject'        => 'required|string|max:998',
            'customer_email' => 'required|email|max:191',
            'body'           => 'required|string',
            'assignee'       => 'nullable|integer',
            'status'         => 'nullable|string',
            'type'           => 'nullable|string',
            'cc'             => 'nullable|string',
            'bcc'            => 'nullable|string',
        ]);

        $mailbox = Mailbox::find($request->input('mailbox_id'));
        if (!$mailbox) {
            return response()->json(['status' => 'error', 'message' => 'Mailbox not found.'], 404);
        }

        // Find or create customer.
        $customerEmail = $request->input('customer_email');
        $emailRecord = Email::where('email', $customerEmail)->first();
        if ($emailRecord) {
            $customer = Customer::find($emailRecord->customer_id);
        } else {
            $customer = new Customer();
            $customer->first_name = $request->input('customer_first_name', '');
            $customer->last_name = $request->input('customer_last_name', '');
            $customer->save();
            $email = new Email();
            $email->email = $customerEmail;
            $email->customer_id = $customer->id;
            $email->save();
        }

        // Status.
        $statusMap = array_flip(ConversationTransformer::$statusMap);
        $status = $statusMap[$request->input('status', 'active')] ?? Conversation::STATUS_ACTIVE;

        // Type.
        $typeMap = ['email' => 1, 'phone' => 2, 'chat' => 3];
        $type = $typeMap[$request->input('type', 'email')] ?? 1;

        $conversation = new Conversation();
        $conversation->type = $type;
        $conversation->subject = $request->input('subject');
        $conversation->status = $status;
        $conversation->state = Conversation::STATE_PUBLISHED;
        $conversation->mailbox_id = $mailbox->id;
        $conversation->customer_id = $customer->id;
        $conversation->customer_email = $customerEmail;
        $conversation->source_via = Conversation::PERSON_USER;
        $conversation->source_type = 3; // SOURCE_TYPE_API
        $conversation->preview = mb_substr(strip_tags($request->input('body')), 0, 255);
        $conversation->last_reply_at = now();

        if ($request->filled('cc')) {
            $conversation->setCc(array_map('trim', explode(',', $request->input('cc'))));
        }
        if ($request->filled('bcc')) {
            $conversation->setBcc(array_map('trim', explode(',', $request->input('bcc'))));
        }
        if ($request->filled('assignee')) {
            $assignee = User::find($request->input('assignee'));
            if ($assignee) {
                $conversation->user_id = $assignee->id;
            }
        }

        // Assign to folder.
        $folder = $mailbox->folders()->where('type', \App\Folder::TYPE_ASSIGNED)->first();
        if ($folder) {
            $conversation->folder_id = $folder->id;
        }

        $conversation->save();

        // Create the first thread.
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type = Thread::TYPE_CUSTOMER;
        $thread->status = $status;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->body = $request->input('body');
        $thread->from = $customerEmail;
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = 3;
        $thread->customer_id = $customer->id;
        $thread->created_by_customer_id = $customer->id;
        $thread->first = true;
        if ($conversation->user_id) {
            $thread->user_id = $conversation->user_id;
        }
        $thread->save();

        $conversation->threads_count = 1;
        $conversation->save();

        // Fire event so notifications work.
        event(new \App\Events\CustomerCreatedConversation($conversation, $thread));

        return response()->json([
            'status' => 'success',
            'data'   => ConversationTransformer::transform($conversation, true),
        ], 201);
    }

    public function updateConversation(Request $request, $id)
    {
        $conversation = Conversation::find($id);
        if (!$conversation) {
            return response()->json(['status' => 'error', 'message' => 'Conversation not found.'], 404);
        }

        if ($request->filled('status')) {
            $statusMap = array_flip(ConversationTransformer::$statusMap);
            $status = $statusMap[$request->input('status')] ?? null;
            if ($status) {
                $conversation->status = $status;
                if ($status == Conversation::STATUS_CLOSED) {
                    $conversation->closed_at = now();
                }
            }
        }

        if ($request->filled('assignee')) {
            $assignee = User::find($request->input('assignee'));
            if ($assignee) {
                $conversation->user_id = $assignee->id;
            }
        }

        if ($request->filled('subject')) {
            $conversation->subject = $request->input('subject');
        }

        $conversation->save();

        return response()->json([
            'status' => 'success',
            'data'   => ConversationTransformer::transform($conversation),
        ]);
    }

    public function deleteConversation($id)
    {
        $conversation = Conversation::find($id);
        if (!$conversation) {
            return response()->json(['status' => 'error', 'message' => 'Conversation not found.'], 404);
        }

        $conversation->state = Conversation::STATE_DELETED;
        $conversation->save();

        return response()->json(['status' => 'success', 'message' => 'Conversation deleted.']);
    }

    // ── Threads ──────────────────────────────────────────────────

    public function listThreads($conversationId)
    {
        $conversation = Conversation::find($conversationId);
        if (!$conversation) {
            return response()->json(['status' => 'error', 'message' => 'Conversation not found.'], 404);
        }

        $threads = $conversation->threads()
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $threads->map(function ($t) {
                return ThreadTransformer::transform($t);
            }),
        ]);
    }

    public function createThread(Request $request, $conversationId)
    {
        $conversation = Conversation::find($conversationId);
        if (!$conversation) {
            return response()->json(['status' => 'error', 'message' => 'Conversation not found.'], 404);
        }

        $request->validate([
            'body' => 'required|string',
            'type' => 'nullable|string',
            'user_id' => 'nullable|integer',
        ]);

        $typeMap = array_flip(ThreadTransformer::$typeMap);
        $type = $typeMap[$request->input('type', 'note')] ?? Thread::TYPE_NOTE;

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type = $type;
        $thread->status = $conversation->status;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->body = $request->input('body');
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = 3;

        if ($request->filled('user_id')) {
            $user = User::find($request->input('user_id'));
            if ($user) {
                $thread->user_id = $user->id;
                $thread->created_by_user_id = $user->id;
            }
        }

        if ($type == Thread::TYPE_CUSTOMER && $conversation->customer_id) {
            $thread->customer_id = $conversation->customer_id;
            $thread->from = $conversation->customer_email;
        }

        $thread->save();

        // Update conversation.
        $conversation->threads_count = $conversation->threads()->count();
        $conversation->preview = mb_substr(strip_tags($request->input('body')), 0, 255);
        $conversation->last_reply_at = now();
        $conversation->save();

        // Fire events.
        if ($type == Thread::TYPE_NOTE) {
            event(new \App\Events\UserAddedNote($conversation, $thread));
        } elseif ($type == Thread::TYPE_MESSAGE) {
            event(new \App\Events\UserReplied($conversation, $thread));
        }

        return response()->json([
            'status' => 'success',
            'data'   => ThreadTransformer::transform($thread),
        ], 201);
    }

    // ── Customers ────────────────────────────────────────────────

    public function listCustomers(Request $request)
    {
        $query = Customer::query();

        if ($request->filled('search')) {
            $s = $request->input('search');
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'LIKE', '%' . $s . '%')
                  ->orWhere('last_name', 'LIKE', '%' . $s . '%')
                  ->orWhere('company', 'LIKE', '%' . $s . '%')
                  ->orWhereHas('emails', function ($eq) use ($s) {
                      $eq->where('email', 'LIKE', '%' . $s . '%');
                  });
            });
        }

        $perPage = min((int) ($request->input('per_page', 50)), 200);
        $paginator = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'page'   => $paginator->currentPage(),
            'pages'  => $paginator->lastPage(),
            'total'  => $paginator->total(),
            'data'   => $paginator->getCollection()->map(function ($c) {
                return CustomerTransformer::transform($c);
            }),
        ]);
    }

    public function getCustomer($id)
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Customer not found.'], 404);
        }
        return response()->json([
            'status' => 'success',
            'data'   => CustomerTransformer::transform($customer),
        ]);
    }

    // ── Users (Agents) ───────────────────────────────────────────

    public function listUsers()
    {
        $users = User::where('status', '!=', User::STATUS_DELETED)->orderBy('id')->get();
        return response()->json([
            'status' => 'success',
            'data'   => $users->map(function ($u) {
                return UserTransformer::transform($u);
            }),
        ]);
    }

    public function getUser($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        }
        return response()->json([
            'status' => 'success',
            'data'   => UserTransformer::transform($user),
        ]);
    }

    public function createUser(Request $request)
    {
        $request->validate([
            'email'      => 'required|email|max:100|unique:users,email',
            'first_name' => 'required|string|max:20',
            'last_name'  => 'required|string|max:30',
            'password'   => 'nullable|string|min:8',
            'role'       => 'nullable|string',
        ]);

        $roleMap = ['admin' => User::ROLE_ADMIN, 'user' => User::ROLE_USER];

        $user = new User();
        $user->first_name = $request->input('first_name');
        $user->last_name = $request->input('last_name');
        $user->email = $request->input('email');
        $user->password = bcrypt($request->input('password', bin2hex(random_bytes(8))));
        $user->role = $roleMap[$request->input('role', 'user')] ?? User::ROLE_USER;
        $user->status = User::STATUS_ACTIVE;
        $user->invite_state = User::INVITE_STATE_NOT_INVITED;
        $user->save();

        // Assign to mailboxes if specified.
        if ($request->filled('mailbox_ids')) {
            $ids = is_array($request->input('mailbox_ids'))
                ? $request->input('mailbox_ids')
                : explode(',', $request->input('mailbox_ids'));
            $user->mailboxes()->sync(array_map('intval', $ids));
        }

        return response()->json([
            'status' => 'success',
            'data'   => UserTransformer::transform($user),
        ], 201);
    }

    public function disableUser($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        }
        if ($user->isAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Cannot disable admin users via API.'], 403);
        }

        $user->status = User::STATUS_DISABLED;
        $user->save();

        return response()->json(['status' => 'success', 'message' => 'User disabled.', 'data' => UserTransformer::transform($user)]);
    }

    public function enableUser($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        }

        $user->status = User::STATUS_ACTIVE;
        $user->save();

        return response()->json(['status' => 'success', 'message' => 'User enabled.', 'data' => UserTransformer::transform($user)]);
    }

    // ── Mailboxes ────────────────────────────────────────────────

    public function listMailboxes()
    {
        $mailboxes = Mailbox::all();
        return response()->json([
            'status' => 'success',
            'data'   => $mailboxes->map(function ($m) {
                return MailboxTransformer::transform($m, false);
            }),
        ]);
    }

    public function getMailbox($id, Request $request)
    {
        $mailbox = Mailbox::find($id);
        if (!$mailbox) {
            return response()->json(['status' => 'error', 'message' => 'Mailbox not found.'], 404);
        }
        $includeConfig = $request->input('include_config', false);
        return response()->json([
            'status' => 'success',
            'data'   => MailboxTransformer::transform($mailbox, (bool) $includeConfig),
        ]);
    }

    // ── Email History ────────────────────────────────────────────

    /**
     * List email threads (inbound/outbound) with optional filters.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function emailHistory(Request $request)
    {
        $query = Thread::with('conversation')
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            ->where('state', Thread::STATE_PUBLISHED);

        if ($request->filled('mailbox_id')) {
            $convIds = Conversation::where('mailbox_id', (int) $request->input('mailbox_id'))
                ->pluck('id');
            $query->whereIn('conversation_id', $convIds);
        }
        if ($request->filled('conversation_id')) {
            $query->where('conversation_id', (int) $request->input('conversation_id'));
        }
        if ($request->filled('direction')) {
            if ($request->input('direction') === 'in') {
                $query->where('type', Thread::TYPE_CUSTOMER);
            } elseif ($request->input('direction') === 'out') {
                $query->where('type', Thread::TYPE_MESSAGE);
            }
        }
        if ($request->filled('since')) {
            $query->where('created_at', '>=', $request->input('since'));
        }

        $perPage = min((int) ($request->input('per_page', 50)), 200);
        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'page'   => $paginator->currentPage(),
            'pages'  => $paginator->lastPage(),
            'total'  => $paginator->total(),
            'data'   => $paginator->getCollection()->map(function ($t) {
                return ThreadTransformer::transform($t);
            }),
        ]);
    }

    // ── SMTP/IMAP Test ───────────────────────────────────────────

    public function testSmtp($id)
    {
        $mailbox = Mailbox::find($id);
        if (!$mailbox) {
            return response()->json(['status' => 'error', 'message' => 'Mailbox not found.'], 404);
        }

        try {
            $transport = new \Swift_SmtpTransport(
                $mailbox->out_server,
                $mailbox->out_port,
                $mailbox->getOutEncryptionName()
            );
            $transport->setUsername($mailbox->out_username);
            $transport->setPassword($mailbox->out_password);
            $transport->start();
            $transport->stop();

            return response()->json(['status' => 'success', 'message' => 'SMTP connection successful.']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'SMTP failed: ' . $e->getMessage()], 500);
        }
    }

    public function testImap($id)
    {
        $mailbox = Mailbox::find($id);
        if (!$mailbox) {
            return response()->json(['status' => 'error', 'message' => 'Mailbox not found.'], 404);
        }

        try {
            $encMap = [1 => '', 2 => 'ssl', 3 => 'tls', 4 => 'notls'];
            $enc = $encMap[$mailbox->in_encryption] ?? '';

            $flags = '/imap';
            if ($enc === 'ssl') {
                $flags .= '/ssl';
            }
            if (!$mailbox->in_validate_cert) {
                $flags .= '/novalidate-cert';
            }

            $connString = '{' . $mailbox->in_server . ':' . $mailbox->in_port . $flags . '}INBOX';
            $conn = @imap_open($connString, $mailbox->in_username, $mailbox->in_password, 0, 1);

            if (!$conn) {
                throw new \Exception(imap_last_error() ?: 'Connection failed');
            }

            $info = imap_mailboxmsginfo($conn);
            imap_close($conn);

            return response()->json([
                'status'  => 'success',
                'message' => 'IMAP connection successful.',
                'data'    => [
                    'messages' => $info->Nmsgs ?? 0,
                    'recent'   => $info->Recent ?? 0,
                    'unread'   => $info->Unread ?? 0,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'IMAP failed: ' . $e->getMessage()], 500);
        }
    }

    public function sendTestEmail(Request $request, $id)
    {
        $mailbox = Mailbox::find($id);
        if (!$mailbox) {
            return response()->json(['status' => 'error', 'message' => 'Mailbox not found.'], 404);
        }

        $request->validate([
            'to' => 'required|email|max:191',
        ]);

        try {
            $to = $request->input('to');
            $subject = $request->input('subject', 'FreeScout API Test Email');
            $body = $request->input('body', 'This is a test email sent via the FreeScout API at ' . now()->toDateTimeString());

            \Mail::raw($body, function ($message) use ($mailbox, $to, $subject) {
                $message->to($to)
                    ->subject($subject)
                    ->from($mailbox->email, $mailbox->name);
            });

            return response()->json(['status' => 'success', 'message' => 'Test email sent to ' . $to]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Send failed: ' . $e->getMessage()], 500);
        }
    }
}
