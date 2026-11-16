<?php

namespace Modules\ApiWebhooks\Models;

use Illuminate\Database\Eloquent\Model;

class ApiLog extends Model
{
    protected $table = 'api_logs';

    public $timestamps = false;

    protected $fillable = [
        'api_key_id', 'method', 'endpoint', 'ip',
        'user_agent', 'request_headers', 'query_string',
        'country', 'city',
        'status_code', 'request_body', 'response_summary',
        'response_time_ms', 'created_at',
    ];

    protected $dates = ['created_at'];

    public function apiKey()
    {
        return $this->belongsTo(ApiKey::class, 'api_key_id');
    }

    /**
     * Log request metadata without content or credentials.
     */
    public static function log($apiKeyId, $request, $statusCode, $responseSummary = '', $startTime = null)
    {
        self::create([
            'api_key_id'       => $apiKeyId,
            'method'           => $request->method(),
            'endpoint'         => $request->path(),
            'ip'               => $request->ip(),
            'user_agent'       => null,
            'request_headers'  => null,
            'query_string'     => null,
            'country'          => null,
            'city'             => null,
            'status_code'      => $statusCode,
            'request_body'     => null,
            'response_summary' => 'HTTP ' . (int) $statusCode,
            'response_time_ms' => $startTime ? round((microtime(true) - $startTime) * 1000) : 0,
            'created_at'       => now(),
        ]);
    }

    /**
     * Get created_at in EST timezone.
     */
    public function getTimeEstAttribute()
    {
        if (!$this->created_at) {
            return '';
        }
        return $this->created_at->copy()->setTimezone('-05:00')->format('M j, Y g:i:s A');
    }

    /**
     * Parse user agent into a readable device/client summary.
     */
    public function getDeviceSummaryAttribute()
    {
        $ua = $this->user_agent;
        if (empty($ua)) {
            return 'Unknown';
        }

        // Detect API clients
        if (stripos($ua, 'curl') !== false) return 'curl (CLI)';
        if (stripos($ua, 'postman') !== false) return 'Postman';
        if (stripos($ua, 'insomnia') !== false) return 'Insomnia';
        if (stripos($ua, 'python') !== false) return 'Python';
        if (stripos($ua, 'node') !== false || stripos($ua, 'axios') !== false) return 'Node.js';
        if (stripos($ua, 'guzzle') !== false) return 'Guzzle (PHP)';
        if (stripos($ua, 'n8n') !== false) return 'n8n';
        if (stripos($ua, 'zapier') !== false) return 'Zapier';

        // Detect OS
        $os = 'Unknown OS';
        if (stripos($ua, 'windows') !== false) $os = 'Windows';
        elseif (stripos($ua, 'mac') !== false) $os = 'macOS';
        elseif (stripos($ua, 'linux') !== false) $os = 'Linux';
        elseif (stripos($ua, 'android') !== false) $os = 'Android';
        elseif (stripos($ua, 'iphone') !== false || stripos($ua, 'ipad') !== false) $os = 'iOS';

        // Detect browser
        $browser = '';
        if (stripos($ua, 'edg') !== false) $browser = 'Edge';
        elseif (stripos($ua, 'chrome') !== false) $browser = 'Chrome';
        elseif (stripos($ua, 'firefox') !== false) $browser = 'Firefox';
        elseif (stripos($ua, 'safari') !== false) $browser = 'Safari';

        return trim(($browser ? $browser . ' / ' : '') . $os);
    }
}
