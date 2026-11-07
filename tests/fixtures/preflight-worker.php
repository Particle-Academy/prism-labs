<?php

declare(strict_types=1);

use App\Lab\WorkerHeartbeats;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$directory = storage_path('framework/testing/preflight-native-'.bin2hex(random_bytes(8)));
config(['lab-preflight.workers.directory' => $directory, 'queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);
Schema::create('jobs', function ($table): void {
    $table->bigIncrements('id');
    $table->string('queue')->index();
    $table->longText('payload');
    $table->unsignedTinyInteger('attempts');
    $table->unsignedInteger('reserved_at')->nullable();
    $table->unsignedInteger('available_at');
    $table->unsignedInteger('created_at');
});
$loops = 0;
$observed = false;
Event::listen(Looping::class, function () use (&$loops, &$observed, $app): void {
    $loops++;
    $observed = $app->make(WorkerHeartbeats::class)->check()['ready'];
});
try {
    $exit = Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default,scoring', '--stop-when-empty' => true, '--timeout' => 60, '--sleep' => 0]);
    echo json_encode(['loops' => $loops, 'observed_ready' => $observed, 'after_stop_ready' => $app->make(WorkerHeartbeats::class)->check()['ready'],
        'records_after_stop' => count(glob($directory.'/*.json') ?: []), 'exit' => $exit], JSON_THROW_ON_ERROR);
} finally {
    (new Filesystem)->deleteDirectory($directory);
}
