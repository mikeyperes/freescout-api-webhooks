<?php

namespace Modules\ApiWebhooks\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PushCredentialCache
{
    public function get($provider, array $settings, $refreshMargin, $factory)
    {
        $key = 'apiwebhooks:push-auth:'.$provider.':'.hash('sha256', json_encode($settings));
        $cached = $this->read($key);
        if ($cached && $cached['expires_at'] - $refreshMargin > time()) {
            return $cached['token'];
        }
        // Laravel 5.5's file-cache add() is not atomic. A connection-owned MySQL
        // advisory lock serializes refreshes and is released if the worker dies.
        $lock = hash('sha256', $key);
        $result = DB::select('SELECT GET_LOCK(?, 0) AS acquired', [$lock]);
        if (!$result || (int) $result[0]->acquired !== 1) {
            if ($cached && $cached['expires_at'] > time()) {
                return $cached['token'];
            }
            throw new \RuntimeException('Push authentication refresh is in progress.');
        }
        try {
            $cached = $this->read($key);
            if ($cached && $cached['expires_at'] - $refreshMargin > time()) {
                return $cached['token'];
            }
            $credential = $factory();
            $value = ['token' => $credential['token'], 'expires_at' => time() + $credential['ttl']];
            Cache::put($key, encrypt(json_encode($value)), ceil($credential['ttl'] / 60));
            return $value['token'];
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    protected function read($key)
    {
        $encrypted = Cache::get($key);
        return $encrypted ? json_decode(decrypt($encrypted), true) : null;
    }
}
