<?php

namespace Modules\ApiWebhooks\Transformers;

use App\Conversation;

class ConversationTransformer
{
    public static $statusMap = [
        Conversation::STATUS_ACTIVE  => 'active',
        Conversation::STATUS_PENDING => 'pending',
        Conversation::STATUS_CLOSED  => 'closed',
        Conversation::STATUS_SPAM    => 'spam',
    ];

    public static $stateMap = [
        Conversation::STATE_DRAFT     => 'draft',
        Conversation::STATE_PUBLISHED => 'published',
        Conversation::STATE_DELETED   => 'deleted',
    ];

    public static function transform(Conversation $c, $includeThreads = false)
    {
        $data = [
            'id'        => $c->id,
            'number'    => $c->number,
            'type'      => self::typeName($c->type),
            'status'    => self::$statusMap[$c->status] ?? 'unknown',
            'state'     => self::$stateMap[$c->state] ?? 'unknown',
            'subject'   => $c->subject,
            'preview'   => $c->preview,
            'mailboxId' => $c->mailbox_id,
            'assignee'  => $c->user_id ? UserTransformer::brief($c->user) : null,
            'customer'  => $c->customer_id ? CustomerTransformer::brief($c->customer) : null,
            'createdBy' => self::createdBy($c),
            'cc'        => $c->getCcArray(),
            'bcc'       => $c->getBccArray(),
            'closedAt'  => $c->closed_at ? date('c', strtotime($c->closed_at)) : null,
            'closedBy'  => $c->closed_by_user_id,
            'lastReplyAt'   => $c->last_reply_at ? date('c', strtotime($c->last_reply_at)) : null,
            'createdAt'     => $c->created_at ? date('c', strtotime($c->created_at)) : null,
            'updatedAt'     => $c->updated_at ? date('c', strtotime($c->updated_at)) : null,
            'threadsCount'  => $c->threads_count,
            'hasAttachments' => (bool) $c->has_attachments,
        ];

        if ($includeThreads) {
            $threads = $c->threads()
                ->where('state', \App\Thread::STATE_PUBLISHED)
                ->orderBy('created_at', 'desc')
                ->get();
            $data['_embedded']['threads'] = $threads->map(function ($t) {
                return ThreadTransformer::transform($t);
            })->toArray();
        }

        return $data;
    }

    private static function typeName($type)
    {
        $map = [1 => 'email', 2 => 'phone', 3 => 'chat'];
        return $map[$type] ?? 'email';
    }

    private static function createdBy(Conversation $c)
    {
        if ($c->created_by_user_id) {
            $u = $c->created_by_user;
            return $u ? ['id' => $u->id, 'type' => 'user', 'email' => $u->email] : null;
        }
        if ($c->created_by_customer_id) {
            return ['id' => $c->created_by_customer_id, 'type' => 'customer', 'email' => $c->customer_email];
        }
        return null;
    }
}
