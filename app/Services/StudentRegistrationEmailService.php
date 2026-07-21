<?php

namespace App\Services;

use App\Mail\StudentRegisteredMail;
use App\Models\Student;

class StudentRegistrationEmailService
{
    public function __construct(
        protected MailDeliveryService $mail
    ) {
    }

    /**
     * Send welcome email with login credentials after learner signup.
     *
     * @param  array<int, string>  $selectedCourses
     */
    public function sendWelcomeEmail(Student $student, string $plainPassword, array $selectedCourses = []): bool
    {
        $mailable = new StudentRegisteredMail($student, $plainPassword, $selectedCourses);
        $context = [
            'event' => 'student_registered',
            'student_id' => $student->id,
        ];

        $institutionId = (int) ($student->platform_institution_id ?? 0);
        if ($institutionId > 0) {
            return $this->mail->sendToForInstitution(
                $institutionId,
                $student->email,
                $mailable,
                $context
            );
        }

        return $this->mail->sendTo(
            $student->email,
            $mailable,
            $context
        );
    }
}
