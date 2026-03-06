<?php

namespace Modules\ApiWebhooks\Transformers;

use App\Thread;

class ThreadTransformer
{
    public static $typeMap = [
        Thread::TYPE_CUSTOMER => 'customer',
        Thread::TYPE_MESSAGE  => 'message',
        Thread::TYPE_NOTE     => 'note',
        Thread::TYPE_LINEITEM => 'lineitem',
        Thread::TYPE_CHAT     => 'chat',
    ];

    public static function transform(Thread $t)
    {
        return [
            'id'             => $t->id,
            'conversationId' => $t->conversation_id,
            'type'           => self::$typeMap[$t->type] ?? 'unknown',
            'status'         => ConversationTransformer::$statusMap[$t->status] ?? 'nochange',
            'state'          => ConversationTransformer::$stateMap[$t->state] ?? 'unknown',
            'body'           => $t->body,
            'from'           => $t->from,
            'to'             => $t->getToArray(),
            'cc'             => $t->getCcArray(),
            'bcc'            => $t->getBccArray(),
            'customer'       => $t->customer_id ? CustomerTransformer::brief($t->customer) : null,
            'createdBy'      => self::createdBy($t),
            'assignedTo'     => $t->user_id ? UserTransformer::brief($t->user) : null,
            'hasAttachments' => (bool) $t->has_attachments,
            'createdAt'      => $t->created_at ? date('c', strtotime($t->created_at)) : null,
            'openedAt'       => $t->opened_at ? date('c', strtotime($t->opened_at)) : null,
        ];
    }

    private static function createdBy(Thread $t)
    {
        if ($t->created_by_user_id) {
            $u = $t->created_by_user;
            return $u ? ['id' => $u->id, 'type' => 'user', 'email' => $u->email] : null;
        }
        if ($t->created_by_customer_id) {
            $cu = $t->customer;
            return $cu ? ['id' => $cu->id, 'type' => 'customer', 'email' => $cu->getMainEmail()] : null;
        }
        return null;
    }
}
