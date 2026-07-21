<?php

namespace App\Support;

use App\Models\AvailableSchedule;
use App\Models\MeetingRegistration;
use App\Models\User;
use App\Models\WebinarSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve Pathways / booked-appointment webinar settings per institution.
 */
class WebinarTenant
{
    public static function actorInstitutionId(?User $user): ?int
    {
        if (!$user || PlatformInstitutionHelper::isMainPlatformAdmin($user)) {
            return null;
        }

        $id = (int) ($user->platform_institution_id ?? 0);

        return $id > 0 ? $id : null;
    }

    public static function fromRequest(Request $request): ?int
    {
        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);
        if (!$actor) {
            return null;
        }

        // Partner institution admins must never fall through to hub (null) scoping.
        if (strtolower(trim((string) ($actor->role ?? ''))) === 'partner_company') {
            $id = (int) ($actor->platform_institution_id ?? 0);

            return $id > 0 ? $id : 0;
        }

        return self::actorInstitutionId($actor);
    }

    public static function fromSchedule(?AvailableSchedule $schedule): ?int
    {
        if (!$schedule || !Schema::hasColumn('available_schedules', 'platform_institution_id')) {
            return null;
        }

        $id = (int) ($schedule->platform_institution_id ?? 0);

        return $id > 0 ? $id : null;
    }

    public static function fromRegistration(MeetingRegistration $registration): ?int
    {
        if (Schema::hasColumn('meeting_registrations', 'platform_institution_id')) {
            $id = (int) ($registration->platform_institution_id ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        if ($registration->relationLoaded('availableSchedule') || $registration->available_schedule_id) {
            return self::fromSchedule($registration->availableSchedule);
        }

        return null;
    }

    public static function settingsFor(?int $institutionId): WebinarSetting
    {
        return WebinarSetting::forInstitution($institutionId && $institutionId > 0 ? $institutionId : null);
    }

    public static function settingsForActor(?User $user): WebinarSetting
    {
        return self::settingsFor(self::actorInstitutionId($user));
    }

    public static function settingsForRequest(Request $request): WebinarSetting
    {
        return self::settingsFor(self::fromRequest($request));
    }

    /**
     * Scope a MeetingRegistration query to one tenant (hub = null institution).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\MeetingRegistration>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\MeetingRegistration>
     */
    public static function scopeRegistrations($query, ?int $institutionId)
    {
        if (!Schema::hasColumn('meeting_registrations', 'platform_institution_id')) {
            return $query;
        }

        // Partner without a linked institution: empty result (never hub/all).
        if ($institutionId === 0) {
            return $query->whereRaw('1 = 0');
        }

        if ($institutionId && $institutionId > 0) {
            return $query->where('platform_institution_id', $institutionId);
        }

        return $query->whereNull('platform_institution_id');
    }

    /**
     * Scope AvailableSchedule query to one tenant (hub = null institution).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\AvailableSchedule>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\AvailableSchedule>
     */
    public static function scopeSchedules($query, ?int $institutionId)
    {
        if (!Schema::hasColumn('available_schedules', 'platform_institution_id')) {
            return $query;
        }

        if ($institutionId === 0) {
            return $query->whereRaw('1 = 0');
        }

        if ($institutionId && $institutionId > 0) {
            return $query->where('platform_institution_id', $institutionId);
        }

        return $query->whereNull('platform_institution_id');
    }
}