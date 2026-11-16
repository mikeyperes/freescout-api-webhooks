<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HardenLegacyApiCredentials extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('api_keys', 'scopes')) {
            Schema::table('api_keys', function (Blueprint $table) {
                $table->text('scopes')->nullable();
            });
        }

        DB::transaction(function () {
            foreach (DB::table('api_keys')->lockForUpdate()->get() as $key) {
                $changes = [];
                if (preg_match('/^[a-f0-9]{48}$/D', $key->api_key)) {
                    $changes['api_key'] = hash('sha256', $key->api_key);
                    $changes['api_secret'] = hash('sha256', $key->api_secret);
                }
                if ($key->scopes === null) {
                    // Preserve existing integrations' authority explicitly. New keys default to read.
                    $changes['scopes'] = json_encode(array_keys(\Modules\ApiWebhooks\Models\ApiKey::availableScopes()));
                }
                if ($changes) {
                    DB::table('api_keys')->where('id', $key->id)->update($changes);
                }
            }

            // CRITICAL - see BUGLOG.md API-007. Keep metadata, erase stored content and secrets.
            DB::table('api_logs')->update([
                'request_body' => null,
                'request_headers' => null,
                'query_string' => null,
                'response_summary' => null,
            ]);
        });
    }

    public function down()
    {
        // Credential hashing and sensitive-log erasure are intentionally irreversible.
    }
}
