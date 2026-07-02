<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = trim((string) (env('GOOGLE_AI_API_KEY') ?: env('GEMINI_API_KEY')), " \t\"'");
$model = config('services.quiz_ai.generation_model', 'gemini-2.5-flash');
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

$prompt = <<<'PROMPT'
Generate exactly 3 quiz questions about TCF Module 1.

Return JSON only:
{"questions":[{"id":"q1","question":"Sample?","type":"true_false","options":["True","False"],"correct_answer":"True","explanation":"Because","source_section":"Module 1","confidence_score":0.9,"points":1}]}
PROMPT;

$response = Illuminate\Support\Facades\Http::timeout(60)->post($url, [
    'contents' => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => [
        'temperature' => 0.2,
        'maxOutputTokens' => 2048,
        'responseMimeType' => 'application/json',
    ],
]);

echo 'HTTP: ' . $response->status() . "\n";
$json = $response->json();
echo 'Parts count: ' . count($json['candidates'][0]['content']['parts'] ?? []) . "\n";
echo json_encode($json['candidates'][0]['content']['parts'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
echo 'Finish: ' . ($json['candidates'][0]['finishReason'] ?? '?') . "\n";
