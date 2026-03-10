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
     * Log an API request with full detail.
     */
    public static function log($apiKeyId, $request, $statusCode, $responseSummary = '', $startTime = null)
    {
        // Sanitize request body — strip sensitive fields
        $body = $request->except(['api_key', 'api_secret', 'password', '_token']);

        // Capture headers — strip sensitive ones
        $headers = $request->headers->all();
        $sensitiveHeaders = ['cookie', 'authorization', 'x-api-key', 'x-api-secret', 'php-auth-pw'];
        foreach ($sensitiveHeaders as $sh) {
            if (isset($headers[$sh])) {
                $headers[$sh] = ['***REDACTED***'];
            }
        }

        // Get user agent
        $userAgent = $request->header('User-Agent', '');

        // Get query string
        $queryString = $request->getQueryString();

        // Geo lookup via IP (lightweight, cached 24h)
        $geoData = self::geoLookup($request->ip());

        self::create([
            'api_key_id'       => $apiKeyId,
            'method'           => $request->method(),
            'endpoint'         => $request->path(),
            'ip'               => $request->ip(),
            'user_agent'       => mb_substr($userAgent, 0, 500),
            'request_headers'  => json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'query_string'     => $queryString ? mb_substr($queryString, 0, 2000) : null,
            'country'          => $geoData['country'] ?? null,
            'city'             => $geoData['city'] ?? null,
            'status_code'      => $statusCode,
            'request_body'     => !empty($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : null,
            'response_summary' => mb_substr($responseSummary, 0, 500),
            'response_time_ms' => $startTime ? round((microtime(true) - $startTime) * 1000) : 0,
            'created_at'       => now(),
        ]);
    }

    /**
     * Lightweight geo lookup using ip-api.com (free, no key, 45 req/min).
     * Cached 24 hours per IP.
     */
    protected static function geoLookup($ip)
    {
        // Skip private/local IPs
        if (in_array($ip, ['127.0.0.1', '::1']) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['country' => 'Local', 'city' => 'Local'];
        }

        $cacheKey = 'api_geo_' . md5($ip);
        $cached = \Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 3]);
            $response = $client->get("http://ip-api.com/json/{$ip}?fields=status,country,city");
            $data = json_decode($response->getBody()->getContents(), true);

            if (!empty($data['status']) && $data['status'] === 'success') {
                $result = [
                    'country' => $data['country'] ?? null,
                    'city'    => $data['city'] ?? null,
                ];
                \Cache::put($cacheKey, $result, 60 * 24); // 24 hours
                return $result;
            }
        } catch (\Exception $e) {
            // Silently fail — geo is non-critical
        }

        return ['country' => null, 'city' => null];
    }

    /**
     * Get created_at in EST timezone.
     */
    public function getTimeEstAttribute()
    {
        if (!$this->created_at) {
            return '';
        }
        return $this->created_at->copy()->setTimezone('America/New_York')->format('M j, Y g:i:s A');
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
