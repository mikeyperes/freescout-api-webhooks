<?php

namespace Modules\ApiWebhooks\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
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

        // Track last login time on the users table.
        Event::listen(Login::class, function (Login $event) {
            if ($event->user && method_exists($event->user, 'save')) {
                try {
                    $event->user->last_login_at = now();
                    $event->user->save();
                } catch (\Exception $e) {
                    // Column may not exist yet — silently ignore.
                }
            }
        });
    }

    public function register()
    {
        //
    }
}
