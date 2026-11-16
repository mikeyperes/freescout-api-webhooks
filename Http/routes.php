<?php

// ── Admin routes (session auth) ──────────────────────────────────
Route::group(['middleware' => ['web', 'auth'], 'prefix' => 'manage'], function () {
    Route::get('/api-webhooks', 'Modules\ApiWebhooks\Http\Controllers\ApiKeysController@index')
        ->name('apiwebhooks.settings');
    Route::post('/api-webhooks/keys', 'Modules\ApiWebhooks\Http\Controllers\ApiKeysController@create')
        ->name('apiwebhooks.keys.create');
    Route::post('/api-webhooks/keys/{id}/update', 'Modules\ApiWebhooks\Http\Controllers\ApiKeysController@update')
        ->name('apiwebhooks.keys.update');
    Route::post('/api-webhooks/keys/{id}/toggle', 'Modules\ApiWebhooks\Http\Controllers\ApiKeysController@toggle')
        ->name('apiwebhooks.keys.toggle');
    Route::post('/api-webhooks/keys/{id}/delete', 'Modules\ApiWebhooks\Http\Controllers\ApiKeysController@destroy')
        ->name('apiwebhooks.keys.delete');
    Route::post('/api-webhooks/whitelist-ip', 'Modules\ApiWebhooks\Http\Controllers\ApiKeysController@whitelistIp')
        ->name('apiwebhooks.whitelist-ip');
    Route::post('/api-webhooks/logs/clear', 'Modules\ApiWebhooks\Http\Controllers\ApiKeysController@clearLogs')
        ->name('apiwebhooks.logs.clear');
});

// ── API routes (API key auth) ────────────────────────────────────
Route::group(['middleware' => [\Modules\ApiWebhooks\Http\Middleware\ApiAuth::class], 'prefix' => 'api/v1'], function () {

    // Conversations
    Route::get('/conversations', 'Modules\ApiWebhooks\Http\Controllers\ApiController@listConversations');
    Route::get('/conversations/{id}', 'Modules\ApiWebhooks\Http\Controllers\ApiController@getConversation');
    Route::post('/conversations', 'Modules\ApiWebhooks\Http\Controllers\ApiController@createConversation');
    Route::put('/conversations/{id}', 'Modules\ApiWebhooks\Http\Controllers\ApiController@updateConversation');
    Route::delete('/conversations/{id}', 'Modules\ApiWebhooks\Http\Controllers\ApiController@deleteConversation');

    // Threads
    Route::get('/conversations/{id}/threads', 'Modules\ApiWebhooks\Http\Controllers\ApiController@listThreads');
    Route::post('/conversations/{id}/threads', 'Modules\ApiWebhooks\Http\Controllers\ApiController@createThread');

    // Customers
    Route::get('/customers', 'Modules\ApiWebhooks\Http\Controllers\ApiController@listCustomers');
    Route::get('/customers/{id}', 'Modules\ApiWebhooks\Http\Controllers\ApiController@getCustomer');

    // Users (Agents)
    Route::get('/users', 'Modules\ApiWebhooks\Http\Controllers\ApiController@listUsers');
    Route::get('/users/{id}', 'Modules\ApiWebhooks\Http\Controllers\ApiController@getUser');
    Route::post('/users', 'Modules\ApiWebhooks\Http\Controllers\ApiController@createUser');
    Route::post('/users/{id}/disable', 'Modules\ApiWebhooks\Http\Controllers\ApiController@disableUser');
    Route::post('/users/{id}/enable', 'Modules\ApiWebhooks\Http\Controllers\ApiController@enableUser');

    // Mailboxes
    Route::get('/mailboxes', 'Modules\ApiWebhooks\Http\Controllers\ApiController@listMailboxes');
    Route::get('/mailboxes/{id}', 'Modules\ApiWebhooks\Http\Controllers\ApiController@getMailbox');

    // Email History
    Route::get('/emails', 'Modules\ApiWebhooks\Http\Controllers\ApiController@emailHistory');

    // SMTP/IMAP Testing
    Route::post('/mailboxes/{id}/test-smtp', 'Modules\ApiWebhooks\Http\Controllers\ApiController@testSmtp');
    Route::post('/mailboxes/{id}/test-imap', 'Modules\ApiWebhooks\Http\Controllers\ApiController@testImap');
    Route::post('/mailboxes/{id}/send-test', 'Modules\ApiWebhooks\Http\Controllers\ApiController@sendTestEmail');
});
