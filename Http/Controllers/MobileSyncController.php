<?php

namespace Modules\ApiWebhooks\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ApiWebhooks\Services\AgentAccess;
use Modules\ApiWebhooks\Transformers\ConversationTransformer;
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
        $query = $access->query($request)->where('updated_at', '>=', $state['since'])->where('updated_at', '<=', $state['until']);
        if ($state['last_time']) {
            $query->where(function ($after) use ($state) {
                $after->where('updated_at', '>', $state['last_time'])->orWhere(function ($same) use ($state) {
                    $same->where('updated_at', $state['last_time'])->where('id', '>', $state['last_id']);
                });
            });
        }
        $items = [];
        $limit = (int) $request->input('per_page', 50);
        $hasMore = false;
        foreach ($query->orderBy('updated_at')->orderBy('id')->cursor() as $conversation) {
            if (!$request->user()->can('view', $conversation)) {
                continue;
            }
            if (count($items) === $limit) {
                $hasMore = true;
                break;
            }
            $items[] = ConversationPresenter::transform($conversation);
            $state['last_time'] = $conversation->updated_at->toDateTimeString();
            $state['last_id'] = $conversation->id;
        }
        return response()->json([
            'data' => $items,
            'next_cursor' => $hasMore ? encrypt(json_encode($state)) : null,
            // Inclusive overlap handles multiple updates with FreeScout's second-resolution timestamps.
            'next_since' => Carbon::parse($state['until'], 'UTC')->toIso8601String(),
            'requires_visibility_refresh' => true,
        ]);
    }
}
