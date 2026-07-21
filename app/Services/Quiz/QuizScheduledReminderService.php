<?php

namespace App\Services\Quiz;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseMaterial;
use App\Models\Student;
use App\Services\MailDeliveryService;
use App\Support\EnrollmentStatusHelper;
use App\Support\QuizMaterialHelper;
use Illuminate\Support\Facades\Log;

class QuizScheduledReminderService
{
    public const REMINDER_MINUTES_BEFORE = 90;

    public function __construct(
        protected MailDeliveryService $mail,
    ) {
    }

    /**
     * Send 90-minute reminder emails for a scheduled quiz (idempotent per quiz).
     *
     * @return int Number of emails sent
     */
    public function sendDueReminders(CourseMaterial $quiz): int
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return 0;
        }

        if (!QuizMaterialHelper::isPublished($quiz) || !QuizMaterialHelper::isScheduledQuiz($quiz)) {
            return 0;
        }

        $opensAt = QuizMaterialHelper::scheduledOpensAt($quiz);
        if ($opensAt === null || now()->gte($opensAt)) {
            return 0;
        }

        $meta = QuizMaterialHelper::meta($quiz);
        if (!empty($meta['reminder_90m_sent_at'])) {
            return 0;
        }

        $remindFrom = $opensAt->copy()->subMinutes(self::REMINDER_MINUTES_BEFORE);
        if (now()->lt($remindFrom)) {
            return 0;
        }

        $quiz->loadMissing('course');
        $course = $quiz->course;
        if (!$course instanceof Course) {
            return 0;
        }

        $publishedStudentIds = QuizMaterialHelper::publishedStudentIds($quiz);
        $students = $this->resolveStudents($course, $publishedStudentIds);
        if ($students->isEmpty()) {
            return 0;
        }

        $kind = (string) ($meta['assessment_kind'] ?? 'quiz');
        $kindLabel = match ($kind) {
            'exam' => 'Final exam',
            'test' => 'Test',
            default => 'Quiz',
        };

        $frontend = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $takeUrl = $frontend !== ''
            ? $frontend . '/dashboard/learner/quiz/' . $quiz->id
            : null;

        $opensAtLabel = $opensAt->timezone(config('app.timezone', 'UTC'))->format('l, M j, Y g:i A T');

        $sent = 0;
        foreach ($students as $student) {
            $email = trim((string) ($student->email ?? ''));
            if ($email === '') {
                continue;
            }

            $institutionId = (int) ($course->platform_institution_id ?? 0);
            $payload = [
                'student' => $student,
                'course' => $course,
                'quiz' => $quiz,
                'kindLabel' => $kindLabel,
                'takeUrl' => $takeUrl,
                'opensAtLabel' => $opensAtLabel,
                'timeLimit' => QuizMaterialHelper::timeLimitMinutes($quiz),
                'passingScore' => (int) ($meta['passing_score'] ?? 70),
            ];
            $callback = function ($message) use ($email, $quiz, $kindLabel) {
                $message->to($email)
                    ->subject($kindLabel . ' reminder: ' . ($quiz->title ?? 'Assessment') . ' starts in 1h 30m');
            };
            $context = ['quiz_id' => $quiz->id, 'student_id' => $student->id, 'reminder' => '90m'];

            $ok = $institutionId > 0
                ? $this->mail->sendViewForInstitution(
                    $institutionId,
                    'emails.quiz_scheduled_reminder',
                    $payload,
                    $callback,
                    $context
                )
                : $this->mail->sendView(
                    'emails.quiz_scheduled_reminder',
                    $payload,
                    $callback,
                    $context
                );

            if ($ok) {
                $sent++;
            }
        }

        if ($sent > 0) {
            $meta['reminder_90m_sent_at'] = now()->toIso8601String();
            $quiz->metadata = $meta;
            $quiz->save();

            Log::info('Quiz scheduled reminder emails sent', [
                'quiz_id' => $quiz->id,
                'sent' => $sent,
                'targets' => $students->count(),
            ]);
        }

        return $sent;
    }

    /**
     * @param  array<int, int>  $publishedStudentIds
     * @return \Illuminate\Support\Collection<int, Student>
     */
    protected function resolveStudents(Course $course, array $publishedStudentIds)
    {
        if ($publishedStudentIds !== []) {
            return Student::query()
                ->whereIn('id', $publishedStudentIds)
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->get();
        }

        $studentIds = CourseEnrollment::query()
            ->where('course_id', $course->id)
            ->whereIn('status', EnrollmentStatusHelper::accessStatuses())
            ->pluck('student_id');

        return Student::query()
            ->whereIn('id', $studentIds)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();
    }
}
