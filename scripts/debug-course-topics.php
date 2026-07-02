<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Services\MaterialDocumentReader;
use App\Services\Quiz\QuizMaterialAnalysisService;
use App\Support\QuizMaterialHelper;

$course = Course::query()->where('title', 'like', '%TCF%')->first()
    ?? Course::query()->orderByDesc('id')->first();

if (!$course) {
    echo "No course found\n";
    exit(1);
}

echo "Course: #{$course->id} {$course->title}\n\n";

$materials = CourseMaterial::query()
    ->where('course_id', $course->id)
    ->whereNotIn('type', ['quiz', 'assessment', 'zoom'])
    ->orderBy('sort_order')
    ->orderBy('title')
    ->get();

echo "Materials: {$materials->count()}\n";

$reader = app(MaterialDocumentReader::class);
$analysis = app(QuizMaterialAnalysisService::class);

foreach ($materials as $material) {
    $meta = QuizMaterialHelper::meta($material);
    echo str_repeat('-', 60) . "\n";
    echo "Material #{$material->id}: {$material->title}\n";
    echo "Type: {$material->type}\n";
    echo "Is PDF: " . (QuizMaterialHelper::isPdfMaterial($material) ? 'yes' : 'no') . "\n";
    echo "Meta keys: " . implode(', ', array_keys(is_array($meta) ? $meta : [])) . "\n";
    echo "pcloud_file_id: " . json_encode($meta['pcloud_file_id'] ?? $meta['file_id'] ?? null) . "\n";

    if (!QuizMaterialHelper::isPdfMaterial($material)) {
        continue;
    }

    $text = $reader->readMaterialText($material);
    if ($text === null || trim($text) === '') {
        echo "TEXT: FAILED — " . ($reader->lastFetchError() ?? 'no readable text') . "\n";
        continue;
    }

    echo 'TEXT: OK — ' . strlen($text) . " chars, " . str_word_count($text) . " words\n";
    echo 'Sample: ' . substr(trim($text), 0, 120) . "...\n";

    try {
        $result = $analysis->analyze($material, true, true);
        $topics = array_merge(
            $result['knowledge_map']['main_topics'] ?? [],
            $result['knowledge_map']['subtopics'] ?? [],
        );
        echo 'Provider: ' . ($result['analysis_provider'] ?? '?') . "\n";
        echo 'Topics: ' . json_encode(array_slice($topics, 0, 10), JSON_UNESCAPED_UNICODE) . "\n";
        if (!empty($result['ai_warnings'])) {
            echo 'Warnings: ' . json_encode($result['ai_warnings'], JSON_UNESCAPED_UNICODE) . "\n";
        }
    } catch (Throwable $e) {
        echo 'ANALYZE ERROR: ' . $e->getMessage() . "\n";
    }
}

echo str_repeat('=', 60) . "\n";
$groups = $analysis->buildCourseTopicGroupsFromMaterials($materials);
echo "Groups: " . count($groups['groups']) . "\n";
echo "Topics: " . json_encode(array_column($groups['groups'], 'label'), JSON_UNESCAPED_UNICODE) . "\n";
echo "Errors: " . json_encode($groups['errors'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
