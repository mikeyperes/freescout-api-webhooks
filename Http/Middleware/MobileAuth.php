<?php

namespace Modules\ApiWebhooks\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Modules\ApiWebhooks\Models\ApiKey;
use Modules\ApiWebhooks\Models\DeviceToken;
use Modules\ApiWebhooks\Services\RequestAudit;
use Modules\ApiWebhooks\Services\ApiSettings;

class MobileAuth
{
    public function handle($request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');
        $token = null;
        $status = 500;
        try {
            abort_unless(app(\Modules\ApiWebhooks\Services\TransportSecurity::class)->isSecure($request), 403);
            $plain = $request->bearerToken();
            $server = $request->header('X-Api-Key');
            abort_if($plain && $server, 401);
            $plain = $plain ?: $server;
            abort_unless(is_string($plain) && preg_match('/\A[a-f0-9]{64}\z/D', $plain), 401);
            $token = DeviceToken::where('token_hash', hash('sha256', $plain))
                ->whereNull('revoked_at')->where('expires_at', '>', now())->first();
            abort_unless($token && $token->user && $token->user->isActive(), 401);
            abort_if($token->access_level === 'root' && !$token->user->isAdmin(), 403);
            abort_if(($token->kind === 'server') !== (bool) $server, 401);
            if ($server) {
                $allowlist = new ApiKey(['allowed_ips' => $token->allowed_ips]);
                abort_unless($allowlist->isIpAllowed($request->ip()), 403);
                $this->verifySignature($request, $token, $plain);
            }
            $limiter = app(RateLimiter::class);
            $bucket = 'apiwebhooks:v2:'.$token->id;
            $limit = \Modules\ApiWebhooks\Services\ApiSettings::value('requests_per_minute');
            if ($limiter->tooManyAttempts($bucket, $limit, 1)) {
                $status = 429;
                return response()->json(['error' => 'rate_limited'], 429)
                    ->header('Retry-After', $limiter->availableIn($bucket));
            }
            $limiter->hit($bucket, 1);
            auth()->setUser($token->user);
            $request->setUserResolver(function () use ($token) { return $token->user; });
            $request->attributes->set('device_token', $token);
            $token->last_used_at = now();
            $token->save();
            $response = $next($request);
            $status = $response->getStatusCode();
            $response->headers->set('Cache-Control', 'no-store, private');
            return $response;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $status = 404;
            throw $exception;
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $status = 422;
            throw $exception;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $status = $exception->getStatusCode();
            throw $exception;
        } finally {
            app(RequestAudit::class)->append($request, $token, $status);
        }
    }

    public static function canonical($request)
    {
        // Unlike the Publish protocol, include the query string to bind list/sync parameters.
        return implode("\n", [
            $request->method(),
            $request->getRequestUri(),
            $request->header('X-Api-Timestamp', ''),
            $request->header('X-Api-Nonce', ''),
            hash('sha256', $request->getContent()),
        ]);
    }

    protected function verifySignature($request, $token, $plain)
    {
        $timestamp = (string) $request->header('X-Api-Timestamp', '');
        $age = time() - (int) $timestamp;
        abort_unless(ctype_digit($timestamp) && $age <= ApiSettings::SIGNATURE_PAST_SECONDS && $age >= -ApiSettings::SIGNATURE_FUTURE_SECONDS, 401);
        $nonce = (string) $request->header('X-Api-Nonce', '');
        abort_unless(preg_match('/\A[a-f0-9]{32,128}\z/D', $nonce), 401);
        $signature = (string) $request->header('X-Api-Signature', '');
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/D', $signature), 401);
        abort_unless(hash_equals(hash_hmac('sha256', self::canonical($request), $plain), $signature), 401);
        try {
            DB::table('api_nonces')->insert([
                'token_id' => $token->id,
                'nonce_hash' => hash('sha256', $nonce),
                'created_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($exception->getCode() == '23000') {
                abort(401, 'Nonce already used.');
            }
            throw $exception;
        }
    }
}
