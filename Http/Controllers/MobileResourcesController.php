<?php

namespace Modules\ApiWebhooks\Http\Controllers;

use App\Attachment;
use App\Conversation;
use App\Customer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ApiWebhooks\Services\AgentAccess;
use Modules\ApiWebhooks\Transformers\CustomerTransformer;
use Modules\ApiWebhooks\Transformers\UserTransformer;

class MobileResourcesController extends Controller
{
    protected $access;

    public function __construct(AgentAccess $access)
    {
        $this->access = $access;
    }

    public function me(Request $request)
    {
        return response()->json(['data' => UserTransformer::brief($request->user()),
            'access_level' => $request->attributes->get('device_token')->access_level]);
    }

    public function mailboxes(Request $request)
    {
        $data = [];
        foreach ($request->user()->mailboxesCanView() as $mailbox) {
            $folders = [];
            foreach ($mailbox->getAssesibleFolders() as $folder) {
                $query = Conversation::getQueryByFolder($folder, $request->user()->id);
                $visible = 0;
                foreach ($query->cursor() as $conversation) {
                    if ($request->attributes->get('device_token')->access_level === 'own'
                        && $conversation->user_id != $request->user()->id) {
                        continue;
                    }
                    if ($request->user()->can('view', $conversation)) {
                        ++$visible;
                    }
                }
                $folders[] = ['id' => $folder->id, 'name' => $folder->getTypeName(), 'type' => $folder->type, 'count' => $visible];
            }
            $snoozed = 0;
            foreach ($this->access->query($request)->where('mailbox_id', $mailbox->id)
                ->whereIn('id', \Modules\ApiWebhooks\Models\TicketMetadata::where('snoozed_until', '>', now())->select('conversation_id'))->cursor() as $conversation) {
                if ($request->user()->can('view', $conversation)) {
                    ++$snoozed;
                }
            }
            $folders[] = ['id' => null, 'name' => 'Snoozed', 'type' => 'snoozed', 'count' => $snoozed];
            $data[] = ['id' => $mailbox->id, 'name' => $mailbox->name, 'email' => $mailbox->email, 'folders' => $folders];
        }
        return response()->json(['data' => $data]);
    }

    protected function customerQuery($request)
    {
        // Even when FreeScout's global customer-visibility setting is off, mobile tokens
        // cannot enumerate customers outside their conversation access level.
        if ($request->attributes->get('device_token')->access_level === 'root') {
            return Customer::query();
        }
        $query = $this->access->query($request);
        $ids = [];
        foreach ($query->cursor() as $conversation) {
            if ($request->user()->can('view', $conversation) && $conversation->customer_id) {
                $ids[] = $conversation->customer_id;
            }
        }
        return Customer::whereIn('id', array_unique($ids));
    }

    public function customers(Request $request)
    {
        $request->validate(['page' => 'integer|min:1', 'per_page' => 'integer|min:1|max:100']);
        $page = $this->customerQuery($request)->with('emails')->orderBy('id')->paginate($request->input('per_page', 50));
        return response()->json(['data' => $page->getCollection()->map(function ($customer) {
            return CustomerTransformer::transform($customer);
        }), 'page' => $page->currentPage(), 'has_more' => $page->hasMorePages()]);
    }

    public function customer(Request $request, $id)
    {
        return response()->json(['data' => CustomerTransformer::transform($this->customerQuery($request)->findOrFail($id))]);
    }

    public function createCustomer(Request $request)
    {
        $request->validate(['first_name' => 'required|string|max:255', 'last_name' => 'nullable|string|max:255', 'email' => 'required|email|unique:emails,email']);
        $input = Request::create('/api/v2/customers', 'POST', array_merge($request->only(['first_name', 'last_name', 'email']), ['action' => 'create']));
        $response = app(\App\Http\Controllers\CustomersController::class)->ajax($input);
        $data = json_decode($response->getContent(), true);
        if (isset($data['status']) && $data['status'] === 'success') {
            $customer = Customer::getByEmail($request->input('email'));
            return response()->json(['data' => CustomerTransformer::transform($customer)], 201);
        }
        return response()->json(['error' => 'native_action_failed', 'message' => $data['msg'] ?? 'Customer creation failed.'], 422);
    }

    public function users(Request $request)
    {
        $users = $request->user()->whichUsersCanView($request->user()->mailboxesCanView());
        return response()->json(['data' => collect($users)->map(function ($user) { return UserTransformer::brief($user); })->values()]);
    }

    public function upload(Request $request)
    {
        $request->validate(['file' => 'required|file']);
        // Native uploader sanitizes filenames and uses FreeScout's private attachment disk.
        $request->merge(['attach' => 1]);
        $response = app(\App\Http\Controllers\ConversationsController::class)->upload($request);
        $data = json_decode($response->getContent(), true);
        if (isset($data['attachment_id'])) {
            $data['id'] = decrypt($data['attachment_id']);
            unset($data['url'], $data['attachment_id']);
        }
        return response()->json(['data' => $data], isset($data['status']) && $data['status'] === 'success' ? 201 : 422);
    }

    public function download(Request $request, $id)
    {
        $attachment = Attachment::findOrFail($id);
        if ($attachment->thread_id) {
            abort_unless($attachment->thread, 404);
            $this->access->conversation($request, $attachment->thread->conversation_id);
            abort_unless($attachment->thread->state == \App\Thread::STATE_PUBLISHED
                || $attachment->thread->created_by_user_id == $request->user()->id, 404);
        } else {
            abort_unless($attachment->user_id == $request->user()->id, 404);
        }
        return response()->download($attachment->getLocalFilePath(), $attachment->file_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function notifications(Request $request)
    {
        $request->validate(['per_page' => 'integer|min:1|max:100', 'page' => 'integer|min:1']);
        // Return only the user's own notifications; inaccessible conversation payloads are omitted.
        $data = [];
        $perPage = (int) $request->input('per_page', 50);
        $page = (int) $request->input('page', 1);
        $skip = ($page - 1) * $perPage;
        $visible = 0;
        foreach ($request->user()->notifications()->orderBy('created_at', 'desc')->orderBy('id')->cursor() as $notification) {
            $payload = $notification->data;
            $id = $payload['conversation_id'] ?? null;
            if (!$id) {
                continue;
            }
            try {
                $this->access->conversation($request, $id);
                if ($visible++ < $skip) {
                    continue;
                }
                $data[] = ['id' => $notification->id, 'read_at' => $notification->read_at,
                    'conversation_id' => $id, 'created_at' => $notification->created_at];
                if (count($data) > $perPage) {
                    break;
                }
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                continue;
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $exception) {
                continue;
            }
        }
        return response()->json(['data' => array_slice($data, 0, $perPage), 'page' => $page, 'has_more' => count($data) > $perPage]);
    }

    public function updateCustomer(Request $request, $id)
    {
        $customer = $this->customerQuery($request)->findOrFail($id);
        abort_unless($request->user()->can('view', $customer), 404);
        $request->validate([
            'first_name' => 'required|string|max:255', 'last_name' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255', 'job_title' => 'nullable|string|max:255',
            'background' => 'nullable|string',
        ]);
        // Preserve existing email/channel records; their native edit form has a separate contract.
        $customer->setData($request->only(['first_name', 'last_name', 'company', 'job_title', 'background']));
        $customer->save();
        \Eventy::action('customer.updated', $customer);
        return response()->json(['data' => CustomerTransformer::transform($customer->fresh())]);
    }

    public function notificationSettings(Request $request)
    {
        return response()->json(['data' => $request->user()->subscriptions()->get(['medium', 'event'])]);
    }

    public function saveNotificationSettings(Request $request)
    {
        $request->validate(['subscriptions' => 'present|array']);
        $allowedEvents = [
            \Modules\ApiWebhooks\Services\OutboundEvents::EVENT_MENTION,
            \App\Subscription::EVENT_NEW_CONVERSATION,
            \App\Subscription::EVENT_CONVERSATION_ASSIGNED_TO_ME,
            \App\Subscription::EVENT_CONVERSATION_ASSIGNED,
            \App\Subscription::EVENT_FOLLOWED_CONVERSATION_UPDATED,
            \App\Subscription::EVENT_CUSTOMER_REPLIED_TO_UNASSIGNED,
            \App\Subscription::EVENT_CUSTOMER_REPLIED_TO_MY,
            \App\Subscription::EVENT_CUSTOMER_REPLIED_TO_ASSIGNED,
            \App\Subscription::EVENT_USER_REPLIED_TO_UNASSIGNED,
            \App\Subscription::EVENT_USER_REPLIED_TO_MY,
            \App\Subscription::EVENT_USER_REPLIED_TO_ASSIGNED,
        ];
        $normalized = [];
        foreach ($request->input('subscriptions') as $medium => $events) {
            abort_unless(ctype_digit((string) $medium), 422);
            abort_unless(in_array((int) $medium, \App\Subscription::$mediums, true) && is_array($events), 422);
            foreach ($events as $event) {
                abort_unless((is_int($event) || (is_string($event) && ctype_digit($event)))
                    && in_array((int) $event, $allowedEvents, true), 422);
                $normalized[(int) $medium][] = (int) $event;
            }
        }
        \App\Subscription::saveFromArray($normalized, $request->user()->id);
        return $this->notificationSettings($request);
    }

    public function savedReplies(Request $request)
    {
        $ids = $request->user()->mailboxesCanView()->pluck('id')->all();
        $query = \DB::table('api_saved_replies')->where(function ($mailbox) use ($ids) {
            $mailbox->whereNull('mailbox_id')->orWhereIn('mailbox_id', $ids);
        })->where(function ($agent) use ($request) {
            $agent->whereNull('user_id')->orWhere('user_id', $request->user()->id);
        });
        if ($request->filled('mailbox_id')) {
            abort_unless(in_array((int) $request->input('mailbox_id'), array_map('intval', $ids), true), 404);
            $query->where(function ($mailbox) use ($request) {
                $mailbox->whereNull('mailbox_id')->orWhere('mailbox_id', $request->input('mailbox_id'));
            });
        }
        return response()->json(['data' => $query->orderBy('name')->get(['id', 'name', 'body', 'mailbox_id'])]);
    }
}
