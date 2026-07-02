<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = config('services.anthropic.api_key');
$response = Illuminate\Support\Facades\Http::timeout(60)->withHeaders([
    'x-api-key' => $key,
    'anthropic-version' => '2023-06-01',
    'content-type' => 'application/json',
])->post('https://api.anthropic.com/v1/messages', [
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 100,
    'messages' => [['role' => 'user', 'content' => 'Say OK']],
]);

echo 'status: ' . $response->status() . PHP_EOL;
echo substr($response->body(), 0, 300) . PHP_EOL;
