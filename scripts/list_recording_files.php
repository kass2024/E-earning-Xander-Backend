<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$zoom = app(\App\Services\ZoomService::class);
$collected = $zoom->collectAllCloudRecordings(\App\Support\AdminRecordingCatalog::trackedMeetingIds(), 12);

foreach ($collected['meetings'] as $m) {
    echo 'Topic: ' . ($m['topic'] ?? '?') . ' id=' . ($m['id'] ?? '?') . PHP_EOL;
    foreach ($m['recording_files'] ?? [] as $f) {
        echo '  - ' . ($f['file_type'] ?? '?') . ' type=' . ($f['recording_type'] ?? '?') . PHP_EOL;
        echo '    dl=' . substr((string) ($f['download_url'] ?? ''), 0, 80) . '...' . PHP_EOL;
    }
}
