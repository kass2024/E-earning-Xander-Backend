<?php

namespace App\Support;

use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Multi-tenant ownership for courses/programs/cohorts.
 *
 * Rules:
 * - Main platform (hub) owns only rows with platform_institution_id IS NULL.
 * - Each partner institution owns only rows with their platform_institution_id.
 * - No tenant may list, enroll into, host, or mutate another tenant's courses.
 * - Main admin may pass platform_institution_id to operate inside one partner tenant
 *   (admin oversight), still never mixing tenants in one query.
 */
class PlatformTenantScope
{
    public static function resolveActorEmail(Request $request): string
    {
        return strtolower(trim((string) (
            $request->input('user_email')
            ?? $request->query('user_email')
            ?? $request->query('email')
            ?? $request->input('email')
            ?? $request->header('X-User-Email')
            ?? ''
        )));
    }

    public static function resolveActorUser(Request $request): ?User
    {
        $email = self::resolveActorEmail($request);
        if ($email === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->first();
    }

    /**
     * Active tenant for this request.
     * null = main platform hub (not "unscoped").
     */
    public static function resolveTenantId(Request $request): ?int
    {
        $partnerId = self::resolvePartnerTenantId($request);
        if ($partnerId !== null) {
            return $partnerId;
        }

        $explicit = $request->input('platform_institution_id') ?? $request->query('platform_institution_id');
        if ($explicit !== null && $explicit !== '') {
            $user = self::resolveActorUser($request);
            // Only authenticated main platform admins may switch into a partner tenant context.
            // Never accept a bare client institution id without a resolved actor.
            if ($user && PlatformInstitutionHelper::isMainPlatformAdmin($user)) {
                return (int) $explicit;
            }
        }

        $user = self::resolveActorUser($request);
        if (!$user) {
            return null;
        }

        if (PlatformInstitutionHelper::isMainPlatformAdmin($user)) {
            return null;
        }

        if (!empty($user->platform_institution_id)) {
            return (int) $user->platform_institution_id;
        }

        return null;
    }

    public static function isPartnerRequest(Request $request): bool
    {
        return self::resolvePartnerTenantId($request) !== null;
    }

    public static function isMainPlatformHubContext(Request $request): bool
    {
        $user = self::resolveActorUser($request);
        if (!$user || !PlatformInstitutionHelper::isMainPlatformAdmin($user)) {
            return false;
        }

        $explicit = $request->input('platform_institution_id') ?? $request->query('platform_institution_id');

        return $explicit === null || $explicit === '';
    }

    public static function resolvePartnerTenantId(Request $request): ?int
    {
        $user = self::resolveActorUser($request);
        if ($user && PlatformInstitutionHelper::isPartnerCompanyAdmin($user)) {
            return (int) $user->platform_institution_id;
        }

        return null;
    }

    /** @return list<int> */
    public static function tenantCourseIds(?int $tenantId): array
    {
        $query = Course::query();
        if ($tenantId === null) {
            $query->whereNull('platform_institution_id');
        } else {
            $query->where('platform_institution_id', $tenantId);
        }

        return $query
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Restrict a query to the caller's owned tenant resources.
     *
     * @param  Builder<Model>|Relation  $query
     * @return Builder<Model>|Relation
     */
    public static function applyToQuery(
        Builder|Relation $query,
        Request $request,
        string $column = 'platform_institution_id',
    ): Builder|Relation {
        $table = $query->getModel()->getTable();
        if (!Schema::hasColumn($table, $column)) {
            return $query;
        }

        $tenantId = self::resolveTenantId($request);
        if ($tenantId === null) {
            // Main hub (or anonymous): only hub-owned rows — never all institutions.
            return $query->whereNull($table . '.' . $column);
        }

        return $query->where($table . '.' . $column, $tenantId);
    }

    /**
     * Catalog visibility for a learner/student by their institution.
     *
     * @param  Builder<Model>  $query
     */
    public static function applyStudentCatalogScope(
        Builder $query,
        ?Student $student,
        string $column = 'platform_institution_id',
    ): Builder {
        $table = $query->getModel()->getTable();
        if (!Schema::hasColumn($table, $column)) {
            return $query;
        }

        $studentTenant = $student && !empty($student->platform_institution_id)
            ? (int) $student->platform_institution_id
            : null;

        if ($studentTenant === null) {
            return $query->whereNull($table . '.' . $column);
        }

        return $query->where($table . '.' . $column, $studentTenant);
    }

    /**
     * Whether a course belongs to the same tenant as the student.
     */
    public static function studentCanAccessCourse(?Student $student, Course $course): bool
    {
        $courseTenant = $course->platform_institution_id !== null
            ? (int) $course->platform_institution_id
            : null;
        $studentTenant = $student && $student->platform_institution_id !== null
            ? (int) $student->platform_institution_id
            : null;

        return $courseTenant === $studentTenant;
    }

    public static function assertStudentCanAccessCourse(?Student $student, Course $course): void
    {
        if (!self::studentCanAccessCourse($student, $course)) {
            throw new AccessDeniedHttpException('This course belongs to another institution.');
        }
    }

    /**
     * Whether a user may manage/host a course under strict ownership.
     */
    public static function userOwnsCourse(User $user, Course $course): bool
    {
        $courseTenant = $course->platform_institution_id !== null
            ? (int) $course->platform_institution_id
            : null;

        if (PlatformInstitutionHelper::isMainPlatformAdmin($user)) {
            return $courseTenant === null;
        }

        if (PlatformInstitutionHelper::isPartnerCompanyAdmin($user)) {
            return $courseTenant !== null
                && $courseTenant === (int) $user->platform_institution_id;
        }

        if (!empty($user->platform_institution_id)) {
            return $courseTenant !== null
                && $courseTenant === (int) $user->platform_institution_id;
        }

        // Hub instructors/staff without institution link: hub courses only.
        $role = strtolower(trim((string) ($user->role ?? '')));
        if (in_array($role, ['admin', 'staff', 'instructor'], true)) {
            return $courseTenant === null;
        }

        return false;
    }

    public static function stampInstitutionId(Request $request, array &$data, string $key = 'platform_institution_id'): void
    {
        // Partners must always stamp their own institution — never trust client override.
        $partnerId = self::resolvePartnerTenantId($request);
        if ($partnerId !== null) {
            $data[$key] = $partnerId;

            return;
        }

        if (!empty($data[$key])) {
            return;
        }

        $tenantId = self::resolveTenantId($request);
        if ($tenantId !== null) {
            $data[$key] = $tenantId;
        }
        // Main hub creates leave platform_institution_id null (hub-owned).
    }

    /**
     * Strict partner tenant for list endpoints.
     * - null: caller is not a partner_company actor
     * - positive int: partner institution id
     * - 0: partner_company without a linked institution (must return empty, never hub/all)
     */
    public static function partnerTenantIdStrict(Request $request): ?int
    {
        $user = self::resolveActorUser($request);
        if (!$user) {
            return null;
        }

        if (strtolower(trim((string) ($user->role ?? ''))) !== 'partner_company') {
            return null;
        }

        $id = (int) ($user->platform_institution_id ?? 0);

        return $id > 0 ? $id : 0;
    }

    /**
     * Stamp from a user actor (instructor create, etc.) when no Request tenant is present.
     */
    public static function stampInstitutionIdForUser(?User $user, array &$data, string $key = 'platform_institution_id'): void
    {
        if (!empty($data[$key]) || !$user) {
            return;
        }

        if (PlatformInstitutionHelper::isMainPlatformAdmin($user)) {
            return;
        }

        if (!empty($user->platform_institution_id)) {
            $data[$key] = (int) $user->platform_institution_id;
        }
    }

    public static function assertCanAccess(
        Request $request,
        Model $model,
        string $column = 'platform_institution_id',
    ): void {
        if (!Schema::hasColumn($model->getTable(), $column)) {
            return;
        }

        $recordTenant = $model->getAttribute($column);
        $recordTenant = $recordTenant !== null ? (int) $recordTenant : null;
        $tenantId = self::resolveTenantId($request);

        if ($tenantId === null) {
            // Hub context: only hub-owned resources.
            if ($recordTenant !== null) {
                throw new AccessDeniedHttpException('This resource belongs to another institution.');
            }

            return;
        }

        if ($recordTenant === null || $recordTenant !== (int) $tenantId) {
            throw new AccessDeniedHttpException('This resource belongs to another institution.');
        }
    }

    public static function assertUserOwnsCourse(User $user, Course $course): void
    {
        if (!self::userOwnsCourse($user, $course)) {
            throw new AccessDeniedHttpException('This course belongs to another institution.');
        }
    }
}
