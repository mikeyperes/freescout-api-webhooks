<?php

namespace Modules\ApiWebhooks\Services;

class TransportSecurity
{
    public function isSecure($request)
    {
        // CRITICAL - see BUGLOG.md API-008.
        // FreeScout overrides Request::isSecure() to consult app.url. That does not
        // establish that this particular connection used TLS.
        $https = strtolower((string) $request->server('HTTPS', ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }
        if ($request->isFromTrustedProxy()
            && ($request->headers->has('X-Forwarded-Proto') || $request->headers->has('Forwarded'))) {
            return $request->isSecure();
        }
        return false;
    }
}
