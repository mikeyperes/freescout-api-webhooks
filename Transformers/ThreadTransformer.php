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

    /**
     * Transform a Thread model into an API-friendly array.
     *
     * @param  \App\Thread  $t
     * @return array
     */
    public static function transform(Thread $t)
    {
        $conversation = $t->conversation;

        return [
            'id'             => $t->id,
            'conversationId' => $t->conversation_id,
            'subject'        => $conversation ? $conversation->subject : null,
            'type'           => self::$typeMap[$t->type] ?? 'unknown',
            'status'         => ConversationTransformer::$statusMap[$t->status] ?? 'nochange',
            'state'          => ConversationTransformer::$stateMap[$t->state] ?? 'unknown',
            'body'           => $t->body,
            'bodyPreview'    => self::bodyPreview($t->body),
            'from'           => $t->from,
            'to'             => $t->getToArray(),
            'cc'             => $t->getCcArray(),
            'bcc'            => $t->getBccArray(),
            'customer'       => $t->customer_id ? CustomerTransformer::brief($t->customer) : null,
            'createdBy'      => self::createdBy($t),
            'assignedTo'     => $t->user_id ? UserTransformer::brief($t->user) : null,
            'hasAttachments' => (bool) $t->has_attachments,
            'attachments'    => self::buildAttachments($t),
            'createdAt'      => $t->created_at ? date('c', strtotime($t->created_at)) : null,
            'openedAt'       => $t->opened_at ? date('c', strtotime($t->opened_at)) : null,
        ];
    }

    /**
     * Return first 200 characters of the thread body as plain text.
     *
     * @param  string|null  $html
     * @return string|null
     */
    private static function bodyPreview($html)
    {
        if ($html === null || $html === '') {
            return null;
        }

        $text = trim(strip_tags($html));
        // Collapse whitespace.
        $text = preg_replace('/\s+/', ' ', $text);

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200) . '...' : $text;
    }

    /**
     * Build the attachments array for a thread.
     *
     * Returns non-embedded attachments with id, filename, mime type, size, and
     * download URL. Falls back to an empty array when there are no attachments.
     *
     * @param  \App\Thread  $t
     * @return array
     */
    private static function buildAttachments(Thread $t)
    {
        if (!$t->has_attachments) {
            return [];
        }

        try {
            $attachments = $t->attachments;
        } catch (\Exception $e) {
            return [];
        }

        if (!$attachments || $attachments->isEmpty()) {
            return [];
        }

        return $attachments->map(function (\App\Attachment $a) {
            return [
                'id'       => $a->id,
                'filename' => $a->file_name,
                'url'      => $a->url(),
                'mimeType' => $a->mime_type,
                'size'     => $a->size,
            ];
        })->values()->toArray();
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
