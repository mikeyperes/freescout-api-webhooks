<?php

namespace Modules\ApiWebhooks\Http\Middleware;

use Closure;
use Modules\ApiWebhooks\Models\ApiKey;
use Modules\ApiWebhooks\Models\ApiLog;

class ApiAuth
{
    public function handle($request, Closure $next)
    {
        $startTime = microtime(true);

        // Extract API key from X-Api-Key header or query param.
        $key = $request->header('X-Api-Key') ?: $request->input('api_key');

        if (empty($key)) {
            ApiLog::log(null, $request, 401, 'Missing API key');
            return response()->json(['status' => 'error', 'message' => 'API key required.'], 401);
        }

        // Sanitize: only hex chars allowed in key.
        if (!preg_match('/^[a-f0-9]{48}$/', $key)) {
            ApiLog::log(null, $request, 401, 'Invalid key format');
            return response()->json(['status' => 'error', 'message' => 'Invalid API key format.'], 401);
        }

        $apiKey = ApiKey::where('api_key', $key)->where('active', true)->first();

        if (!$apiKey) {
            ApiLog::log(null, $request, 401, 'Key not found or disabled');
            return response()->json(['status' => 'error', 'message' => 'Invalid or disabled API key.'], 401);
        }

        // IP whitelist check.
        if (!$apiKey->isIpAllowed($request->ip())) {
            ApiLog::log($apiKey->id, $request, 403, 'IP not whitelisted: ' . $request->ip());
            return response()->json(['status' => 'error', 'message' => 'IP address not allowed.'], 403);
        }

        // Rate limiting: 60 requests per minute per key.
        $recentCount = ApiLog::where('api_key_id', $apiKey->id)
            ->where('created_at', '>=', now()->subMinute())
            ->count();

        if ($recentCount >= 60) {
            ApiLog::log($apiKey->id, $request, 429, 'Rate limited');
            return response()->json(['status' => 'error', 'message' => 'Rate limit exceeded. Max 60 requests/minute.'], 429);
        }

        // Store for use in controllers and logging.
        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('api_start_time', $startTime);

        $response = $next($request);

        // Log successful request.
        $summary = mb_substr($response->getContent(), 0, 200);
        ApiLog::log($apiKey->id, $request, $response->getStatusCode(), $summary, $startTime);

        return $response;
    }
}
