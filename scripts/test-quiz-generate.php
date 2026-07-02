<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Course;
use App\Services\QuizAiService;

$course = Course::find(4);
if (!$course) {
    echo "Course not found\n";
    exit(1);
}

$service = app(QuizAiService::class);

foreach ([5, 10] as $count) {
    echo "=== Generating {$count} questions ===\n";
    try {
        $start = microtime(true);
        $result = $service->generateQuestions(
            $course,
            'Module 1: Introduction to the TCF',
            $count,
            'medium',
            3,
            [
                'quiz_mode' => 'custom',
                'question_types' => ['multiple_choice', 'true_false'],
                'bloom_levels' => ['remember', 'understand'],
            ]
        );
        $elapsed = round(microtime(true) - $start, 1);
        echo "OK: " . count($result['questions']) . " questions in {$elapsed}s via {$result['provider']}\n";
    } catch (Throwable $e) {
        echo 'FAIL: ' . $e->getMessage() . "\n";
    }
}
