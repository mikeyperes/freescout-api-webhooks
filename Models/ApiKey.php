<?php

namespace Modules\ApiWebhooks\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ApiKey — Manages API authentication keys for the FreeScout API module.
 * Each key has an optional IP whitelist; keys with no whitelist deny all requests.
 */
class ApiKey extends Model
{
    /** @var string */
    protected $table = 'api_keys';

    /** @var array */
    protected $fillable = ['name', 'api_key', 'api_secret', 'allowed_ips', 'created_by_user_id', 'active'];

    /** @var array Fields hidden from JSON serialization */
    protected $hidden = ['api_secret'];

    /**
     * Get the user who created this API key.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(\App\User::class, 'created_by_user_id');
    }

    /**
     * Get all API logs for this key.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function logs()
    {
        return $this->hasMany(ApiLog::class, 'api_key_id');
    }

    /**
     * Parse the allowed_ips field into an array.
     *
     * @return array
     */
    public function getAllowedIpsArray()
    {
        if (empty($this->allowed_ips)) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $this->allowed_ips)));
    }

    /**
     * Check if a given IP is allowed by this key's whitelist.
     * SECURITY: Empty whitelist = NO IPs allowed (all requests denied).
     *
     * @param string $ip The IP address to check
     * @return bool
     */
    public function isIpAllowed($ip)
    {
        $allowed = $this->getAllowedIpsArray();

        // Empty whitelist = deny all. Keys MUST have explicit IP whitelist.
        if (empty($allowed)) {
            return false;
        }

        foreach ($allowed as $pattern) {
            if ($pattern === $ip) {
                return true;
            }
            // CIDR support: e.g. 192.168.1.0/24
            if (strpos($pattern, '/') !== false && self::ipInCidr($ip, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an IP falls within a CIDR range.
     *
     * @param string $ip   The IP to check
     * @param string $cidr The CIDR range (e.g. 192.168.1.0/24)
     * @return bool
     */
    public static function ipInCidr($ip, $cidr)
    {
        list($subnet, $bits) = explode('/', $cidr, 2);
        $subnet = ip2long($subnet);
        $ip = ip2long($ip);
        if ($subnet === false || $ip === false) {
            return false;
        }
        $mask = -1 << (32 - (int) $bits);
        return ($ip & $mask) === ($subnet & $mask);
    }

    /**
     * Add an IP address to this key's whitelist.
     *
     * @param string $ip The IP address or CIDR to add
     * @return void
     */
    public function addAllowedIp($ip)
    {
        $ips = $this->getAllowedIpsArray();
        $ip = trim($ip);
        if ($ip && !in_array($ip, $ips)) {
            $ips[] = $ip;
            $this->allowed_ips = implode(', ', $ips);
            $this->save();
        }
    }

    /**
     * Generate a 48-character hex API key (24 random bytes).
     *
     * @return string
     */
    public static function generateKey()
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * Generate a 64-character hex API secret (32 random bytes).
     *
     * @return string
     */
    public static function generateSecret()
    {
        return bin2hex(random_bytes(32));
    }
}
