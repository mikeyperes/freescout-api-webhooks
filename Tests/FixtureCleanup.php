<?php

class FixtureCleanup
{
    public static function conversations(array $ids, $actorId)
    {
        $ids = App\Conversation::whereIn('id', $ids)->where('created_by_user_id', $actorId)
            ->where('subject', 'like', 'ticketapi-2 regression %')->pluck('id')->all();
        if (!$ids) {
            return;
        }
        $threads = App\Thread::whereIn('conversation_id', $ids)->pluck('id')->all();
        self::jobs($ids, $threads);
        App\Conversation::deleteConversationsForever($ids);
        DB::table('notifications')->whereIn('conversation_id', $ids)->delete();
        DB::table('send_logs')->whereIn('thread_id', $threads)->delete();
        DB::table('api_conversation_metadata')->whereIn('conversation_id', $ids)->delete();
        DB::table('api_agent_sync_changes')->whereIn('conversation_id', $ids)->delete();
        DB::table('api_deliveries')->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.conversation_id'))"), $ids)->delete();
    }

    public static function actorCustomer($actor, $previousCustomerId)
    {
        if ($previousCustomerId) {
            return;
        }
        $email = App\Email::where('email', $actor->email)->first();
        if ($email && $email->customer && !App\Conversation::where('customer_id', $email->customer_id)->exists()) {
            $email->customer->deleteCustomer();
        }
    }

    public static function attachments(array $ids, $actorId)
    {
        $ids = App\Attachment::whereIn('id', $ids)->where('user_id', $actorId)
            ->where('file_name', 'ticketapi-2.txt')->pluck('id')->all();
        App\Attachment::deleteByIds($ids);
    }

    public static function jobReferences($payload, array $ids, array $threads = [])
    {
        $data = json_decode($payload, true);
        if (!isset($data['data']['command']) || !is_string($data['data']['command'])) {
            return false;
        }
        try {
            $command = unserialize($data['data']['command'], ['allowed_classes' => false]);
        } catch (\Exception $exception) {
            return false;
        }
        $references = function ($value) use (&$references, $ids, $threads) {
            if (!is_array($value) && !is_object($value)) {
                return false;
            }
            $values = (array) $value;
            if (isset($values['class'], $values['id'])) {
                $modelIds = (array) $values['id'];
                if (($values['class'] === App\Conversation::class && array_intersect($modelIds, $ids))
                    || ($values['class'] === App\Thread::class && array_intersect($modelIds, $threads))) {
                    return true;
                }
            }
            if (isset($values['conversation_id']) && in_array($values['conversation_id'], $ids)) {
                return true;
            }
            foreach ($values as $child) {
                if ($references($child)) {
                    return true;
                }
            }
            return false;
        };
        return $references($command);
    }

    protected static function jobs(array $ids, array $threads)
    {
        $deadline = microtime(true) + 30;
        do {
            $running = false;
            foreach (DB::table('jobs')->get(['id', 'payload', 'reserved_at']) as $job) {
                if (!self::jobReferences($job->payload, $ids, $threads)) {
                    continue;
                }
                if ($job->reserved_at || !DB::table('jobs')->where('id', $job->id)->whereNull('reserved_at')->delete()) {
                    $running = true;
                }
            }
            if ($running) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('A fixture queue job is still executing; its conversation was preserved.');
                }
                usleep(250000);
            }
        } while ($running);
        foreach (DB::table('failed_jobs')->get(['id', 'payload']) as $job) {
            if (self::jobReferences($job->payload, $ids, $threads)) {
                DB::table('failed_jobs')->where('id', $job->id)->delete();
            }
        }
    }
}
