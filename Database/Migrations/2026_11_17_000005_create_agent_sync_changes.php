<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAgentSyncChanges extends Migration
{
    public function up()
    {
        Schema::create('api_agent_sync_changes', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('conversation_id');
            $table->dateTime('updated_at');
            $table->primary(['user_id', 'conversation_id']);
            $table->index(['user_id', 'updated_at', 'conversation_id'], 'api_agent_sync_time');
        });
    }

    public function down()
    {
        Schema::dropIfExists('api_agent_sync_changes');
    }
}
