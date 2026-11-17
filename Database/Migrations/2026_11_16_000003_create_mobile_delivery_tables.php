<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMobileDeliveryTables extends Migration
{
    public function up()
    {
        Schema::create('api_push_devices', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('token_id')->unique();
            $table->string('provider', 10);
            $table->text('destination');
            $table->timestamps();
        });
        Schema::create('api_webhook_subscriptions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 100);
            $table->text('url');
            $table->text('secret');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('api_deliveries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_id', 36);
            $table->string('kind', 10);
            $table->unsignedInteger('destination_id');
            $table->text('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('api_deliveries');
        Schema::dropIfExists('api_webhook_subscriptions');
        Schema::dropIfExists('api_push_devices');
    }
}
