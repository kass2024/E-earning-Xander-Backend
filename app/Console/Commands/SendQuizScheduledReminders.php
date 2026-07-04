<?php

namespace App\Console\Commands;

use App\Models\CourseMaterial;
use App\Services\Quiz\QuizScheduledReminderService;
use App\Support\QuizMaterialHelper;
use Illuminate\Console\Command;

class SendQuizScheduledReminders extends Command
{
    protected $signature = 'quizzes:send-scheduled-reminders';

    protected $description = 'Send 90-minute reminder emails for scheduled quizzes, tests, and exams';

    public function handle(QuizScheduledReminderService $reminders): int
    {
        $now = now();
        // Extra buffer for cPanel cron intervals (e.g. every 5 minutes).
        $windowEnd = $now->copy()->addMinutes(QuizScheduledReminderService::REMINDER_MINUTES_BEFORE + 10);

        CourseMaterial::query()
            ->whereIn('type', ['quiz', 'assessment'])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', $now)
            ->where('scheduled_at', '<=', $windowEnd)
            ->orderBy('id')
            ->chunkById(50, function ($quizzes) use ($reminders) {
                foreach ($quizzes as $quiz) {
                    if (!QuizMaterialHelper::isPublished($quiz)) {
                        continue;
                    }

                    $reminders->sendDueReminders($quiz);
                }
            });

        return self::SUCCESS;
    }
}
