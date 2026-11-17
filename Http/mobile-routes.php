<?php

use Modules\ApiWebhooks\Http\Controllers\MobileSessionController;
use Modules\ApiWebhooks\Http\Controllers\MobileConversationsController;
use Modules\ApiWebhooks\Http\Controllers\MobileResourcesController;
use Modules\ApiWebhooks\Http\Controllers\MobileSyncController;
use Modules\ApiWebhooks\Http\Middleware\MobileAuth;

Route::group(['prefix' => 'api/v2', 'middleware' => ['web']], function () {
    Route::get('login', MobileSessionController::class.'@challenge');
    Route::post('login', MobileSessionController::class.'@login');
    Route::post('tokens/exchange', MobileSessionController::class.'@exchange')->middleware('auth');
});

Route::group(['prefix' => 'api/v2', 'middleware' => [MobileAuth::class]], function () {
    Route::get('me', MobileResourcesController::class.'@me');
    Route::get('tokens', MobileSessionController::class.'@index');
    Route::delete('tokens/{id}', MobileSessionController::class.'@revoke');
    Route::get('mailboxes', MobileResourcesController::class.'@mailboxes');
    Route::get('conversations', MobileConversationsController::class.'@index');
    Route::post('conversations', MobileConversationsController::class.'@create');
    Route::get('conversations/{id}', MobileConversationsController::class.'@show');
    Route::patch('conversations/{id}', MobileConversationsController::class.'@update');
    Route::get('conversations/{id}/threads', MobileConversationsController::class.'@threads');
    Route::post('conversations/{id}/reply', MobileConversationsController::class.'@reply');
    Route::post('conversations/{id}/notes', MobileConversationsController::class.'@note');
    Route::get('conversations/{id}/drafts', MobileConversationsController::class.'@drafts');
    Route::post('conversations/{id}/drafts', MobileConversationsController::class.'@draft');
    Route::delete('conversations/{id}/drafts/{draftId}', MobileConversationsController::class.'@deleteDraft');
    Route::put('conversations/{id}/read', MobileConversationsController::class.'@readState');
    Route::post('attachments', MobileResourcesController::class.'@upload');
    Route::get('attachments/{id}', MobileResourcesController::class.'@download');
    Route::get('customers', MobileResourcesController::class.'@customers');
    Route::post('customers', MobileResourcesController::class.'@createCustomer');
    Route::get('customers/{id}', MobileResourcesController::class.'@customer');
    Route::patch('customers/{id}', MobileResourcesController::class.'@updateCustomer');
    Route::get('users', MobileResourcesController::class.'@users');
    Route::get('notifications', MobileResourcesController::class.'@notifications');
    Route::get('notification-settings', MobileResourcesController::class.'@notificationSettings');
    Route::put('notification-settings', MobileResourcesController::class.'@saveNotificationSettings');
    Route::get('saved-replies', MobileResourcesController::class.'@savedReplies');
    Route::put('device', \Modules\ApiWebhooks\Http\Controllers\MobileDevicesController::class.'@register');
    Route::delete('device', \Modules\ApiWebhooks\Http\Controllers\MobileDevicesController::class.'@remove');
    Route::get('sync', MobileSyncController::class.'@index');
});

Route::group(['prefix' => 'manage/api-webhooks/mobile', 'middleware' => ['web', 'auth']], function () {
    $controller = \Modules\ApiWebhooks\Http\Controllers\MobileSettingsController::class;
    Route::get('/', $controller.'@dashboard')->name('apiwebhooks.mobile');
    Route::post('settings', $controller.'@save')->name('apiwebhooks.mobile.save');
    Route::post('servers', $controller.'@serverToken')->name('apiwebhooks.mobile.server');
    Route::post('servers/{id}/test', $controller.'@testServer')->name('apiwebhooks.mobile.server-test');
    Route::post('servers/{id}/revoke', $controller.'@revokeServer')->name('apiwebhooks.mobile.server-revoke');
    Route::post('webhooks', $controller.'@webhook')->name('apiwebhooks.mobile.webhook');
    Route::post('webhooks/{id}/disable', $controller.'@deleteWebhook')->name('apiwebhooks.mobile.webhook-delete');
    Route::post('webhooks/{id}/test', $controller.'@testWebhook')->name('apiwebhooks.mobile.webhook-test');
    Route::post('push/test', $controller.'@testPush')->name('apiwebhooks.mobile.push-test');
    Route::post('saved-replies', $controller.'@savedReply')->name('apiwebhooks.mobile.saved-reply');
    Route::post('saved-replies/{id}/delete', $controller.'@deleteSavedReply')->name('apiwebhooks.mobile.saved-reply-delete');
});

// Short-lived, signed self-test receiver. It has no business mutation or ticket payload response.
Route::post('api/v2/webhook-diagnostic/{challenge}', \Modules\ApiWebhooks\Http\Controllers\WebhookDiagnosticController::class.'@receive')->middleware('throttle:10,1');

Route::post('mobile-api/device-token', MobileSessionController::class.'@issue')
    ->middleware(['web', 'auth'])->name('apiwebhooks.device-exchange');
