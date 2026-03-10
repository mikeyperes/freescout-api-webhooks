<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddDetailColumnsToApiLogs extends Migration
{
    public function up()
    {
        if (Schema::hasTable('api_logs') && !Schema::hasColumn('api_logs', 'user_agent')) {
            Schema::table('api_logs', function (Blueprint $table) {
                $table->string('user_agent', 500)->nullable()->after('ip');
                $table->text('request_headers')->nullable()->after('user_agent');
                $table->text('query_string')->nullable()->after('request_headers');
                $table->string('country', 100)->nullable()->after('query_string');
                $table->string('city', 100)->nullable()->after('country');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('api_logs')) {
            Schema::table('api_logs', function (Blueprint $table) {
                $table->dropColumn(['user_agent', 'request_headers', 'query_string', 'country', 'city']);
            });
        }
    }
}
