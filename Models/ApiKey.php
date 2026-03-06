<?php

namespace Modules\ApiWebhooks\Models;

use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    protected $table = 'api_keys';

    protected $fillable = ['name', 'api_key', 'api_secret', 'allowed_ips', 'created_by_user_id', 'active'];

    protected $hidden = ['api_secret'];

    public function createdBy()
    {
        return $this->belongsTo(\App\User::class, 'created_by_user_id');
    }

    public function logs()
    {
        return $this->hasMany(ApiLog::class, 'api_key_id');
    }

    public function getAllowedIpsArray()
    {
        if (empty($this->allowed_ips)) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $this->allowed_ips)));
    }

    public function isIpAllowed($ip)
    {
        $allowed = $this->getAllowedIpsArray();
        if (empty($allowed)) {
            return true; // No restriction = all IPs allowed.
        }
        foreach ($allowed as $pattern) {
            if ($pattern === $ip) {
                return true;
            }
            // CIDR support: 192.168.1.0/24
            if (strpos($pattern, '/') !== false && self::ipInCidr($ip, $pattern)) {
                return true;
            }
        }
        return false;
    }

    public static function ipInCidr($ip, $cidr)
    {
        list($subnet, $bits) = explode('/', $cidr, 2);
        $subnet = ip2long($subnet);
        $ip = ip2long($ip);
        if ($subnet === false || $ip === false) {
            return false;
        }
        $mask = -1 << (32 - (int)$bits);
        return ($ip & $mask) === ($subnet & $mask);
    }

    public static function generateKey()
    {
        return bin2hex(random_bytes(24));
    }

    public static function generateSecret()
    {
        return bin2hex(random_bytes(32));
    }
}
