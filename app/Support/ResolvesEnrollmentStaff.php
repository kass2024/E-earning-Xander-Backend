<?php

namespace App\Support;

use App\Models\Course;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait ResolvesEnrollmentStaff
{
    protected function resolveEnrollmentActor(Request $request): ?User
    {
        if ($user = Auth::user()) {
            return $user;
        }

        $email = $request->input('email')
            ?? $request->input('instructor_email')
            ?? $request->query('email')
            ?? $request->header('X-User-Email');

        if (!$email) {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim((string) $email))])
            ->first();
    }

    protected function isEnrollmentAdmin(User $user): bool
    {
        return in_array(strtolower((string) $user->role), ['admin', 'superadmin', 'staff'], true);
    }

    protected function isEnrollmentInstructor(User $user): bool
    {
        return strtolower((string) $user->role) === 'instructor';
    }

    protected function instructorManagesCourse(User $user, Course $course): bool
    {
        if (!$user->assignedCourses()->where('courses.id', $course->id)->exists()) {
            return false;
        }

        return PlatformTenantScope::userOwnsCourse($user, $course);
    }

    /**
     * Enforce course ownership for enrollment management.
     */
    protected function assertCanManageCourseEnrollment(Request $request, Course $course): ?JsonResponse
    {
        try {
            PlatformTenantScope::assertCanAccess($request, $course);
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $actor = $this->resolveEnrollmentActor($request);
        if (!$actor) {
            // Require an actor identity for enrollment management.
            return response()->json([
                'message' => 'Sign in to manage enrollments for this course.',
            ], 403);
        }

        if (PlatformInstitutionHelper::isPartnerCompanyAdmin($actor)) {
            if (!PlatformTenantScope::userOwnsCourse($actor, $course)) {
                return response()->json([
                    'message' => 'This course belongs to another institution.',
                ], 403);
            }

            return null;
        }

        if ($this->isEnrollmentAdmin($actor)) {
            if (!PlatformTenantScope::userOwnsCourse($actor, $course)) {
                return response()->json([
                    'message' => 'This course belongs to another institution.',
                ], 403);
            }

            return null;
        }

        if ($this->isEnrollmentInstructor($actor) && $this->instructorManagesCourse($actor, $course)) {
            return null;
        }

        return response()->json([
            'message' => 'You are not allowed to manage enrollments for this course.',
        ], 403);
    }
}
