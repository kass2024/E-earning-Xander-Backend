<?php

namespace App\Support;

use App\Models\AvailableSchedule;
use App\Models\MeetingRegistration;
use App\Models\Student;
use Carbon\Carbon;

class MeetingScheduleTimeFormatter
{
    public static function resolveLearnerTimezone(?string $stored, string $fallback = 'UTC'): string
    {
        if (!$stored) {
            return $fallback;
        }

        $stored = trim($stored);
        if ($stored === '') {
            return $fallback;
        }

        if (str_contains($stored, '/')) {
            try {
                new \DateTimeZone($stored);

                return $stored;
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return self::mapCountryToTimezone($stored, $fallback);
    }

    public static function mapCountryToTimezone(?string $country, string $fallback): string
    {
        if (!$country) {
            return $fallback;
        }

        $c = mb_strtolower(trim($country));

        if (str_contains($c, 'rwanda')) {
            return 'Africa/Kigali';
        }
        if (str_contains($c, 'kenya')) {
            return 'Africa/Nairobi';
        }
        if (str_contains($c, 'uganda')) {
            return 'Africa/Kampala';
        }
        if (str_contains($c, 'tanzania')) {
            return 'Africa/Dar_es_Salaam';
        }
        if (str_contains($c, 'burundi')) {
            return 'Africa/Bujumbura';
        }

        return $fallback;
    }

    public static function scheduleTimezone(?AvailableSchedule $schedule): string
    {
        return (string) ($schedule?->timezone ?: config('services.pathways_webinar.timezone', 'Africa/Kigali'));
    }

    public static function durationMinutes(?AvailableSchedule $schedule): int
    {
        $configured = (int) ($schedule?->meeting_duration_minutes ?? 0);
        if ($configured >= 15) {
            return min(180, $configured);
        }

        return 60;
    }

    public static function durationLabel(?AvailableSchedule $schedule): string
    {
        $minutes = self::durationMinutes($schedule);
        if ($minutes >= 60 && $minutes % 60 === 0) {
            $hours = $minutes / 60;

            return $hours === 1 ? '1 hour' : $hours . ' hours';
        }

        return $minutes . ' minutes';
    }

    public static function parseStartUtc(MeetingRegistration $registration, ?AvailableSchedule $schedule): ?Carbon
    {
        $raw = $registration->zoom_start_time ?? null;
        if (!$raw) {
            return null;
        }

        try {
            $parsed = Carbon::parse($raw);
            if ($parsed->timezoneName !== 'UTC' && !str_contains((string) $raw, '+') && !str_contains((string) $raw, 'Z')) {
                return $parsed->copy()->setTimezone(self::scheduleTimezone($schedule))->utc();
            }

            return $parsed->utc();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function formatInstant(Carbon $instantUtc, string $timezone): string
    {
        $local = $instantUtc->copy()->setTimezone($timezone);

        return $local->format('l, F j, Y g:i A') . ' (' . $timezone . ')';
    }

    public static function formatRange(Carbon $startUtc, int $durationMinutes, string $timezone): string
    {
        $start = $startUtc->copy()->setTimezone($timezone);
        $end = $start->copy()->addMinutes($durationMinutes);

        return $start->format('l, F j, Y g:i A') . ' – ' . $end->format('g:i A') . ' (' . $timezone . ')';
    }

    /**
     * Resolve a recipient IANA timezone from email (registration country, student country, or fallback).
     */
    public static function resolveRecipientTimezoneByEmail(string $email, ?string $fallback = null): string
    {
        $fallback = $fallback ?: (string) config('app.timezone', 'UTC');
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return $fallback;
        }

        $registration = MeetingRegistration::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->orderByDesc('id')
            ->first();
        if ($registration && !empty($registration->country)) {
            return self::resolveLearnerTimezone((string) $registration->country, $fallback);
        }

        $student = Student::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
        if ($student && !empty($student->country)) {
            return self::resolveLearnerTimezone((string) $student->country, $fallback);
        }

        return $fallback;
    }

    /**
     * Format a UTC start instant for meeting invite emails in the recipient's timezone.
     */
    public static function formatInviteTime(
        Carbon|string|null $startUtc,
        int $durationMinutes,
        string $recipientTimezone,
    ): ?string {
        if ($startUtc === null || $startUtc === '') {
            return null;
        }

        try {
            $start = $startUtc instanceof Carbon
                ? $startUtc->copy()->utc()
                : Carbon::parse((string) $startUtc)->utc();

            if ($durationMinutes >= 15) {
                return self::formatRange($start, $durationMinutes, $recipientTimezone);
            }

            return self::formatInstant($start, $recipientTimezone);
        } catch (\Throwable $e) {
            return is_string($startUtc) ? $startUtc : null;
        }
    }

    /**
     * @return array{
     *   learnerSession: string|null,
     *   hostSession: string|null,
     *   duration: string,
     *   learnerTimezone: string,
     *   hostTimezone: string,
     *   platform: string
     * }
     */
    public static function buildEmailDetails(MeetingRegistration $registration, ?string $learnerLabelOverride = null): array
    {
        $schedule = $registration->availableSchedule;
        $hostTz = self::scheduleTimezone($schedule);
        $learnerTz = self::resolveLearnerTimezone($registration->country ?? null, $hostTz);
        $durationMinutes = self::durationMinutes($schedule);
        $startUtc = self::parseStartUtc($registration, $schedule);

        $learnerSession = trim((string) ($learnerLabelOverride ?? $registration->schedule_label ?? ''));
        if ($learnerSession === '' && $startUtc) {
            $learnerSession = self::formatRange($startUtc, $durationMinutes, $learnerTz);
        }

        $hostSession = $startUtc
            ? self::formatRange($startUtc, $durationMinutes, $hostTz)
            : null;

        return [
            'learnerSession' => $learnerSession !== '' ? $learnerSession : null,
            'hostSession' => $hostSession,
            'duration' => self::durationLabel($schedule),
            'learnerTimezone' => $learnerTz,
            'hostTimezone' => $hostTz,
            'platform' => 'XanderTech meet',
        ];
    }
}
