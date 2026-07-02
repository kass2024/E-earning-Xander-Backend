<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LearnerExtrasController extends Controller
{
    /**
     * Return static/demo extras for learner dashboard.
     * Later you can replace this with real DB-driven data.
     */
    public function index(Request $request)
    {
        return response()->json([
            'upcoming_events' => [
                [
                    'title' => 'Live Session: React Hooks Deep Dive',
                    'date' => 'Today',
                    'time' => '3:00 PM',
                    'instructor' => 'Dr. Sarah Johnson',
                    'type' => 'live',
                ],
                [
                    'title' => 'Assignment Due: Data Analysis Project',
                    'date' => 'Tomorrow',
                    'time' => '11:59 PM',
                    'instructor' => 'Prof. Michael Chen',
                    'type' => 'deadline',
                ],
                [
                    'title' => 'Webinar: Career in Tech',
                    'date' => 'Mar 20',
                    'time' => '6:00 PM',
                    'instructor' => 'Industry Panel',
                    'type' => 'webinar',
                ],
            ],
            'achievements' => [
                [
                    'icon' => '\ud83d\ude80',
                    'title' => 'Fast Learner',
                    'description' => 'Completed 3 courses in a month',
                ],
                [
                    'icon' => '\ud83c\udfaf',
                    'title' => 'Perfect Score',
                    'description' => 'Scored 100% in final exam',
                ],
                [
                    'icon' => '\ud83d\udd25',
                    'title' => 'Consistent',
                    'description' => 'Maintained 14-day streak',
                ],
            ],
            'payments' => [
                'plan' => 'Standard Monthly',
                'status' => 'Active',
                'next_billing_date' => '28 Mar 2025',
                'payment_method' => 'Visa •••• 4242',
                'methods' => ['MTN', 'AIRTEL', 'VISA'],
            ],
            'exams' => [
                [
                    'title' => 'Final Exam: React Development',
                    'subtitle' => 'Scheduled • 22 Mar 2025 • 60 min • Timed',
                    'status' => 'Upcoming',
                    'kind' => 'exam',
                ],
                [
                    'title' => 'Certificate: Data Science Fundamentals',
                    'subtitle' => 'Issued • ID DS-2025-1042',
                    'status' => 'Issued',
                    'kind' => 'certificate',
                ],
                [
                    'title' => 'Quiz: UI/UX Design Basics',
                    'subtitle' => 'Completed • Score 92%',
                    'status' => 'Completed',
                    'kind' => 'quiz',
                ],
            ],
            'messages' => [
                [
                    'title' => 'New Zoom cohort dates available',
                    'subtitle' => 'Admin • 2 hours ago',
                ],
                [
                    'title' => 'Live Q&A session: Preparing your portfolio',
                    'subtitle' => 'Instructor Lisa • Tomorrow, 6:00 PM',
                ],
                [
                    'title' => 'Reminder: Update your profile information',
                    'subtitle' => 'System • 3 days ago',
                ],
            ],
        ]);
    }
}
