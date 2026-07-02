<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PCloudService;
use Illuminate\Http\UploadedFile;

$courseId = (int) ($argv[1] ?? 2);
$service = app(PCloudService::class);

$tmp = tempnam(sys_get_temp_dir(), 'dl_test_');
file_put_contents($tmp, 'download link test');
$uploaded = new UploadedFile($tmp, 'download-link-test.txt', 'text/plain', null, true);

try {
    $result = $service->uploadToCourse($courseId, $uploaded);
    $fileId = (int) $result['fileid'];
    echo 'Uploaded fileid=' . $fileId . PHP_EOL;

    $link = $service->downloadLink($fileId);
    echo 'downloadLink=' . $link . PHP_EOL;

    $service->deleteFile($fileId);
    echo 'Cleaned up' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    @unlink($tmp);
}
