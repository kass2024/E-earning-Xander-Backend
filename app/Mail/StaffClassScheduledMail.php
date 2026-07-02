<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StaffClassScheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $staff;
    public Course $course;
    public string $startTime;
    public string $joinLink;
    public ?string $notes;
    public ?string $hostLink;
    public ?string $dashboardLink;

    public function __construct(
        User $staff,
        Course $course,
        string $startTime,
        string $joinLink,
        ?string $notes = null,
        ?string $hostLink = null,
        ?string $dashboardLink = null,
    ) {
        $this->staff = $staff;
        $this->course = $course;
        $this->startTime = $startTime;
        $this->joinLink = $joinLink;
        $this->notes = $notes;
        $this->hostLink = $hostLink ?: $joinLink;
        $this->dashboardLink = $dashboardLink;
    }

    public function build(): self
    {
        return $this->subject('Class scheduled for your course: ' . ($this->course->title ?? 'Course'))
            ->view('emails.staff_class_scheduled')
            ->with([
                'staff' => $this->staff,
                'course' => $this->course,
                'startTime' => $this->startTime,
                'joinLink' => $this->joinLink,
                'hostLink' => $this->hostLink,
                'dashboardLink' => $this->dashboardLink,
                'notes' => $this->notes,
            ]);
    }
}
