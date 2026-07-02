<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Services\Quiz\QuizDocumentEngine;
use App\Services\Quiz\QuizMaterialAnalysisService;
use App\Services\QuizAiService;
use Illuminate\Support\Facades\Http;

$course = Course::find(4);
$material = CourseMaterial::find(3);
$topic = 'Module 1: Introduction to the TCF';
$count = 5;

$analysis = app(QuizMaterialAnalysisService::class);
$engine = app(QuizDocumentEngine::class);
$materials = collect([$material]);
$rag = $engine->buildRagContext($materials, $topic, $count, true);
$knowledgeMap = $analysis->getCachedKnowledgeMap($material);

$service = app(QuizAiService::class);
$reflection = new ReflectionClass($service);

$formatContext = $reflection->getMethod('formatGenerationContext');
$formatContext->setAccessible(true);
$context = $formatContext->invoke($service, $course, $topic, $materials, $rag['context'], $knowledgeMap);

$genPrompt = $reflection->getMethod('generationPrompt');
$genPrompt->setAccessible(true);
$prompt = $genPrompt->invoke($service, $course, $topic, $count, 'medium', $context, [
    'question_types' => ['multiple_choice', 'true_false'],
    'bloom_levels' => ['remember', 'understand'],
], 1);

$key = trim((string) env('GOOGLE_AI_API_KEY'), " \t\"'");
$model = config('services.quiz_ai.generation_model', 'gemini-2.5-flash');
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
$maxTokens = min(8192, max(900, ($count * 240) + 200));

$response = Http::timeout(75)->post($url, [
    'contents' => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => [
        'temperature' => 0.2,
        'maxOutputTokens' => $maxTokens,
        'responseMimeType' => 'application/json',
    ],
]);

echo 'HTTP: ' . $response->status() . "\n";
echo 'Finish: ' . data_get($response->json(), 'candidates.0.finishReason', '?') . "\n";
echo 'Tokens: ' . json_encode(data_get($response->json(), 'usageMetadata')) . "\n\n";

$raw = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
file_put_contents(__DIR__ . '/../storage/logs/gemini-quiz-raw.json', $raw);
echo 'Raw length: ' . strlen($raw) . "\n";
echo substr($raw, 0, 500) . "\n...\n";
echo substr($raw, -300) . "\n\n";

$parse = $reflection->getMethod('parseQuestionsJson');
$parse->setAccessible(true);
try {
    $decoded = $parse->invoke($service, $raw);
    echo 'Parsed OK, questions: ' . count($decoded['questions'] ?? []) . "\n";
} catch (Throwable $e) {
    echo 'Parse FAIL: ' . $e->getMessage() . "\n";
    $err = json_decode($raw, true);
    echo 'json_last_error: ' . json_last_error_msg() . "\n";
}
