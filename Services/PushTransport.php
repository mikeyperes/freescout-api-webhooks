<?php

namespace Modules\ApiWebhooks\Services;

use App\Option;
use GuzzleHttp\Client;

class PushTransport
{
    protected $client;

    public function __construct(?\GuzzleHttp\ClientInterface $client = null)
    {
        $this->client = $client ?: new Client(['timeout' => 15, 'connect_timeout' => 5,
            'http_errors' => false, 'allow_redirects' => false]);
    }

    protected function credential($name)
    {
        $value = Option::get('apiwebhooks_'.$name, '');
        if (!$value) {
            throw new \RuntimeException('Push credentials are not configured.');
        }
        return decrypt($value);
    }

    protected function base64Url($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function jwt(array $header, array $claims, $privateKey, $algorithm)
    {
        $input = $this->base64Url(json_encode($header)).'.'.$this->base64Url(json_encode($claims));
        if (!openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Unable to sign push credential.');
        }
        if ($algorithm === 'ES256') {
            // OpenSSL emits ASN.1 DER; JOSE requires the two unsigned 32-byte integers.
            if (strlen($signature) < 8 || ord($signature[0]) !== 48 || ord($signature[2]) !== 2) {
                throw new \RuntimeException('Invalid ES256 signature.');
            }
            $rLength = ord($signature[3]);
            $offset = 4 + $rLength;
            if (!isset($signature[$offset + 1]) || ord($signature[$offset]) !== 2) {
                throw new \RuntimeException('Invalid ES256 signature.');
            }
            $r = ltrim(substr($signature, 4, $rLength), "\0");
            $s = ltrim(substr($signature, $offset + 2, ord($signature[$offset + 1])), "\0");
            if (strlen($r) > 32 || strlen($s) > 32) {
                throw new \RuntimeException('Invalid ES256 signature width.');
            }
            $signature = str_pad($r, 32, "\0", STR_PAD_LEFT).str_pad($s, 32, "\0", STR_PAD_LEFT);
        }
        return $input.'.'.$this->base64Url($signature);
    }

    protected function appleToken(array $settings)
    {
        return app(PushCredentialCache::class)->get('apns', $settings, ApiSettings::APNS_REFRESH_MARGIN_SECONDS, function () use ($settings) {
            return ['token' => $this->jwt(['alg' => 'ES256', 'kid' => $settings['key_id']],
                ['iss' => $settings['team_id'], 'iat' => time()], $settings['private_key'], 'ES256'),
                'ttl' => ApiSettings::APNS_TOKEN_SECONDS];
        });
    }

    protected function firebaseToken(array $settings)
    {
        return app(PushCredentialCache::class)->get('fcm', $settings, ApiSettings::OAUTH_REFRESH_MARGIN_SECONDS, function () use ($settings) {
            $assertion = $this->jwt(['alg' => 'RS256', 'typ' => 'JWT'], [
                'iss' => $settings['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(),
                'exp' => time() + ApiSettings::OAUTH_TOKEN_SECONDS,
            ], $settings['private_key'], 'RS256');
            $response = $this->client->post('https://oauth2.googleapis.com/token', ['form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion,
            ]]);
            $oauth = json_decode($response->getBody()->getContents(), true);
            if ($response->getStatusCode() !== 200 || empty($oauth['access_token'])) {
                throw new \RuntimeException('FCM authentication was refused.');
            }
            return ['token' => $oauth['access_token'], 'ttl' => max(1, (int) ($oauth['expires_in'] ?? ApiSettings::OAUTH_TOKEN_SECONDS))];
        });
    }

    public function send($device, array $payload)
    {
        $destination = decrypt($device->destination);
        if ($device->provider === 'apns') {
            $settings = json_decode($this->credential('apns_credentials'), true);
            if (empty($settings['team_id']) || empty($settings['key_id']) || empty($settings['private_key']) || empty($settings['topic'])) {
                throw new \RuntimeException('APNs configuration is incomplete.');
            }
            $jwt = $this->appleToken($settings);
            $host = !empty($settings['sandbox']) ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
            $response = $this->client->post($host.'/3/device/'.rawurlencode($destination), [
                'version' => 2.0,
                'headers' => ['authorization' => 'bearer '.$jwt, 'apns-topic' => $settings['topic'], 'apns-push-type' => 'alert'],
                'json' => ['aps' => ['alert' => ['title' => ApiSettings::PUSH_TITLE, 'body' => ApiSettings::PUSH_BODY],
                    'sound' => 'default'], 'data' => $payload],
            ]);
            return $response->getStatusCode();
        }
        $settings = json_decode($this->credential('fcm_credentials'), true);
        if (empty($settings['client_email']) || empty($settings['private_key']) || empty($settings['project_id'])
            || !preg_match('/\A[a-z][a-z0-9-]{4,62}\z/D', $settings['project_id'])) {
            throw new \RuntimeException('FCM service account configuration is incomplete.');
        }
        $oauth = $this->firebaseToken($settings);
        $data = [];
        foreach ($payload as $key => $value) {
            $data[$key] = (string) $value;
        }
        $response = $this->client->post('https://fcm.googleapis.com/v1/projects/'.$settings['project_id'].'/messages:send', [
            'headers' => ['Authorization' => 'Bearer '.$oauth],
            'json' => ['message' => ['token' => $destination,
                'notification' => ['title' => ApiSettings::PUSH_TITLE, 'body' => ApiSettings::PUSH_BODY], 'data' => $data]],
        ]);
        return $response->getStatusCode();
    }
}
