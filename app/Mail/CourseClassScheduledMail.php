<?php

namespace App\Mail;

use App\Models\Student;
use App\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CourseClassScheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public Student $student;
    public Course $course;
    public string $startTime;
    public string $joinLink;
    public ?string $notes;
    public string $portalLink;

    public function __construct(
        Student $student,
        Course $course,
        string $startTime,
        string $joinLink,
        ?string $notes = null,
        ?string $portalLink = null,
    ) {
        $this->student = $student;
        $this->course = $course;
        $this->startTime = $startTime;
        $this->joinLink = $joinLink;
        $this->notes = $notes;
        $this->portalLink = $portalLink ?: $joinLink;
    }

    public function build(): self
    {
        return $this->subject('Upcoming class scheduled: ' . ($this->course->title ?? 'Your course'))
            ->view('emails.course_class_scheduled')
            ->with([
                'student' => $this->student,
                'course' => $this->course,
                'startTime' => $this->startTime,
                'joinLink' => $this->joinLink,
                'portalLink' => $this->portalLink,
                'notes' => $this->notes,
            ]);
    }
}
