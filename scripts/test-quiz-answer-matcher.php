<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Services\Quiz\QuizAnswerMatcher;

$question = [
    'type' => 'multiple_choice',
    'options' => [
        'Paris is the capital of France',
        'London is the capital of France',
        'Berlin is the capital of France',
        'Madrid is the capital of France',
    ],
    'correct_answer' => 'B',
];

$resolved = QuizAnswerMatcher::resolveCorrectText($question);
$match = QuizAnswerMatcher::matchesExact($question, 'London is the capital of France');

echo "Resolved correct: {$resolved}\n";
echo "Student match: " . ($match ? 'YES' : 'NO') . "\n";

$tf = [
    'type' => 'true_false',
    'options' => ['True', 'False'],
    'correct_answer' => 'True',
];
echo "TF match: " . (QuizAnswerMatcher::matchesExact($tf, 'True') ? 'YES' : 'NO') . "\n";
