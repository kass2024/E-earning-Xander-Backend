<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = trim((string) (env('GOOGLE_AI_API_KEY') ?: env('GEMINI_API_KEY')), " \t\"'");
$model = config('services.quiz_ai.generation_model', 'gemini-2.0-flash');
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

$response = Illuminate\Support\Facades\Http::timeout(30)->post($url, [
    'contents' => [['parts' => [['text' => 'Reply with JSON: {"ok":true}']]]],
    'generationConfig' => ['responseMimeType' => 'application/json'],
]);

echo 'status: ' . $response->status() . PHP_EOL;
echo substr($response->body(), 0, 500) . PHP_EOL;
