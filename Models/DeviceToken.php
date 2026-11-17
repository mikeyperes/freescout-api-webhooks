<?php

namespace Modules\ApiWebhooks\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceToken extends Model
{
    protected $table = 'api_device_tokens';
    protected $guarded = ['id'];
    protected $hidden = ['token_hash', 'allowed_ips'];
    protected $dates = ['expires_at', 'revoked_at', 'last_used_at'];

    public function user()
    {
        return $this->belongsTo(\App\User::class);
    }

    public static function issue($user, $name, $level, $kind = 'device', $allowedIps = null)
    {
        abort_unless(in_array($level, ['own', 'broad', 'root'], true), 422);
        abort_if($level === 'root' && !$user->isAdmin(), 403);
        abort_unless($user->isActive(), 403);
        abort_if($kind === 'server' && !$allowedIps, 422);
        $plain = bin2hex(random_bytes(32));
        $token = self::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'kind' => $kind,
            'access_level' => $level,
            'allowed_ips' => $allowedIps,
            'expires_at' => now()->addDays(\Modules\ApiWebhooks\Services\ApiSettings::value('token_days')),
        ]);
        return [$token, $plain];
    }
}
