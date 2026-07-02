<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$zoom = app(App\Services\ZoomService::class);
$mid = preg_replace('/\D+/', '', (string) ($argv[1] ?? '88239496720')) ?: '88239496720';

$checks = [
    ['GET', "/meetings/{$mid}", null],
    ['GET', '/users/' . rawurlencode($zoom->resolveHostUserId()) . '/meetings', ['type' => 'live', 'page_size' => 5]],
    ['GET', '/users/me', null],
];

foreach ($checks as [$method, $path, $query]) {
    echo "\n=== {$method} {$path} ===\n";
    $token = (new ReflectionMethod($zoom, 'getAccessToken'))->invoke($zoom);
    if (!$token) {
        echo "no token\n";
        continue;
    }
    $req = Illuminate\Support\Facades\Http::withToken($token)->timeout(20)->baseUrl('https://api.zoom.us/v2');
    $resp = $query ? $req->get($path, $query) : $req->get($path);
    echo 'status=' . $resp->status() . "\n";
    echo $resp->body() . "\n";
}
