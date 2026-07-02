<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$parser = new Smalot\PdfParser\Parser();
$text = trim($parser->parseFile('C:/Users/user/Downloads/TCF_Complete_Study_Material_5_Modules.pdf')->getText());

$service = app(App\Services\Quiz\QuizMaterialAnalysisService::class);
$reflection = new ReflectionClass($service);
$method = $reflection->getMethod('buildKnowledgeMapWithAi');
$method->setAccessible(true);
$result = $method->invoke($service, $text, 'TCF Study Material');

echo 'provider: ' . ($result['provider'] ?? '?') . PHP_EOL;
echo 'main_topics: ' . json_encode($result['map']['main_topics'] ?? [], JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'subtopics count: ' . count($result['map']['subtopics'] ?? []) . PHP_EOL;
