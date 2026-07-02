<?php

namespace App\Mail;

use App\Models\Student;
use App\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CourseEnrollmentRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Student $student;
    public Course $course;
    public ?string $reason;

    public function __construct(Student $student, Course $course, ?string $reason = null)
    {
        $this->student = $student;
        $this->course = $course;
        $this->reason = $reason;
    }

    public function build(): self
    {
        return $this->subject('Update on your course application')
            ->view('emails.course_enrollment_rejected')
            ->with([
                'student' => $this->student,
                'course' => $this->course,
                'reason' => $this->reason,
            ]);
    }
}
