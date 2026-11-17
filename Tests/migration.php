<?php

require __DIR__.'/bootstrap.php';

config(['database.connections.ticketapi_test' => [
    'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
]]);
DB::setDefaultConnection('ticketapi_test');
Illuminate\Support\Facades\Schema::clearResolvedInstance('db.schema');
$app->forgetInstance('db.schema');
require_once __DIR__.'/../Database/Migrations/2026_03_06_000001_create_api_keys_table.php';
require_once __DIR__.'/../Database/Migrations/2026_03_06_200000_add_detail_columns_to_api_logs.php';
require_once __DIR__.'/../Database/Migrations/2026_11_16_000001_harden_legacy_api_credentials.php';
(new CreateApiKeysTable())->up();
(new AddDetailColumnsToApiLogs())->up();
$plain = bin2hex(random_bytes(24));
$secret = bin2hex(random_bytes(32));
DB::table('api_keys')->insert([
    'id' => 2, 'name' => 'isolated legacy caller fixture', 'api_key' => $plain,
    'api_secret' => $secret, 'allowed_ips' => '192.0.2.1', 'active' => true,
]);
DB::table('api_logs')->insert([
    'api_key_id' => 2, 'method' => 'POST', 'endpoint' => 'api/v1/users', 'ip' => '192.0.2.1',
    'status_code' => 200, 'request_body' => 'private fixture', 'response_summary' => 'private fixture',
    'request_headers' => 'private fixture', 'query_string' => 'password=fixture',
]);
$migration = new HardenLegacyApiCredentials();
$migration->up();
$key = DB::table('api_keys')->where('id', 2)->first();
check(hash_equals(hash('sha256', $plain), $key->api_key), 'isolated key 2 hashes in place');
check(hash_equals(hash('sha256', $secret), $key->api_secret), 'legacy secret hashed');
check($key->allowed_ips === '192.0.2.1', 'migration preserves IP allowlist exactly');
check((int) $key->active === 1, 'migration preserves active caller');
check(DB::table('api_keys')->where('api_key', hash('sha256', $plain))->exists(), 'unchanged caller credential still authenticates after migration');
$log = DB::table('api_logs')->first();
check($log->request_body === null && $log->response_summary === null && $log->request_headers === null && $log->query_string === null,
    'historic sensitive fields erased');
$migration->up();
check(DB::table('api_keys')->where('id', 2)->value('api_key') === $key->api_key, 'migration replay does not rotate or double-hash credential');
check(DB::table('api_keys')->where('id', 2)->value('scopes') === $key->scopes, 'migration replay preserves explicit scopes');
echo 'PASS total '.$checks.PHP_EOL;
