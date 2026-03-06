<?php

namespace Modules\ApiWebhooks\Providers;

use Illuminate\Support\ServiceProvider;

class ApiWebhooksServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__ . '/../Http/routes.php');
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'apiwebhooks');

        // Add "API & Webhooks" link to Manage menu (admin only).
        \Eventy::addAction('menu.manage.after_mailboxes', function () {
            $user = auth()->user();
            if (!$user || !$user->isAdmin()) {
                return;
            }
            echo '<li><a href="' . route('apiwebhooks.settings') . '"><i class="glyphicon glyphicon-cloud"></i> ' . __('API & Webhooks') . '</a></li>';
        });
    }

    public function register()
    {
        //
    }
}
