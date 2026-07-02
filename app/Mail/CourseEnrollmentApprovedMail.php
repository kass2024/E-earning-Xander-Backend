<?php

namespace App\Mail;

use App\Models\Student;
use App\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CourseEnrollmentApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Student $student;
    public Course $course;

    public function __construct(Student $student, Course $course)
    {
        $this->student = $student;
        $this->course = $course;
    }

    public function build(): self
    {
        return $this->subject('Your course enrollment has been approved')
            ->view('emails.course_enrollment_approved')
            ->with([
                'student' => $this->student,
                'course' => $this->course,
            ]);
    }
}
