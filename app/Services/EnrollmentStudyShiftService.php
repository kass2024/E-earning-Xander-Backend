<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\StudyShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class EnrollmentStudyShiftService
{
    public function __construct(
        private readonly StudyShiftProvisioningService $provisioning,
    ) {
    }

    public const DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    /**
     * @return array<int, StudyShift>|JsonResponse
     */
    public function resolveStudyShiftsForCourse(
        Course $course,
        array $shiftIds,
        ?int $excludeEnrollmentId = null,
        bool $requireAtLeastOne = false
    ) {
        $shiftIds = array_values(array_unique(array_filter(array_map('intval', $shiftIds))));

        if ($shiftIds === []) {
            if ($requireAtLeastOne) {
                $hasShifts = $this->provisioning
                    ->shiftsForCourseRegistration($course, $course->platform_institution_id)
                    ->isNotEmpty();

                if ($hasShifts) {
                    return response()->json([
                        'message' => 'Please select at least one study shift for this course.',
                    ], 422);
                }
            }

            return [];
        }

        $shifts = StudyShift::query()
            ->whereIn('id', $shiftIds)
            ->where('is_active', true)
            ->get();

        $invalid = $shifts->filter(
            fn (StudyShift $shift) => !$this->provisioning->shiftAppliesToCourse($shift, $course)
        );

        if ($invalid->isNotEmpty() || $shifts->count() !== count($shiftIds)) {
            return response()->json([
                'message' => 'One or more selected study shifts are not available for this course.',
            ], 422);
        }

        $days = $shifts->pluck('day_of_week')->map(fn ($day) => (int) $day);
        $timeKeys = $shifts->map(function (StudyShift $shift) {
            $start = substr((string) $shift->start_time, 0, 5);
            $end = substr((string) $shift->end_time, 0, 5);

            return sprintf('%d|%s|%s', (int) $shift->day_of_week, $start, $end);
        });

        if ($timeKeys->count() !== $timeKeys->unique()->count()) {
            return response()->json([
                'message' => 'You cannot select the same day and time more than once.',
            ], 422);
        }

        foreach ($shifts as $shift) {
            $enrolled = $this->countEnrollmentLinks($shift, $excludeEnrollmentId);
            if ($shift->max_students && $enrolled >= $shift->max_students) {
                $dayLabel = self::DAY_NAMES[(int) $shift->day_of_week] ?? 'Day';

                return response()->json([
                    'message' => sprintf(
                        'The %s shift on %s is full. Please choose another time.',
                        $shift->name,
                        $dayLabel
                    ),
                ], 422);
            }
        }

        return $shifts->sortBy([
            ['day_of_week', 'asc'],
            ['start_time', 'asc'],
        ])->values()->all();
    }

    /**
     * @return array{study_shifts: array}|JsonResponse
     */
    public function syncEnrollmentStudyShifts(CourseEnrollment $enrollment, array $shiftIds, bool $requireAtLeastOne = false)
    {
        $course = $enrollment->course ?? Course::find($enrollment->course_id);
        if (!$course) {
            return response()->json(['message' => 'Course not found for this enrollment.'], 404);
        }

        $resolved = $this->resolveStudyShiftsForCourse(
            $course,
            $shiftIds,
            $enrollment->id,
            $requireAtLeastOne
        );

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $ids = collect($resolved)->pluck('id')->all();
        $enrollment->studyShifts()->sync($ids);
        $enrollment->update([
            'study_shift_id' => $ids !== [] ? $ids[0] : null,
        ]);
        $enrollment->load('studyShifts');

        return [
            'study_shifts' => $this->formatStudyShiftsForApi($enrollment->studyShifts),
        ];
    }

    public function countEnrollmentLinks(StudyShift $shift, ?int $excludeEnrollmentId = null): int
    {
        $query = $shift->enrollmentLinks();
        if ($excludeEnrollmentId) {
            $query->where('course_enrollment_id', '!=', $excludeEnrollmentId);
        }

        return $query->count();
    }

    public function formatStudyShiftsForApi($shifts): array
    {
        return collect($shifts)->map(fn (StudyShift $shift) => $this->formatStudyShift($shift))->values()->all();
    }

    public function formatStudyShift(StudyShift $shift): array
    {
        $dayLabel = self::DAY_NAMES[(int) $shift->day_of_week] ?? 'Day';
        $start = substr((string) $shift->start_time, 0, 5);
        $end = substr((string) $shift->end_time, 0, 5);

        return [
            'id' => $shift->id,
            'course_id' => $shift->course_id,
            'name' => $shift->name,
            'day_of_week' => (int) $shift->day_of_week,
            'day_label' => $dayLabel,
            'start_time' => $start,
            'end_time' => $end,
            'label' => sprintf('%s · %s %s–%s', $shift->name, $dayLabel, $start, $end),
        ];
    }

    public function formatShiftIds(Collection|array $shifts): array
    {
        return collect($shifts)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
