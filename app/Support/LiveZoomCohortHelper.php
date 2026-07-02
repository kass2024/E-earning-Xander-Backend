<?php

namespace App\Support;

use App\Models\LiveZoomCohort;

class LiveZoomCohortHelper
{
    public static function publicJoinUrl(LiveZoomCohort|int $cohort): string
    {
        $id = $cohort instanceof LiveZoomCohort ? $cohort->id : $cohort;

        return rtrim(FrontendUrl::base(), '/') . '/live-cohort/' . $id . '/join';
    }

    public static function participantRoomPath(LiveZoomCohort|int $cohort): string
    {
        $id = $cohort instanceof LiveZoomCohort ? $cohort->id : $cohort;

        return '/live-cohort/' . $id . '/room';
    }

    public static function hostStudioPath(LiveZoomCohort|int $cohort): string
    {
        $id = $cohort instanceof LiveZoomCohort ? $cohort->id : $cohort;

        return '/live-cohort/' . $id . '/host';
    }

    public static function participantRoomUrl(LiveZoomCohort|int $cohort): string
    {
        return rtrim(FrontendUrl::base(), '/') . self::participantRoomPath($cohort);
    }

    public static function hostStudioUrl(LiveZoomCohort|int $cohort): string
    {
        return rtrim(FrontendUrl::base(), '/') . self::hostStudioPath($cohort);
    }
}
