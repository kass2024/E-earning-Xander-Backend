<?php

namespace App\Support;

use App\Models\User;

class InstructorLookup
{
    public static function byEmail(?string $email): ?User
    {
        $normalized = strtolower(trim((string) $email));
        if ($normalized === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
            ->where('role', 'instructor')
            ->first();
    }
}
