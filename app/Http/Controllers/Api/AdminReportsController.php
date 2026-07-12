<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CoursePayment;
use App\Models\InstructorPayoutRequest;
use App\Models\MeetingRegistration;
use App\Models\Student;
use App\Models\User;
use App\Support\ApiListCache;
use App\Support\CourseRevenueCalculator;
use App\Support\PlatformTenantScope;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminReportsController extends Controller
{
    public function analytics(Request $request)
    {
        $tenantId = PlatformTenantScope::resolveTenantId($request);
        $cacheKey = $tenantId !== null ? 'inst_' . $tenantId : 'hub';

        $payload = ApiListCache::remember('analytics', $cacheKey, 180, function () use ($tenantId) {
            return $this->buildAnalyticsPayload($tenantId);
        });

        return response()->json($payload, 200);
    }

    protected function buildAnalyticsPayload(?int $tenantId = null): array
    {
        // null tenantId = main hub courses only (never all institutions).
        $tenantCourseIds = PlatformTenantScope::tenantCourseIds($tenantId);
        $now = Carbon::now();
        $months = collect(range(5, 0))->map(function ($i) use ($now) {
            return $now->copy()->subMonths($i)->format('Y-m');
        });

        $enrollmentRows = CourseEnrollment::query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
            ->where('created_at', '>=', $now->copy()->subMonths(5)->startOfMonth())
            ->whereIn('course_id', $tenantCourseIds ?: [-1])
            ->groupBy('month')
            ->pluck('count', 'month');

        $enrollmentsByMonth = $months->map(fn ($month) => [
            'month' => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
            'count' => (int) ($enrollmentRows[$month] ?? 0),
        ])->values();

        $revenueByMonth = CourseRevenueCalculator::revenueByMonth(5, $tenantCourseIds);
        $sharePercent = (float) config('app.instructor_share_percent', 70);
        $platformSharePercent = round(100 - $sharePercent, 2);

        $revenueByMonthSplit = $revenueByMonth->map(function (array $row) use ($sharePercent, $platformSharePercent) {
            $total = (float) ($row['amount'] ?? 0);

            return [
                'month' => $row['month'],
                'amount' => $total,
                'instructor_earnings' => round($total * ($sharePercent / 100), 2),
                'platform_earnings' => round($total * ($platformSharePercent / 100), 2),
            ];
        })->values();

        $instructors = User::query()
            ->where('role', 'instructor')
            ->when(
                $tenantId !== null,
                fn ($q) => $q->where('platform_institution_id', $tenantId),
                fn ($q) => $q->whereNull('platform_institution_id'),
            )
            ->with(['assignedCourses:id'])
            ->withCount('assignedCourses')
            ->orderByDesc('id')
            ->get();

        $instructorIds = $instructors->pluck('id')->all();
        $allCourseIds = $instructors
            ->flatMap(fn (User $instructor) => $instructor->assignedCourses->pluck('id'))
            ->unique()
            ->values()
            ->all();

        $enrollmentByCourse = $allCourseIds === []
            ? collect()
            : CourseEnrollment::query()
                ->selectRaw('course_id, COUNT(*) as enrollments')
                ->whereIn('course_id', $allCourseIds)
                ->groupBy('course_id')
                ->get()
                ->keyBy('course_id');

        $studentsByCourse = $allCourseIds === []
            ? collect()
            : CourseEnrollment::query()
                ->whereIn('course_id', $allCourseIds)
                ->get(['course_id', 'student_id'])
                ->groupBy('course_id')
                ->map(fn ($rows) => $rows->pluck('student_id')->all());

        $payoutsByInstructor = $instructorIds === []
            ? collect()
            : InstructorPayoutRequest::query()
                ->selectRaw("instructor_id,
                    SUM(CASE WHEN status IN ('approved','paid','completed') THEN amount ELSE 0 END) as paid_out,
                    SUM(CASE WHEN status IN ('pending','processing') THEN amount ELSE 0 END) as pending_payout")
                ->whereIn('instructor_id', $instructorIds)
                ->groupBy('instructor_id')
                ->get()
                ->keyBy('instructor_id');

        $instructorPerformance = $instructors
            ->map(function (User $instructor) use ($sharePercent, $platformSharePercent, $enrollmentByCourse, $studentsByCourse, $payoutsByInstructor) {
                $courseIds = $instructor->assignedCourses->pluck('id');
                $enrollments = 0;
                $studentIds = [];
                foreach ($courseIds as $courseId) {
                    $row = $enrollmentByCourse->get($courseId);
                    if ($row) {
                        $enrollments += (int) $row->enrollments;
                    }
                    foreach ($studentsByCourse->get($courseId, []) as $studentId) {
                        $studentIds[$studentId] = true;
                    }
                }
                $students = count($studentIds);

                $totalRevenue = $courseIds->isEmpty()
                    ? 0.0
                    : CourseRevenueCalculator::paymentRevenue($courseIds->all());
                $instructorEarnings = round($totalRevenue * ($sharePercent / 100), 2);
                $platformEarnings = round($totalRevenue * ($platformSharePercent / 100), 2);

                $payoutRow = $payoutsByInstructor->get($instructor->id);
                $paidOut = (float) ($payoutRow->paid_out ?? 0);
                $pendingPayout = (float) ($payoutRow->pending_payout ?? 0);

                return [
                    'id' => $instructor->id,
                    'name' => $instructor->name,
                    'email' => $instructor->email,
                    'status' => $instructor->status,
                    'courses_assigned' => $instructor->assigned_courses_count,
                    'total_enrollments' => $enrollments,
                    'unique_students' => $students,
                    'total_revenue' => round($totalRevenue, 2),
                    'instructor_earnings' => $instructorEarnings,
                    'platform_earnings' => $platformEarnings,
                    'paid_out' => round($paidOut, 2),
                    'pending_payout' => round($pendingPayout, 2),
                    'available_balance' => max(0, round($instructorEarnings - $paidOut - $pendingPayout, 2)),
                ];
            })
            ->values();

        $coursePerformance = Course::query()
            ->when(
                $tenantId !== null,
                fn ($q) => $q->where('platform_institution_id', $tenantId),
                fn ($q) => $q->whereNull('platform_institution_id'),
            )
            ->with(['instructors:id,name,email'])
            ->withCount([
                'enrollments as total_enrollments',
                'enrollments as paid_enrollments' => fn ($q) => $q->where('status', 'paid'),
            ])
            ->orderByDesc('total_enrollments')
            ->get()
            ->map(function (Course $course) use ($sharePercent, $platformSharePercent) {
                $revenue = CourseRevenueCalculator::courseRevenue($course);
                $instructorNames = $course->instructors->pluck('name')->filter()->values()->all();

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'status' => $course->status,
                    'price' => (float) ($course->price ?? 0),
                    'total_enrollments' => (int) $course->total_enrollments,
                    'paid_enrollments' => (int) $course->paid_enrollments,
                    'revenue' => $revenue,
                    'instructor_earnings' => round($revenue * ($sharePercent / 100), 2),
                    'platform_earnings' => round($revenue * ($platformSharePercent / 100), 2),
                    'instructor_names' => $instructorNames,
                    'instructor_label' => $instructorNames ? implode(', ', $instructorNames) : 'Unassigned',
                ];
            })
            ->values();

        $studentsByCountry = Student::query()
            ->when(
                $tenantId !== null,
                fn ($q) => $q->where('platform_institution_id', $tenantId),
                fn ($q) => $q->whereNull('platform_institution_id'),
            )
            ->selectRaw("COALESCE(NULLIF(TRIM(country), ''), 'Unknown') as country, COUNT(*) as count")
            ->groupBy('country')
            ->orderByDesc('count')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'country' => $row->country,
                'count' => (int) $row->count,
            ])
            ->values();

        $stripeRevenue = CourseRevenueCalculator::paymentRevenue($tenantCourseIds);
        $manualRevenue = CourseRevenueCalculator::manualEnrollmentRevenue($tenantCourseIds);
        $instructorEarningsTotal = round($stripeRevenue * ($sharePercent / 100), 2);
        $platformEarningsTotal = round($stripeRevenue * ($platformSharePercent / 100), 2);

        $pendingInstructors = User::query()
            ->where('role', 'instructor')
            ->when(
                $tenantId !== null,
                fn ($q) => $q->where('platform_institution_id', $tenantId),
                fn ($q) => $q->whereNull('platform_institution_id'),
            )
            ->whereRaw('LOWER(COALESCE(status, "")) IN (?, ?, ?)', ['pending', 'inactive', ''])
            ->count();

        $pendingCourses = Course::query()
            ->when(
                $tenantId !== null,
                fn ($q) => $q->where('platform_institution_id', $tenantId),
                fn ($q) => $q->whereNull('platform_institution_id'),
            )
            ->whereRaw('LOWER(COALESCE(status, "")) IN (?, ?)', ['pending', 'draft'])
            ->count();

        $pendingPayments = CoursePayment::query()
            ->whereIn('course_id', $tenantCourseIds ?: [-1])
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        $pendingPayoutQuery = InstructorPayoutRequest::query()
            ->when(
                $tenantId !== null,
                fn ($q) => $q->whereHas('instructor', fn ($iq) => $iq->where('platform_institution_id', $tenantId)),
                fn ($q) => $q->whereHas('instructor', fn ($iq) => $iq->whereNull('platform_institution_id')),
            );

        $pendingPayoutRequests = (clone $pendingPayoutQuery)
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        $pendingPayoutAmount = (float) (clone $pendingPayoutQuery)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        $meetingStats = $tenantId !== null
            ? ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0]
            : [
                'total' => MeetingRegistration::count(),
                'pending' => MeetingRegistration::whereRaw('LOWER(COALESCE(status, "")) = ?', ['pending'])->count(),
                'approved' => MeetingRegistration::whereRaw('LOWER(COALESCE(status, "")) = ?', ['approved'])->count(),
                'rejected' => MeetingRegistration::whereRaw('LOWER(COALESCE(status, "")) = ?', ['rejected'])->count(),
            ];

        $studentQuery = Student::query()->when(
            $tenantId !== null,
            fn ($q) => $q->where('platform_institution_id', $tenantId),
            fn ($q) => $q->whereNull('platform_institution_id'),
        );

        $courseQuery = Course::query()->when(
            $tenantId !== null,
            fn ($q) => $q->where('platform_institution_id', $tenantId),
            fn ($q) => $q->whereNull('platform_institution_id'),
        );

        $instructorQuery = User::query()->where('role', 'instructor')->when(
            $tenantId !== null,
            fn ($q) => $q->where('platform_institution_id', $tenantId),
            fn ($q) => $q->whereNull('platform_institution_id'),
        );

        $enrollmentQuery = CourseEnrollment::query()
            ->whereIn('course_id', $tenantCourseIds ?: [-1]);

        return [
            'summary' => [
                'totalStudents' => $studentQuery->count(),
                'totalCourses' => $courseQuery->count(),
                'activeCourses' => (clone $courseQuery)->whereRaw('LOWER(COALESCE(status, "")) = ?', ['active'])->count(),
                'totalInstructors' => $instructorQuery->count(),
                'totalEnrollments' => $enrollmentQuery->count(),
                'paidEnrollments' => (clone $enrollmentQuery)->where('status', 'paid')->count(),
                'totalRevenue' => round($stripeRevenue, 2),
                'stripeRevenue' => round($stripeRevenue, 2),
                'manualRevenue' => round($manualRevenue, 2),
                'instructorEarnings' => $instructorEarningsTotal,
                'platformEarnings' => $platformEarningsTotal,
                'instructorSharePercent' => $sharePercent,
                'platformSharePercent' => $platformSharePercent,
                'pendingInstructors' => $pendingInstructors,
                'pendingCourses' => $pendingCourses,
                'pendingPayments' => $pendingPayments,
                'pendingPayoutRequests' => $pendingPayoutRequests,
                'pendingPayoutAmount' => round($pendingPayoutAmount, 2),
                'paymentProvider' => 'Stripe',
            ],
            'enrollmentsByMonth' => $enrollmentsByMonth,
            'revenueByMonth' => $revenueByMonth,
            'revenueByMonthSplit' => $revenueByMonthSplit,
            'instructorPerformance' => $instructorPerformance,
            'coursePerformance' => $coursePerformance,
            'studentsByCountry' => $studentsByCountry,
            'marketing' => $meetingStats,
        ];
    }
}
