<?php

namespace Modules\ApiWebhooks\Services;

use GuzzleHttp\Client;

class WebhookTransport
{
    protected $client;

    public function __construct(?\GuzzleHttp\ClientInterface $client = null)
    {
        $this->client = $client ?: new Client(['timeout' => 15, 'connect_timeout' => 5,
            'http_errors' => false, 'allow_redirects' => false]);
    }

    public function destination($url)
    {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new \InvalidArgumentException('Webhook URL must use HTTPS on port 443 without credentials or fragments.');
        }
        $host = $parts['host'];
        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } else {
            foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $addresses[] = $record['ip'] ?? $record['ipv6'] ?? '';
            }
        }
        if (!$addresses) {
            throw new \InvalidArgumentException('Webhook hostname has no usable address.');
        }
        $nonPublic = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
            '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16',
            '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
        foreach ($addresses as $address) {
            if ((strpos($address, ':') !== false && !\Symfony\Component\HttpFoundation\IpUtils::checkIp($address, '2000::/3'))
                || \Symfony\Component\HttpFoundation\IpUtils::checkIp($address, $nonPublic)) {
                throw new \InvalidArgumentException('Webhook must resolve only to globally routed addresses.');
            }
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException('Webhook must resolve only to public addresses.');
            }
        }
        return [$host, $addresses[0]];
    }

    public function send($subscription, $delivery)
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('Webhook DNS pinning requires cURL.');
        }
        list($host, $address) = $this->destination($subscription->url);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$delivery->event_id.'.'.$delivery->payload, decrypt($subscription->secret));
        $client = $this->client;
        $response = $client->post($subscription->url, [
            'body' => $delivery->payload,
            'allow_redirects' => false,
            'verify' => true,
            'proxy' => '',
            'headers' => ['Content-Type' => 'application/json', 'X-FreeScout-Event' => $delivery->event_id,
                'X-FreeScout-Timestamp' => $timestamp, 'X-FreeScout-Signature' => $signature],
            // Pin the validated DNS result for this connection to prevent rebinding.
            'curl' => [CURLOPT_RESOLVE => [$host.':443:'.(strpos($address, ':') !== false ? '['.$address.']' : $address)]],
        ]);
        return $response->getStatusCode();
    }
}
