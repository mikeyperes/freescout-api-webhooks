<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateMobileApiTables extends Migration
{
    public function up()
    {
        Schema::create('api_device_tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->index();
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->string('kind', 10);
            $table->string('access_level', 10);
            $table->text('allowed_ips')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('api_nonces', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('token_id');
            $table->string('nonce_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['token_id', 'nonce_hash']);
        });
        Schema::create('api_audit_chain', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('tail_hash', 64);
            $table->string('anchor_hash', 64);
        });
        DB::table('api_audit_chain')->insert(['id' => 1, 'tail_hash' => str_repeat('0', 64), 'anchor_hash' => str_repeat('0', 64)]);
        Schema::create('api_audit_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('token_id')->nullable();
            $table->text('metadata');
            $table->string('previous_hash', 64);
            $table->string('entry_hash', 64);
            $table->timestamp('created_at')->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('api_audit_entries');
        Schema::dropIfExists('api_audit_chain');
        Schema::dropIfExists('api_nonces');
        Schema::dropIfExists('api_device_tokens');
    }
}
