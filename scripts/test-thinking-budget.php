<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = trim((string) env('GOOGLE_AI_API_KEY'), " \t\"'");
$model = 'gemini-2.5-flash';
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

$r = Illuminate\Support\Facades\Http::timeout(60)->post($url, [
    'contents' => [['parts' => [['text' => 'Return JSON with 3 true_false questions about French TCF exam. Format: {"questions":[...]}']]]],
    'generationConfig' => [
        'temperature' => 0.2,
        'maxOutputTokens' => 4096,
        'responseMimeType' => 'application/json',
        'thinkingConfig' => ['thinkingBudget' => 0],
    ],
]);

$j = $r->json();
echo 'HTTP: ' . $r->status() . PHP_EOL;
echo 'finish=' . ($j['candidates'][0]['finishReason'] ?? '?') . PHP_EOL;
echo 'thoughts=' . ($j['usageMetadata']['thoughtsTokenCount'] ?? 0) . PHP_EOL;
echo 'candidates=' . ($j['usageMetadata']['candidatesTokenCount'] ?? 0) . PHP_EOL;
$raw = (string) data_get($j, 'candidates.0.content.parts.0.text', '');
echo 'len=' . strlen($raw) . PHP_EOL;
$decoded = json_decode($raw, true);
echo 'valid=' . (is_array($decoded) ? 'yes ' . count($decoded['questions'] ?? []) . ' q' : 'no ' . json_last_error_msg()) . PHP_EOL;
