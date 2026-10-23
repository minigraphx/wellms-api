<?php

/*
 * Scheduler + queue trigger for Infomaniak shared hosting, where the
 * Aufgabenplaner can only call a URL (no shell cron, no supervisor/Horizon).
 * Called by the Aufgabenplaner (every 15 minutes, its finest interval). See deploy/infomaniak/README.md.
 *
 * It needs no secret: it runs at most once per MIN_INTERVAL seconds and never
 * in parallel, so extra calls do nothing beyond what cron does anyway.
 */

const MIN_INTERVAL = 50;
const QUEUE_MAX_TIME = 40;

$storage = __DIR__ . '/../storage/framework';
$lock = fopen($storage . '/cron.lock', 'c');

if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    http_response_code(204);
    exit;
}

$stampFile = $storage . '/cron.last';
$last = is_file($stampFile) ? (int) file_get_contents($stampFile) : 0;

if (time() - $last < MIN_INTERVAL) {
    http_response_code(204);
    exit;
}

file_put_contents($stampFile, (string) time());

ignore_user_abort(true);
set_time_limit(QUEUE_MAX_TIME + 30);

// Scheduled artisan commands are started as separate processes; point Laravel at the CLI binary.
putenv('PHP_PATH=/opt/php8.3/bin/php');

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

try {
    $kernel->call('schedule:run');
    $kernel->call('queue:work', [
        '--stop-when-empty' => true,
        '--max-time' => QUEUE_MAX_TIME,
        '--tries' => 3,
    ]);
    echo 'ok';
} catch (Throwable $e) {
    report($e);
    http_response_code(500);
    echo 'error';
}
