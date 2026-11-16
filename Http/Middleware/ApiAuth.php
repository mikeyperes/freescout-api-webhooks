<?php

namespace Modules\ApiWebhooks\Http\Middleware;

use Closure;
use Modules\ApiWebhooks\Models\ApiKey;
use Modules\ApiWebhooks\Models\ApiLog;

/**
 * ApiAuth — Authenticates API requests via X-Api-Key header.
 * Validates key existence, active status, IP whitelist, and rate limits.
 * Logs all requests (successful and failed) for audit trail.
 */
class ApiAuth
{
    /**
     * Handle an incoming API request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return \Illuminate\Http\Response
     */
    public function handle($request, Closure $next)
    {
        $startTime = microtime(true);

        // SECURITY: Only accept API key via X-Api-Key header.
        // Query param auth is intentionally NOT supported — keys in URLs
        // leak via server logs, Referer headers, and browser history.
        $key = $request->header('X-Api-Key');

        if (empty($key)) {
            ApiLog::log(null, $request, 401, 'Missing API key');
            return response()->json([
                'status'  => 'error',
                'message' => 'API key required. Pass via X-Api-Key header.',
            ], 401);
        }

        // Validate key format: 48 hex characters only.
        if (!preg_match('/^[a-f0-9]{48}$/', $key)) {
            ApiLog::log(null, $request, 401, 'Invalid key format');
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid API key format.',
            ], 401);
        }

        $apiKey = ApiKey::where('api_key', hash('sha256', $key))->where('active', true)->first();

        if (!$apiKey) {
            ApiLog::log(null, $request, 401, 'Key not found or disabled');
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid or disabled API key.',
            ], 401);
        }

        // IP whitelist enforcement — keys with no whitelist deny all requests.
        if (!$apiKey->isIpAllowed($request->ip())) {
            $hasWhitelist = !empty($apiKey->getAllowedIpsArray());
            $detail = $hasWhitelist
                ? 'IP not whitelisted: ' . $request->ip()
                : 'No IPs configured for key — all requests denied: ' . $request->ip();

            ApiLog::log($apiKey->id, $request, 403, $detail);
            return response()->json([
                'status'  => 'error',
                'message' => 'IP address not allowed.',
            ], 403);
        }

        $scope = self::requiredScope($request->route()->getActionMethod(), $request->method());
        if (!$apiKey->hasScope($scope)) {
            ApiLog::log($apiKey->id, $request, 403, 'Scope denied');
            return response()->json(['status' => 'error', 'message' => 'Required scope: ' . $scope], 403);
        }

        $limiter = app(\Illuminate\Cache\RateLimiter::class);
        $limitKey = 'apiwebhooks:v1:' . $apiKey->id;
        if ($limiter->tooManyAttempts($limitKey, 60, 1)) {
            return response()->json(['status' => 'error', 'message' => 'Rate limit exceeded.'], 429)
                ->header('Retry-After', $limiter->availableIn($limitKey));
        }
        $limiter->hit($limitKey, 1);

        // Store for use in controllers and post-request logging.
        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('api_start_time', $startTime);

        $response = $next($request);

        // Record status metadata without response content.
        $summary = 'HTTP ' . $response->getStatusCode();
        ApiLog::log($apiKey->id, $request, $response->getStatusCode(), $summary, $startTime);

        return $response;
    }

    public static function requiredScope($action, $method)
    {
        if ($method === 'GET') {
            return 'read';
        }
        $scopes = [
            'createConversation' => 'conversations:write',
            'updateConversation' => 'conversations:write',
            'deleteConversation' => 'conversations:delete',
            'createThread' => 'threads:write',
            'createUser' => 'users:write',
            'disableUser' => 'users:write',
            'enableUser' => 'users:write',
            'testSmtp' => 'mailboxes:test',
            'testImap' => 'mailboxes:test',
            'sendTestEmail' => 'admin',
        ];
        return isset($scopes[$action]) ? $scopes[$action] : 'admin';
    }
}
