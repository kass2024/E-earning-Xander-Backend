<?php

namespace App\Support;

use App\Models\User;

class InstructorLookup
{
    /** Roles that can be assigned courses and use teaching APIs. */
    public const TEACHABLE_ROLES = ['instructor', 'admin', 'staff', 'partner_company'];

    /** Portal roles that teach while retaining admin/partner control. */
    public const PORTAL_TEACHER_ROLES = ['admin', 'staff', 'partner_company'];

    public static function byEmail(?string $email): ?User
    {
        $normalized = strtolower(trim((string) $email));
        if ($normalized === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
            ->whereIn('role', self::TEACHABLE_ROLES)
            ->first();
    }

    public static function isTeachable(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower(trim((string) ($user->role ?? '')));

        return in_array($role, self::TEACHABLE_ROLES, true);
    }

    public static function isPortalTeacher(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower(trim((string) ($user->role ?? '')));

        return in_array($role, self::PORTAL_TEACHER_ROLES, true);
    }
}
