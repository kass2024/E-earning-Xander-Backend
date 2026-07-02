<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = trim((string) (env('GOOGLE_AI_API_KEY') ?: env('GEMINI_API_KEY')), " \t\"'");
$keyPreview = strlen($key) > 12 ? substr($key, 0, 6) . '…' . substr($key, -4) : '(empty)';
$keyFormat = str_starts_with($key, 'AIzaSy') ? 'AIzaSy (standard API key)' : (str_starts_with($key, 'AQ.') ? 'AQ. (NOT standard — use AIzaSy from AI Studio)' : 'unknown format');

$models = array_unique(array_filter([
    config('services.quiz_ai.generation_model'),
    env('GOOGLE_AI_MODEL'),
    env('GEMINI_MODEL'),
    'gemini-2.5-flash',
    'gemini-2.0-flash',
]));

echo "Key preview: {$keyPreview}\n";
echo "Key format: {$keyFormat}\n";
echo str_repeat('-', 60) . "\n";

foreach ($models as $model) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
    $response = Illuminate\Support\Facades\Http::timeout(30)->post($url, [
        'contents' => [['parts' => [['text' => 'Reply with one word: OK']]]],
    ]);

    echo "Model: {$model}\n";
    echo "HTTP: {$response->status()}\n";

    $json = $response->json();
    if (is_array($json) && isset($json['error'])) {
        echo 'Error: ' . ($json['error']['message'] ?? json_encode($json['error'])) . "\n";
    } elseif ($response->successful()) {
        $text = data_get($json, 'candidates.0.content.parts.0.text', '');
        echo "Success: " . trim(substr((string) $text, 0, 80)) . "\n";
    } else {
        echo 'Body: ' . substr($response->body(), 0, 300) . "\n";
    }
    echo str_repeat('-', 60) . "\n";
}
