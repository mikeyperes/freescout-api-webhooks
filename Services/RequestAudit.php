<?php

namespace Modules\ApiWebhooks\Services;

use Illuminate\Support\Facades\DB;

class RequestAudit
{
    public function append($request, $token, $status)
    {
        DB::transaction(function () use ($request, $token, $status) {
            $chain = DB::table('api_audit_chain')->where('id', 1)->lockForUpdate()->first();
            $metadata = json_encode([
                'method' => $request->method(),
                'path' => $request->route() ? $request->route()->uri() : 'unmatched',
                'ip' => $request->ip(),
                'status' => $status,
                'token_id' => $token ? $token->id : null,
                'time' => now()->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES);
            $hash = hash_hmac('sha256', $chain->tail_hash."\n".$metadata, config('app.key'));
            DB::table('api_audit_entries')->insert([
                'token_id' => $token ? $token->id : null,
                'metadata' => $metadata,
                'previous_hash' => $chain->tail_hash,
                'entry_hash' => $hash,
                'created_at' => now(),
            ]);
            DB::table('api_audit_chain')->where('id', 1)->update(['tail_hash' => $hash]);
        });
    }

    public function prune()
    {
        $before = now()->subDays(ApiSettings::value('log_retention_days'));
        DB::transaction(function () use ($before) {
            DB::table('api_audit_chain')->where('id', 1)->lockForUpdate()->first();
            $last = DB::table('api_audit_entries')->where('created_at', '<', $before)->orderBy('id', 'desc')->first();
            if ($last) {
                DB::table('api_audit_chain')->where('id', 1)->update(['anchor_hash' => $last->entry_hash]);
                DB::table('api_audit_entries')->where('id', '<=', $last->id)->delete();
            }
            DB::table('api_deliveries')->where('created_at', '<', $before)->where(function ($finished) {
                $finished->whereNotNull('delivered_at')->orWhereNull('next_attempt_at');
            })->delete();
        });
    }
}
