<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTicketExtensions extends Migration
{
    public function up()
    {
        Schema::create('api_conversation_metadata', function (Blueprint $table) {
            $table->unsignedInteger('conversation_id')->primary();
            $table->text('tags')->nullable();
            $table->text('custom_fields')->nullable();
            $table->timestamp('snoozed_until')->nullable()->index();
            $table->unsignedSmallInteger('resume_status')->nullable();
            $table->timestamps();
        });
        Schema::create('api_saved_replies', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mailbox_id')->nullable()->index();
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->string('name', 100);
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('api_saved_replies');
        Schema::dropIfExists('api_conversation_metadata');
    }
}
