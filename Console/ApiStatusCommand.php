<?php

namespace Modules\ApiWebhooks\Console;

use Illuminate\Console\Command;
use Modules\ApiWebhooks\Services\ApiSettings;

class ApiStatusCommand extends Command
{
    protected $signature = 'apiwebhooks:status';
    protected $description = 'Show mobile API limits, credential presence and registered endpoints without secrets.';

    public function handle()
    {
        $limits = [];
        foreach (ApiSettings::limits() as $name => $definition) {
            $limits[$name] = ApiSettings::value($name);
        }
        $routes = [];
        foreach (app('router')->getRoutes() as $route) {
            if (strpos($route->uri(), 'api/v2') === 0) {
                $routes[] = ['methods' => $route->methods(), 'uri' => $route->uri()];
            }
        }
        $this->line(json_encode(['limits' => $limits, 'credentials_present' => [
            'apns' => (bool) \App\Option::get('apiwebhooks_apns_credentials'),
            'fcm' => (bool) \App\Option::get('apiwebhooks_fcm_credentials'),
        ], 'routes' => $routes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
