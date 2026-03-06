<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateApiKeysTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 100);
                $table->string('api_key', 64)->unique();
                $table->string('api_secret', 64);
                $table->text('allowed_ips')->nullable();
                $table->unsignedInteger('created_by_user_id')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('api_logs')) {
            Schema::create('api_logs', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('api_key_id')->nullable();
                $table->string('method', 10);
                $table->string('endpoint', 255);
                $table->string('ip', 45);
                $table->smallInteger('status_code')->default(200);
                $table->text('request_body')->nullable();
                $table->text('response_summary')->nullable();
                $table->integer('response_time_ms')->default(0);
                $table->timestamp('created_at')->nullable();

                $table->index('api_key_id');
                $table->index('created_at');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('api_logs');
        Schema::dropIfExists('api_keys');
    }
}
