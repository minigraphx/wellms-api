<?php

/*
 * Imports Thrive exporter ZIPs (thrive-export/<site>/wellms/*.zip) into Wellms
 * through the same service as Admin → Courses → Import, acting as the first admin.
 *
 * Usage: php wellms-import.php <app-dir> <zip-dir> [<zip-dir> ...]
 * Skips a course whose title already exists, so reruns don't create duplicates.
 */

use EscolaLms\Courses\Models\Course;
use EscolaLms\CoursesImportExport\Services\Contracts\ExportImportServiceContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

[$script, $appDir] = $argv + [null, null];
$zipDirs = array_slice($argv, 2);

if (!$appDir || !$zipDirs) {
    fwrite(STDERR, "Usage: php {$script} <app-dir> <zip-dir> [<zip-dir> ...]\n");
    exit(2);
}

require $appDir . '/vendor/autoload.php';
$app = require $appDir . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$userModel = config('auth.providers.users.model');
$admin = $userModel::role('admin')->orderBy('id')->first();

if (!$admin) {
    fwrite(STDERR, "No admin user found. Create one with: php artisan escolalms:admin\n");
    exit(1);
}

Auth::setUser($admin);
$service = app(ExportImportServiceContract::class);
$counts = ['imported' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($zipDirs as $zipDir) {
    foreach (glob(rtrim($zipDir, '/') . '/*.zip') as $zipPath) {
        $label = basename(dirname($zipDir)) . '/' . basename($zipPath);
        $title = courseTitle($zipPath);

        if ($title !== null && Course::where('title', $title)->exists()) {
            echo "SKIP    {$label}: \"{$title}\" already exists\n";
            $counts['skipped']++;
            continue;
        }

        try {
            $file = new UploadedFile($zipPath, basename($zipPath), 'application/zip', null, true);
            $course = $service->import($file);
            printf(
                "OK      %s → course #%d \"%s\" (%d lessons, %d topics, %s)\n",
                $label,
                $course->getKey(),
                $course->title,
                $course->lessons->count(),
                $course->lessons->sum(fn ($lesson) => $lesson->topics->count()),
                $course->status
            );
            $counts['imported']++;
        } catch (Throwable $e) {
            echo "FAILED  {$label}: {$e->getMessage()}\n";
            $counts['failed']++;
        }
    }
}

printf("\nimported %d, skipped %d, failed %d\n", $counts['imported'], $counts['skipped'], $counts['failed']);
exit($counts['failed'] > 0 ? 1 : 0);

function courseTitle(string $zipPath): ?string
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return null;
    }
    $json = $zip->getFromName('content.json');
    $zip->close();

    return $json === false ? null : (json_decode($json, true)['title'] ?? null);
}
