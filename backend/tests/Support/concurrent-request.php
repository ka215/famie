<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (getenv('FAMIE_CONCURRENCY_TEST') !== '1' || DB::getDriverName() !== 'pgsql' || ! str_ends_with(DB::getDatabaseName(), '_test')) {
    throw new RuntimeException('An explicitly enabled PostgreSQL test database is required.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
DB::select("select set_config('application_name', ?, false)", [$input['worker']]);
DB::statement("set lock_timeout = '15s'");
config(['famie.allowed_ips' => ['127.0.0.1'], 'famie.max_group_members' => $input['limit']]);
$request = Request::create($input['path'], $input['method'], server: [
    'REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'],
], content: json_encode($input['body'], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
