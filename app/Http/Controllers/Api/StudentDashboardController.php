<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\Student;
use Illuminate\Http\Request;

class StudentDashboardController extends Controller
{
    public function summary(Request $request, Student $student)
    {
        // Total distinct courses this student is enrolled in
        $coursesEnrolled = CourseEnrollment::where('student_id', $student->id)->count();

        // Completed / paid courses (basic example, adjust statuses as needed)
        $certificates = CourseEnrollment::where('student_id', $student->id)
            ->whereIn('status', ['paid', 'completed'])
            ->count();

        // For now, hours learned & streak days are placeholders
        // You can later replace with real tracking (e.g. lessons, attendance, etc.)
        $hoursLearned = 0.0;
        $streakDays = 0;

        return response()->json([
            'stats' => [
                'courses_enrolled' => $coursesEnrolled,
                'hours_learned' => $hoursLearned,
                'certificates' => $certificates,
                'streak_days' => $streakDays,
            ],
        ]);
    }
}
