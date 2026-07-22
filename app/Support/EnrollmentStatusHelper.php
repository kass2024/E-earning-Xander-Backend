<?php

namespace App\Support;

class EnrollmentStatusHelper
{
    /** Statuses that grant full course resource access (materials, quizzes, live classes). */
    public const ACCESS_STATUSES = ['approved', 'partial_paid', 'paid', 'completed'];

    /** Statuses where payment has been fully settled. */
    public const PAID_STATUSES = ['paid', 'completed'];

    /** Statuses where at least one successful payment was recorded (full or partial). */
    public const PARTIAL_OR_PAID_STATUSES = ['partial_paid', 'paid', 'completed'];

    public static function normalize(?string $status): string
    {
        return strtolower(trim((string) $status));
    }

    public static function hasCourseAccess(?string $status): bool
    {
        return in_array(self::normalize($status), self::ACCESS_STATUSES, true);
    }

    public static function isPaid(?string $status): bool
    {
        return in_array(self::normalize($status), self::PAID_STATUSES, true);
    }

    public static function isPartialPaid(?string $status): bool
    {
        return self::normalize($status) === 'partial_paid';
    }

    public static function isPendingApproval(?string $status): bool
    {
        $s = self::normalize($status);

        return in_array($s, ['enrolled', 'applied', 'waiting approval'], true);
    }

    public static function canPay(?string $status): bool
    {
        // Stripe: typically after approval. MoMo: also allow partial balance payments.
        return in_array(
            self::normalize($status),
            ['enrolled', 'applied', 'waiting approval', 'approved', 'partial_paid'],
            true
        );
    }

    public static function isRejected(?string $status): bool
    {
        return self::normalize($status) === 'rejected';
    }

    /** Learner may open the course guide page (any application except rejected). */
    public static function canViewCourseGuide(?string $status): bool
    {
        if (self::isRejected($status)) {
            return false;
        }

        $s = self::normalize($status);

        return in_array($s, [
            'enrolled', 'applied', 'waiting approval', 'approved',
            'partial_paid', 'paid', 'completed', 'active',
        ], true);
    }

    public static function accessStatuses(): array
    {
        return self::ACCESS_STATUSES;
    }
}
