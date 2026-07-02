<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$request = Illuminate\Http\Request::create('/api/instructor/quizzes/topics', 'GET', [
    'course_id' => 4,
]);
$response = $kernel->handle($request);

echo 'HTTP ' . $response->getStatusCode() . "\n";
$data = json_decode($response->getContent(), true);
echo 'topics: ' . json_encode($data['topics'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
echo 'errors: ' . json_encode($data['extraction_errors'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
echo 'source: ' . ($data['topics_source'] ?? '?') . "\n";

$kernel->terminate($request, $response);
