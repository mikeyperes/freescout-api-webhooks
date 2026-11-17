<?php

class NativeSession
{
    public static function open($actor, $path)
    {
        $client = new GuzzleHttp\Client(['base_uri' => rtrim(config('app.url'), '/').'/',
            'http_errors' => false, 'cookies' => new GuzzleHttp\Cookie\CookieJar()]);
        $grant = app(Modules\HexawebBaseTools\Services\AdminLoginLinkService::class)
            ->issue($actor, $path, 2, 'ticketapi-2 settings regression');
        $landing = $client->get('admin-login-link')->getBody()->getContents();
        $response = $client->post('admin-login-link/consume', ['form_params' => [
            '_token' => self::csrf($landing), 'token' => parse_url($grant['url'], PHP_URL_FRAGMENT),
        ]]);
        check($response->getStatusCode() === 200, 'native authenticated settings session');
        return [$client, self::csrf($response->getBody()->getContents())];
    }

    public static function csrf($html)
    {
        preg_match('/name="csrf-token" content="([^"]+)"/', $html, $match);
        if (!isset($match[1])) {
            preg_match('/name="_token" value="([^"]+)"/', $html, $match);
        }
        if (!isset($match[1])) {
            throw new RuntimeException('Native page did not provide a CSRF token.');
        }
        return $match[1];
    }
}
