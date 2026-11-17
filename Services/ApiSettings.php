<?php

namespace Modules\ApiWebhooks\Services;

use App\Option;

class ApiSettings
{
    public static function limits()
    {
        return [
            'token_days' => ['label' => 'Device credential lifetime (days)', 'default' => 30, 'max' => 365],
            'requests_per_minute' => ['label' => 'Requests per token per minute', 'default' => 60, 'max' => 10000],
            'log_retention_days' => ['label' => 'Log retention (days)', 'default' => 30, 'max' => 3650],
        ];
    }

    public static function value($name)
    {
        $definitions = self::limits();
        if (!isset($definitions[$name])) {
            throw new \InvalidArgumentException('Unknown mobile API limit.');
        }
        return max(1, (int) Option::get('apiwebhooks_'.$name, $definitions[$name]['default']));
    }

    public function save($request)
    {
        $rules = ['apns_credentials' => 'nullable|json|max:16384', 'fcm_credentials' => 'nullable|json|max:32768'];
        foreach (self::limits() as $name => $definition) {
            $rules[$name] = 'required|integer|min:1|max:'.$definition['max'];
        }
        $request->validate($rules);
        $credentials = [];
        foreach (['apns', 'fcm'] as $provider) {
            if ($request->filled($provider.'_credentials')) {
                $settings = json_decode($request->input($provider.'_credentials'), true);
                abort_unless(is_array($settings), 422);
                $this->validateCredential($provider, $settings);
                $credentials[$provider] = encrypt(json_encode($settings));
            }
        }
        foreach ($credentials as $provider => $encrypted) {
            Option::set('apiwebhooks_'.$provider.'_credentials', $encrypted);
        }
        foreach (self::limits() as $name => $definition) {
            Option::set('apiwebhooks_'.$name, (int) $request->input($name));
        }
        activity(\App\ActivityLog::NAME_SYSTEM)->causedBy(auth()->user())->log('Mobile API settings updated');
    }

    protected function validateCredential($provider, array $settings)
    {
        $required = $provider === 'apns' ? ['team_id', 'key_id', 'private_key', 'topic'] : ['client_email', 'private_key', 'project_id'];
        foreach ($required as $field) {
            abort_unless(isset($settings[$field]) && is_string($settings[$field]) && $settings[$field] !== '', 422);
            if ($field !== 'private_key') {
                abort_if(preg_match('/[\r\n]/', $settings[$field]), 422);
            }
        }
        $key = openssl_pkey_get_private($settings['private_key']);
        abort_unless($key, 422);
        $details = openssl_pkey_get_details($key);
        abort_unless($provider === 'apns' ? $details['type'] === OPENSSL_KEYTYPE_EC : $details['type'] === OPENSSL_KEYTYPE_RSA, 422);
        if ($provider === 'apns') {
            abort_unless(($details['ec']['curve_name'] ?? '') === 'prime256v1', 422);
            abort_unless(preg_match('/\A[a-zA-Z0-9.-]+\z/D', $settings['topic']), 422);
        } else {
            abort_unless(filter_var($settings['client_email'], FILTER_VALIDATE_EMAIL), 422);
            abort_unless(preg_match('/\A[a-z][a-z0-9-]{4,62}\z/D', $settings['project_id']), 422);
        }
    }
}
