<?php

namespace Modules\ApiWebhooks\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ApiWebhooks\Services\AgentAccess;
use Modules\ApiWebhooks\Services\AgentSyncChanges;
use Modules\ApiWebhooks\Services\ConversationPresenter;

class MobileSyncController extends Controller
{
    public function index(Request $request, AgentAccess $access)
    {
        $request->validate(['since' => 'date', 'cursor' => 'string', 'per_page' => 'integer|min:1|max:100']);
        $token = $request->attributes->get('device_token');
        if ($request->filled('cursor')) {
            try {
                $state = json_decode(decrypt($request->input('cursor')), true);
            } catch (\Exception $exception) {
                abort(422, 'Invalid cursor.');
            }
            abort_unless(is_array($state) && ($state['token_id'] ?? null) === $token->id, 422);
            abort_unless(($state['expires'] ?? 0) >= time(), 422);
        } else {
            $state = [
                'token_id' => $token->id,
                'since' => $request->filled('since') ? Carbon::parse($request->input('since'))->setTimezone('UTC')->toDateTimeString() : '1970-01-01 00:00:00',
                'until' => now()->subSecond()->toDateTimeString(),
                'last_time' => null,
                'last_id' => 0,
                'expires' => time() + 3600,
            ];
        }
        $time = AgentSyncChanges::timestamp();
        $query = app(AgentSyncChanges::class)->query($access->query($request), $request->user())
            ->whereRaw($time.' >= ?', [$state['since']])->whereRaw($time.' <= ?', [$state['until']]);
        if ($state['last_time']) {
            $query->whereRaw('('.$time.' > ? OR ('.$time.' = ? AND conversations.id > ?))',
                [$state['last_time'], $state['last_time'], $state['last_id']]);
        }
        $result = $access->slice($request, $query->orderByRaw($time.' ASC')->orderBy('conversations.id'));
        $last = $result['rows']->last();
        if ($last) {
            $state['last_time'] = $last->sync_updated_at;
            $state['last_id'] = $last->id;
        }
        $items = ConversationPresenter::page($access->visibleRows($request, $result['rows']), $request->user());
        return response()->json([
            'data' => $items,
            'next_cursor' => $result['has_more'] ? encrypt(json_encode($state)) : null,
            // Inclusive overlap handles multiple updates with FreeScout's second-resolution timestamps.
            'next_since' => Carbon::parse($state['until'], 'UTC')->toIso8601String(),
            'requires_visibility_refresh' => true,
        ]);
    }
}
