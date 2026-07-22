<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Student;
use App\Support\ApiListCache;
use App\Support\PlatformTenantScope;
use Illuminate\Console\Command;

/**
 * Remove enrollments that link a student to a course from a different institution
 * (or hub vs partner mismatches). Those rows surface foreign courses in My Courses.
 */
class RepairCrossTenantEnrollments extends Command
{
    protected $signature = 'institutions:repair-cross-tenant-enrollments {--dry-run : List rows without deleting}';

    protected $description = 'Delete course enrollments where the student and course belong to different tenants';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $removed = 0;

        CourseEnrollment::query()
            ->with(['student:id,platform_institution_id,email', 'course:id,title,platform_institution_id'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($dryRun, &$removed) {
                foreach ($rows as $enrollment) {
                    /** @var CourseEnrollment $enrollment */
                    $student = $enrollment->student;
                    $course = $enrollment->course;
                    if (!$student instanceof Student || !$course instanceof Course) {
                        continue;
                    }

                    if (PlatformTenantScope::studentCanAccessCourse($student, $course)) {
                        continue;
                    }

                    $removed++;
                    $this->line(sprintf(
                        '%s enrollment #%d student=%s course=%s (student_inst=%s course_inst=%s)',
                        $dryRun ? 'Would remove' : 'Removing',
                        $enrollment->id,
                        $student->email ?? $student->id,
                        $course->title ?? $course->id,
                        $student->platform_institution_id ?? 'hub',
                        $course->platform_institution_id ?? 'hub',
                    ));

                    if (!$dryRun) {
                        $enrollment->studyShifts()->detach();
                        $enrollment->delete();
                    }
                }
            });

        if (!$dryRun && $removed > 0) {
            ApiListCache::bump('courses');
            ApiListCache::bump('study_shifts');
        }

        $this->info(($dryRun ? 'Would remove' : 'Removed') . " {$removed} cross-tenant enrollment(s).");

        return self::SUCCESS;
    }
}
