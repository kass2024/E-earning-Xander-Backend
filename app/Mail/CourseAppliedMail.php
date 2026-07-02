<?php

namespace App\Mail;

use App\Models\Student;
use App\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CourseAppliedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Student $student;
    public Course $course;
    public ?string $level;

    /**
     * Create a new message instance.
     */
    public function __construct(Student $student, Course $course, ?string $level = null)
    {
        $this->student = $student;
        $this->course = $course;
        $this->level = $level;
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        return $this->subject('Course application received')
            ->view('emails.course_applied')
            ->with([
                'student' => $this->student,
                'course' => $this->course,
                'level' => $this->level,
            ]);
    }
}
