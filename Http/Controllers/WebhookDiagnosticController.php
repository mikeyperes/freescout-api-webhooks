<?php

namespace Modules\ApiWebhooks\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WebhookDiagnosticController extends Controller
{
    public function receive(Request $request, $challenge)
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/D', $challenge), 404);
        $encrypted = \Cache::get('apiwebhooks:diagnostic:'.$challenge);
        abort_unless($encrypted, 404);
        $timestamp = (string) $request->header('X-FreeScout-Timestamp');
        $eventId = (string) $request->header('X-FreeScout-Event');
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= \Modules\ApiWebhooks\Services\ApiSettings::SIGNATURE_PAST_SECONDS, 401);
        $expected = hash_hmac('sha256', $timestamp.'.'.$eventId.'.'.$request->getContent(), decrypt($encrypted));
        abort_unless(hash_equals($expected, (string) $request->header('X-FreeScout-Signature')), 401);
        $body = json_decode($request->getContent(), true);
        abort_unless(is_array($body) && ($body['event'] ?? null) === 'api.test', 422);
        \Cache::forget('apiwebhooks:diagnostic:'.$challenge);
        \Cache::put('apiwebhooks:diagnostic-receipt:'.$challenge, $eventId, 1);
        return response('', 204);
    }
}
