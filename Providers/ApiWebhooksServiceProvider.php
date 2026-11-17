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
        $this->loadRoutesFrom(__DIR__ . '/../Http/mobile-routes.php');
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'apiwebhooks');

        // Add "API & Webhooks" link to Manage menu (admin only).
        \Eventy::addAction('menu.manage.after_mailboxes', function () {
            $user = auth()->user();
            if (!$user || !$user->isAdmin()) {
                return;
            }
            echo '<li><a href="' . route('apiwebhooks.settings') . '"><i class="glyphicon glyphicon-cloud"></i> ' . __('API & Webhooks') . '</a></li>';
        });

        \Eventy::addFilter('notifications.mobile_available', function () { return true; });
        \Eventy::addAction('subscription.process_events', function ($notify) {
            app(\Modules\ApiWebhooks\Services\OutboundEvents::class)->push($notify);
        });
        foreach ([
            \App\Events\CustomerCreatedConversation::class,
            \App\Events\UserCreatedConversation::class,
            \App\Events\CustomerReplied::class,
            \App\Events\UserReplied::class,
            \App\Events\UserAddedNote::class,
            \App\Events\ConversationUserChanged::class,
            \App\Events\ConversationStatusChanged::class,
        ] as $eventClass) {
            Event::listen($eventClass, function ($event) {
                app(\Modules\ApiWebhooks\Services\OutboundEvents::class)->webhook($event);
            });
        }

        Event::listen(\App\Events\UserAddedNote::class, function ($event) {
            app(\Modules\ApiWebhooks\Services\OutboundEvents::class)->mentioned($event);
        });
        Event::listen(\App\Events\CustomerReplied::class, function ($event) {
            $metadata = \Modules\ApiWebhooks\Models\TicketMetadata::find($event->conversation->id);
            if ($metadata && $metadata->snoozed_until) {
                $metadata->snoozed_until = null;
                $metadata->resume_status = null;
                $metadata->save();
            }
        });

        \Eventy::addAction('apiwebhooks.process_deliveries', function () {
            app(\Modules\ApiWebhooks\Services\DeliveryWorker::class)->run();
            app(\Modules\ApiWebhooks\Services\TicketExtensions::class)->wakeDue();
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
        $this->commands([\Modules\ApiWebhooks\Console\ApiStatusCommand::class]);
        $this->app->afterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) {
            $schedule->call(function () {
                \App\Misc\Helper::backgroundAction('apiwebhooks.process_deliveries', []);
            })->name('apiwebhooks-deliveries-and-snooze')->everyMinute()->withoutOverlapping();
            $schedule->call(function () {
                app(\Modules\ApiWebhooks\Services\RequestAudit::class)->prune();
                \DB::table('api_nonces')->where('created_at', '<', now()->subMinutes(10))->delete();
                $days = \Modules\ApiWebhooks\Services\ApiSettings::value('log_retention_days');
                \Modules\ApiWebhooks\Models\ApiLog::where('created_at', '<', now()->subDays($days))->delete();
            })->daily();
        });
    }
}
