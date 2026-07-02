<?php

namespace App\Services\Quiz;

use App\Models\CourseMaterial;
use App\Models\QuizAttempt;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class QuizAnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function forQuiz(CourseMaterial $quiz): array
    {
        $meta = is_array($quiz->metadata) ? $quiz->metadata : [];
        $questions = $meta['questions'] ?? [];
        $attempts = QuizAttempt::query()
            ->where('course_material_id', $quiz->id)
            ->orderByDesc('id')
            ->get();

        $scores = $attempts->pluck('percentage')->map(fn ($v) => (float) $v);
        $passed = $attempts->where('passed', true)->count();

        $questionStats = $this->questionStats($questions, $attempts);

        return [
            'quiz_id' => $quiz->id,
            'quiz_title' => $quiz->title,
            'attempt_count' => $attempts->count(),
            'unique_students' => $attempts->pluck('student_id')->unique()->count(),
            'pass_rate' => $attempts->count() > 0 ? round(($passed / $attempts->count()) * 100, 1) : 0,
            'average_score' => $scores->count() ? round($scores->avg(), 1) : 0,
            'highest_score' => $scores->count() ? round($scores->max(), 1) : 0,
            'lowest_score' => $scores->count() ? round($scores->min(), 1) : 0,
            'question_analytics' => $questionStats,
            'integrity_summary' => [
                'avg_tab_switches' => round($attempts->avg('tab_switch_count') ?? 0, 1),
                'attempts_with_tab_switches' => $attempts->where('tab_switch_count', '>', 0)->count(),
            ],
            'ai_insights' => $this->buildInsights($questionStats, $scores->avg() ?? 0),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, array<string, mixed>>
     */
    protected function questionStats(array $questions, Collection $attempts): array
    {
        $stats = [];
        foreach ($questions as $q) {
            $qid = (string) ($q['id'] ?? '');
            if ($qid === '') {
                continue;
            }

            $correct = 0;
            $total = 0;
            foreach ($attempts as $attempt) {
                $results = is_array($attempt->question_results) ? $attempt->question_results : [];
                foreach ($results as $row) {
                    if ((string) ($row['question_id'] ?? '') !== $qid) {
                        continue;
                    }
                    $total++;
                    if (!empty($row['correct'])) {
                        $correct++;
                    }
                }
            }

            $stats[] = [
                'question_id' => $qid,
                'question' => Str::limit((string) ($q['question'] ?? ''), 120),
                'type' => $q['type'] ?? 'multiple_choice',
                'bloom_level' => $q['bloom_level'] ?? null,
                'source_section' => $q['source_section'] ?? null,
                'attempts' => $total,
                'success_rate' => $total > 0 ? round(($correct / $total) * 100, 1) : 0,
                'failure_rate' => $total > 0 ? round((($total - $correct) / $total) * 100, 1) : 0,
            ];
        }

        usort($stats, fn ($a, $b) => ($a['success_rate'] ?? 0) <=> ($b['success_rate'] ?? 0));

        return $stats;
    }

    /**
     * @param  array<int, array<string, mixed>>  $questionStats
     * @return array<string, mixed>
     */
    protected function buildInsights(array $questionStats, float $avgScore): array
    {
        $weak = array_values(array_filter($questionStats, fn ($s) => ($s['success_rate'] ?? 100) < 50));
        $strong = array_values(array_filter($questionStats, fn ($s) => ($s['success_rate'] ?? 0) >= 80));

        return [
            'learning_gaps' => array_slice(array_map(fn ($s) => $s['source_section'] ?: $s['question'], $weak), 0, 5),
            'strong_topics' => array_slice(array_map(fn ($s) => $s['source_section'] ?: $s['question'], $strong), 0, 5),
            'recommended_revisions' => $avgScore < 70
                ? ['Review material sections with success rate below 50%', 'Re-assign practice quiz on weak topics']
                : ['Consider increasing difficulty or adding HOTS questions'],
        ];
    }
}
