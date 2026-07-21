<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionMeetingApprovalJob;
use App\Jobs\ProvisionMeetingRegistrationJob;
use App\Jobs\ResendMeetingJoinLinkJob;
use App\Jobs\SendMeetingRegistrationReminderEmailJob;
use App\Jobs\SendMeetingRegistrationStatusEmailJob;
use App\Models\AvailableSchedule;
use App\Models\MeetingRegistration;
use App\Models\User;
use App\Models\WebinarSetting;
use App\Services\ZoomService;
use App\Services\Meetings\WebinarDailyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use App\Services\MailDeliveryService;
use App\Support\AdminRecordingCatalog;
use App\Support\FrontendUrl;
use App\Support\MeetingJoinUrl;
use App\Support\MeetingRegistrationJoinUrl;
use App\Support\PlatformInstitutionHelper;
use App\Support\WebinarTenant;
use Illuminate\Support\Str;
use Carbon\Carbon;

class MeetingRegistrationController extends Controller
{
    protected ZoomService $zoom;

    protected MailDeliveryService $mail;

    protected WebinarDailyService $webinarDaily;

    public function __construct(ZoomService $zoom, MailDeliveryService $mail, WebinarDailyService $webinarDaily)
    {
        $this->zoom = $zoom;
        $this->mail = $mail;
        $this->webinarDaily = $webinarDaily;
    }

    private function getNextWebinarStartTime(): Carbon
    {
        $tz = (string) config('services.pathways_webinar.timezone', 'Africa/Kigali');
        $now = Carbon::now($tz);

        $nextSat = $now->copy()->next(Carbon::SATURDAY)->setTime(21, 0, 0);
        $nextSun = $now->copy()->next(Carbon::SUNDAY)->setTime(18, 0, 0);

        // If today is Saturday/Sunday and we haven't passed the start time yet, use today
        if ($now->isSaturday()) {
            $candidate = $now->copy()->setTime(21, 0, 0);
            if ($candidate->greaterThan($now)) {
                $nextSat = $candidate;
            }
        }
        if ($now->isSunday()) {
            $candidate = $now->copy()->setTime(18, 0, 0);
            if ($candidate->greaterThan($now)) {
                $nextSun = $candidate;
            }
        }

        return $nextSat->lessThan($nextSun) ? $nextSat : $nextSun;
    }

    private function getNextStartFromSchedule(?AvailableSchedule $schedule): Carbon
    {
        $tz = (string) ($schedule?->timezone ?: config('services.pathways_webinar.timezone', 'Africa/Kigali'));
        $now = Carbon::now($tz);

        $dow = (int) ($schedule?->day_of_week ?? $now->dayOfWeek);

        $rawStart = (string) ($schedule?->start_time ?? '09:00:00');
        // supports HH:MM or HH:MM:SS
        $parts = explode(':', $rawStart);
        $hour = (int) ($parts[0] ?? 9);
        $minute = (int) ($parts[1] ?? 0);

        $candidate = $now->copy()->next($dow)->setTime($hour, $minute, 0);
        if ($now->dayOfWeek === $dow) {
            $today = $now->copy()->setTime($hour, $minute, 0);
            if ($today->greaterThan($now)) {
                $candidate = $today;
            }
        }

        return $candidate;
    }

    private function resolveMeetingStartAt(?AvailableSchedule $schedule, ?string $meetingAt): Carbon
    {
        if ($meetingAt) {
            try {
                return Carbon::parse($meetingAt);
            } catch (\Throwable $e) {
                // fall through to schedule-based default
            }
        }

        return $schedule ? $this->getNextStartFromSchedule($schedule) : $this->getNextWebinarStartTime();
    }

    private function scheduleLabel(?AvailableSchedule $schedule): ?string
    {
        if (!$schedule) {
            return null;
        }

        $dow = (int) ($schedule->day_of_week ?? 0);
        $day = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$dow] ?? (string) $dow;

        $tzName = (string) ($schedule->timezone ?: config('services.pathways_webinar.timezone', 'Africa/Kigali'));

        $rawStart = (string) ($schedule->start_time ?? '');
        $rawEnd = (string) ($schedule->end_time ?? '');

        $startText = null;
        $endText = null;

        try {
            if ($rawStart !== '') {
                $parts = explode(':', $rawStart);
                $sh = (int) ($parts[0] ?? 0);
                $sm = (int) ($parts[1] ?? 0);
                $start = Carbon::createFromTime($sh, $sm, 0, $tzName);
                $startText = $start->format('g:i A');
            }
            if ($rawEnd !== '') {
                $parts = explode(':', $rawEnd);
                $eh = (int) ($parts[0] ?? 0);
                $em = (int) ($parts[1] ?? 0);
                $end = Carbon::createFromTime($eh, $em, 0, $tzName);
                $endText = $end->format('g:i A');
            }
        } catch (\Throwable $e) {
            // Fallback to original HH:MM strings if parsing fails
            $startText = $rawStart !== '' ? substr($rawStart, 0, 5) : null;
            $endText = $rawEnd !== '' ? substr($rawEnd, 0, 5) : null;
        }

        $range = '';
        if ($startText !== null && $endText !== null) {
            $range = $startText . '-' . $endText;
        } elseif ($startText !== null) {
            $range = $startText;
        }

        $tzSuffix = $tzName ? (' (' . $tzName . ')') : '';

        return trim($day . ' ' . $range) . $tzSuffix;
    }

    private function mapCountryToTimezone(?string $country, string $fallback): string
    {
        if (!$country) {
            return $fallback;
        }

        $country = trim($country);

        // Browser IANA timezone sent from the booking form (e.g. America/New_York)
        if (str_contains($country, '/')) {
            try {
                new \DateTimeZone($country);
                return $country;
            } catch (\Throwable $e) {
                // fall through to country-name mapping
            }
        }

        $c = mb_strtolower($country);

        if (str_contains($c, 'rwanda')) return 'Africa/Kigali';
        if (str_contains($c, 'kenya')) return 'Africa/Nairobi';
        if (str_contains($c, 'uganda')) return 'Africa/Kampala';
        if (str_contains($c, 'tanzania')) return 'Africa/Dar_es_Salaam';
        if (str_contains($c, 'burundi')) return 'Africa/Bujumbura';
        if (str_contains($c, 'canada')) return 'America/Toronto';
        if (str_contains($c, 'united states') || str_contains($c, 'usa')) return 'America/New_York';
        if (str_contains($c, 'united kingdom') || str_contains($c, 'uk')) return 'Europe/London';
        if (str_contains($c, 'france')) return 'Europe/Paris';
        if (str_contains($c, 'germany')) return 'Europe/Berlin';

        return $fallback;
    }

    private function learnerScheduleLabel(?AvailableSchedule $schedule, ?string $registrationCountry): ?string
    {
        if (!$schedule) {
            return null;
        }

        // Primary country: first entry in the comma-separated list from the form
        $primaryCountry = null;
        if ($registrationCountry) {
            $parts = array_filter(array_map('trim', explode(',', $registrationCountry)));
            if (!empty($parts)) {
                $primaryCountry = $parts[0];
            }
        }

        $sourceTz = (string) ($schedule->timezone ?: config('services.pathways_webinar.timezone', 'Africa/Kigali'));
        $targetTz = $this->mapCountryToTimezone($primaryCountry, $sourceTz);

        $rawStart = (string) ($schedule->start_time ?? '');
        $rawEnd = (string) ($schedule->end_time ?? '');

        try {
            $parse = function (string $raw) use ($sourceTz): ?Carbon {
                if ($raw === '') {
                    return null;
                }
                $core = substr($raw, 0, 5); // HH:MM
                [$h, $m] = array_pad(explode(':', $core), 2, '0');
                return Carbon::createFromTime((int) $h, (int) $m, 0, $sourceTz);
            };

            $startSource = $parse($rawStart);
            $endSource = $parse($rawEnd);

            if (!$startSource) {
                return null;
            }

            $durationMinutes = 0;
            if ($endSource) {
                $minutes = (int) round($endSource->diffInMinutes($startSource, false));
                if ($minutes < 0) {
                    $minutes = 0;
                }
                $durationMinutes = $minutes;
            }

            $startLocal = $startSource->copy()->setTimezone($targetTz);
            $endLocal = $startLocal->copy()->addMinutes($durationMinutes);

            // Match frontend style: Mon 12:00 PM - 11:03 AM (Burundi time)
            $startText = $startLocal->format('D g:i A');
            $endText = $endLocal->format('g:i A');

            $suffix = $primaryCountry
                ? (' (' . (str_contains($primaryCountry, '/') ? $primaryCountry : $primaryCountry . ' time') . ')')
                : '';

            return $startText . ' - ' . $endText . $suffix;
        } catch (\Throwable $e) {
            // Fallback to schedule timezone-based label
            return $this->scheduleLabel($schedule);
        }
    }

    private function sendWebinarInviteEmail(MeetingRegistration $meetingRegistration, ?string $joinUrl): void
    {
        $to = $meetingRegistration->email;
        if (!$to) {
            return;
        }

        $tz = (string) config('services.pathways_webinar.timezone', 'Africa/Kigali');
        try {
            if ($meetingRegistration->relationLoaded('availableSchedule') && !empty($meetingRegistration->availableSchedule?->timezone)) {
                $tz = (string) $meetingRegistration->availableSchedule->timezone;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $nextSessionText = null;

            if ($meetingRegistration->relationLoaded('availableSchedule') && $meetingRegistration->availableSchedule) {
                $nextSessionText = $this->learnerScheduleLabel($meetingRegistration->availableSchedule, $meetingRegistration->country ?? null);
                if (!$nextSessionText) {
                    $nextSessionText = $this->scheduleLabel($meetingRegistration->availableSchedule);
                }
            }

            if (!$nextSessionText) {
                $nextStart = null;
                try {
                    if (!empty($meetingRegistration->zoom_start_time)) {
                        $nextStart = Carbon::parse($meetingRegistration->zoom_start_time)->setTimezone($tz);
                    }
                } catch (\Throwable $e) {
                    $nextStart = null;
                }
                $nextSessionText = $nextStart ? ($nextStart->format('Y-m-d H:i') . ' (' . $tz . ')') : null;
            }

            $scheduleDescription = null;
            try {
                if ($meetingRegistration->relationLoaded('availableSchedule') && $meetingRegistration->availableSchedule) {
                    $scheduleDescription = (string) ($meetingRegistration->availableSchedule->notes ?? '');
                    if ($scheduleDescription === '') {
                        $scheduleDescription = null;
                    }
                }
            } catch (\Throwable $e) {
                $scheduleDescription = null;
            }

            $learnerNotes = null;
            try {
                $learnerNotes = (string) ($meetingRegistration->notes ?? '');
                if ($learnerNotes === '') {
                    $learnerNotes = null;
                }
            } catch (\Throwable $e) {
                $learnerNotes = null;
            }

            $this->mail->sendView('emails.meeting_registration_approved', [
                'appName' => config('app.name'),
                'name' => $meetingRegistration->full_name ?? '',
                'joinUrl' => MeetingRegistrationJoinUrl::forRegistration($meetingRegistration)
                    ?: MeetingJoinUrl::preferAppJoinUrl(
                        $joinUrl,
                        trim((string) ($meetingRegistration->zoom_meeting_id ?? ''))
                    )
                    ?: $joinUrl,
                'nextSession' => $nextSessionText,
                'scheduleDescription' => $scheduleDescription,
                'learnerNotes' => $learnerNotes,
            ], function ($message) use ($to) {
                $message->to($to)->subject('Pathways Webinar Schedule & Zoom Link');
            }, [
                'event' => 'meeting_registration_approved',
                'meeting_registration_id' => $meetingRegistration->id ?? null,
                'to' => $to,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to prepare meeting registration approved webinar email', [
                'meeting_registration_id' => $meetingRegistration->id ?? null,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendReminderEmail(MeetingRegistration $meetingRegistration, ?string $message = null): void
    {
        $to = $meetingRegistration->email;
        if (!$to) {
            return;
        }

        $effectiveJoinUrl = null;
        if (!empty($meetingRegistration->zoom_join_url)) {
            $effectiveJoinUrl = $meetingRegistration->zoom_join_url;
        }
        if (!$effectiveJoinUrl && !$this->zoom->isConfigured()) {
            $effectiveJoinUrl = (string) config('services.pathways_webinar.zoom_join_url');
        }
        $effectiveJoinUrl = MeetingRegistrationJoinUrl::forRegistration($meetingRegistration)
            ?: MeetingJoinUrl::preferAppJoinUrl(
                $effectiveJoinUrl,
                trim((string) ($meetingRegistration->zoom_meeting_id ?? ''))
            )
            ?: $effectiveJoinUrl;

        $tz = (string) config('services.pathways_webinar.timezone', 'Africa/Kigali');
        try {
            if ($meetingRegistration->relationLoaded('availableSchedule') && !empty($meetingRegistration->availableSchedule?->timezone)) {
                $tz = (string) $meetingRegistration->availableSchedule->timezone;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $nextSessionText = null;
        if ($meetingRegistration->relationLoaded('availableSchedule') && $meetingRegistration->availableSchedule) {
            $nextSessionText = $this->learnerScheduleLabel($meetingRegistration->availableSchedule, $meetingRegistration->country ?? null);
            if (!$nextSessionText) {
                $nextSessionText = $this->scheduleLabel($meetingRegistration->availableSchedule);
            }
        }
        if (!$nextSessionText) {
            $nextStart = null;
            try {
                if (!empty($meetingRegistration->zoom_start_time)) {
                    $nextStart = Carbon::parse($meetingRegistration->zoom_start_time)->setTimezone($tz);
                }
            } catch (\Throwable $e) {
                $nextStart = null;
            }
            $nextSessionText = $nextStart ? ($nextStart->format('Y-m-d H:i') . ' (' . $tz . ')') : null;
        }

        $this->mail->sendView('emails.meeting_registration_reminder', [
            'appName' => config('app.name'),
            'name' => $meetingRegistration->full_name ?? '',
            'joinUrl' => $effectiveJoinUrl,
            'nextSession' => $nextSessionText,
            'customMessage' => $message,
        ], function ($messageObj) use ($to) {
            $messageObj->to($to)->subject('Reminder: Pathways Webinar & Zoom Link');
        }, [
            'event' => 'meeting_registration_reminder',
            'meeting_registration_id' => $meetingRegistration->id ?? null,
            'to' => $to,
        ]);
    }

    private function sendStatusEmail(MeetingRegistration $meetingRegistration, string $status, ?string $reason = null, ?string $joinUrl = null, ?string $frontendScheduleLabel = null): void
    {
        $to = $meetingRegistration->email;
        if (!$to) {
            return;
        }

        try {
            if (strtolower($status) === 'rejected') {
                $this->mail->sendView('emails.meeting_registration_rejected', [
                    'appName' => config('app.name'),
                    'name' => $meetingRegistration->full_name ?? '',
                    'reason' => $reason,
                ], function ($message) use ($to) {
                    $message->to($to)->subject('Meeting Registration Rejected');
                }, [
                    'event' => 'meeting_registration_rejected',
                    'meeting_registration_id' => $meetingRegistration->id ?? null,
                    'to' => $to,
                ]);
            } elseif (strtolower($status) === 'approved') {
                $effectiveJoinUrl = $joinUrl;
                if (!$effectiveJoinUrl && !empty($meetingRegistration->zoom_join_url)) {
                    $effectiveJoinUrl = $meetingRegistration->zoom_join_url;
                }
                if (!$effectiveJoinUrl && !$this->zoom->isConfigured()) {
                    $effectiveJoinUrl = (string) config('services.pathways_webinar.zoom_join_url');
                }
                $effectiveJoinUrl = MeetingRegistrationJoinUrl::forRegistration($meetingRegistration)
                    ?: MeetingJoinUrl::preferAppJoinUrl(
                        $effectiveJoinUrl,
                        trim((string) ($meetingRegistration->zoom_meeting_id ?? ''))
                    )
                    ?: $effectiveJoinUrl;

                // Prefer the exact label from the frontend dropdown when available.
                $nextSessionText = $frontendScheduleLabel;

                if (!$nextSessionText) {
                    $tz = (string) config('services.pathways_webinar.timezone', 'Africa/Kigali');
                    try {
                        if ($meetingRegistration->relationLoaded('availableSchedule') && !empty($meetingRegistration->availableSchedule?->timezone)) {
                            $tz = (string) $meetingRegistration->availableSchedule->timezone;
                        }
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    if ($meetingRegistration->relationLoaded('availableSchedule') && $meetingRegistration->availableSchedule) {
                        $nextSessionText = $this->learnerScheduleLabel($meetingRegistration->availableSchedule, $meetingRegistration->country ?? null);
                        if (!$nextSessionText) {
                            $nextSessionText = $this->scheduleLabel($meetingRegistration->availableSchedule);
                        }
                    }
                    if (!$nextSessionText) {
                        $nextStart = null;
                        try {
                            if (!empty($meetingRegistration->zoom_start_time)) {
                                $nextStart = Carbon::parse($meetingRegistration->zoom_start_time)->setTimezone($tz);
                            }
                        } catch (\Throwable $e) {
                            $nextStart = null;
                        }
                        $nextSessionText = $nextStart ? ($nextStart->format('Y-m-d H:i') . ' (' . $tz . ')') : null;
                    }
                }

                $scheduleDescription = null;
                try {
                    if ($meetingRegistration->relationLoaded('availableSchedule') && $meetingRegistration->availableSchedule) {
                        $scheduleDescription = (string) ($meetingRegistration->availableSchedule->notes ?? '');
                        if ($scheduleDescription === '') {
                            $scheduleDescription = null;
                        }
                    }
                } catch (\Throwable $e) {
                    $scheduleDescription = null;
                }

                $learnerNotes = null;
                try {
                    $learnerNotes = (string) ($meetingRegistration->notes ?? '');
                    if ($learnerNotes === '') {
                        $learnerNotes = null;
                    }
                } catch (\Throwable $e) {
                    $learnerNotes = null;
                }

                $this->mail->sendView('emails.meeting_registration_approved', [
                    'appName' => config('app.name'),
                    'name' => $meetingRegistration->full_name ?? '',
                    'joinUrl' => $effectiveJoinUrl,
                    'nextSession' => $nextSessionText,
                    'scheduleDescription' => $scheduleDescription,
                    'learnerNotes' => $learnerNotes,
                ], function ($message) use ($to) {
                    $message->to($to)->subject('Pathways Webinar Schedule & Zoom Link');
                }, [
                    'event' => 'meeting_registration_approved',
                    'meeting_registration_id' => $meetingRegistration->id ?? null,
                    'to' => $to,
                ]);
            } else {
                $subject = 'Meeting Registration ' . $status;
                $lines = [];
                $lines[] = 'Hello ' . ($meetingRegistration->full_name ?? '');
                $lines[] = '';
                $lines[] = 'Your meeting registration status is: ' . $status . '.';
                $lines[] = '';
                $lines[] = 'Thank you,';
                $lines[] = config('app.name');

                $this->mail->sendRaw(implode("\n", $lines), function ($message) use ($to, $subject) {
                    $message->to($to)->subject($subject);
                }, [
                    'event' => 'meeting_registration_status',
                    'meeting_registration_id' => $meetingRegistration->id ?? null,
                    'to' => $to,
                    'status' => $status,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to prepare meeting registration status email', [
                'meeting_registration_id' => $meetingRegistration->id ?? null,
                'to' => $to,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function approvedRegistrationCount(?int $institutionId = null): int
    {
        $query = MeetingRegistration::query()
            ->whereRaw("LOWER(COALESCE(status, 'pending')) = 'approved'");
        WebinarTenant::scopeRegistrations($query, $institutionId);

        return $query->count();
    }

    private function pathwaysJoinUrl(?int $institutionId = null): ?string
    {
        $settings = WebinarTenant::settingsFor($institutionId);
        $meetingId = trim((string) ($settings->zoom_meeting_id ?? ''));
        if ($meetingId !== '') {
            return MeetingRegistrationJoinUrl::participantUrl($meetingId);
        }

        $stored = trim((string) ($settings->zoom_join_url ?? ''));
        if ($stored !== '') {
            return MeetingJoinUrl::preferAppJoinUrl($stored, $meetingId !== '' ? $meetingId : null) ?: $stored;
        }

        $url = trim((string) config('services.pathways_webinar.zoom_join_url', ''));

        return $url !== ''
            ? (MeetingJoinUrl::preferAppJoinUrl($url, null) ?: $url)
            : null;
    }

    private function syncApprovedRegistrationZoomLinks(?string $joinUrl, ?string $meetingId, ?int $institutionId = null): void
    {
        if (!$joinUrl || !Schema::hasColumn('meeting_registrations', 'zoom_join_url')) {
            return;
        }

        $update = ['zoom_join_url' => $joinUrl];
        if ($meetingId && Schema::hasColumn('meeting_registrations', 'zoom_meeting_id')) {
            $update['zoom_meeting_id'] = $meetingId;
        }

        $query = MeetingRegistration::query()
            ->whereRaw("LOWER(COALESCE(status, 'pending')) = 'approved'");
        WebinarTenant::scopeRegistrations($query, $institutionId);
        $query->update($update);
    }

    private function scheduleDurationMinutes(?AvailableSchedule $schedule): int
    {
        if (!$schedule) {
            return 60;
        }

        $configured = (int) ($schedule->meeting_duration_minutes ?? 0);
        if ($configured >= 15) {
            return min(180, $configured);
        }

        return 60;
    }

    private function scheduleTimezone(?AvailableSchedule $schedule): string
    {
        return (string) ($schedule?->timezone ?: config('services.pathways_webinar.timezone', 'Africa/Kigali'));
    }

    private function scheduledSessionKey(Carbon $startAt): string
    {
        return $startAt->copy()->utc()->format('Y-m-d H:i');
    }

    private function meetingSlotIsTaken(Carbon $startAt, ?int $institutionId = null): bool
    {
        if (!Schema::hasColumn('meeting_registrations', 'zoom_start_time')) {
            return false;
        }

        $query = MeetingRegistration::query()
            ->where('zoom_start_time', $startAt->toDateTimeString())
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereRaw("LOWER(COALESCE(status, 'pending')) = 'approved'")
                    ->orWhereRaw("LOWER(COALESCE(status, 'pending')) = 'pending'");
            });
        WebinarTenant::scopeRegistrations($query, $institutionId);

        return $query->exists();
    }

    /**
     * Create or reuse a scheduled Zoom meeting for the upcoming webinar session.
     *
     * @return array{ok: bool, message?: string, join_url?: string|null, start_url?: string|null, meeting_id?: string|null, reused?: bool, details?: mixed}
     */
    private function ensureScheduledWebinarMeeting(
        WebinarSetting $settings,
        Carbon $startAt,
        ?AvailableSchedule $schedule,
        ?User $actor = null,
    ): array {
        if (!$this->zoom->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'Zoom API credentials are missing. Set ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, and ZOOM_CLIENT_SECRET.',
            ];
        }

        $sessionKey = $this->scheduledSessionKey($startAt);

        if (
            $settings->zoom_meeting_id &&
            $settings->zoom_join_url &&
            $settings->zoom_scheduled_at &&
            $this->scheduledSessionKey(Carbon::parse($settings->zoom_scheduled_at)) === $sessionKey &&
            $this->zoom->canManageMeetingViaApi((string) $settings->zoom_meeting_id)
        ) {
            return [
                'ok' => true,
                'join_url' => $settings->zoom_join_url,
                'start_url' => $settings->zoom_start_url,
                'meeting_id' => (string) $settings->zoom_meeting_id,
                'reused' => true,
            ];
        }

        $tz = $this->scheduleTimezone($schedule);
        $startLocal = $startAt->copy()->setTimezone($tz);
        $topic = 'Pathways Webinar - ' . $startLocal->format('M j, Y g:i A');

        $institutionId = !empty($schedule?->platform_institution_id)
            ? (int) $schedule->platform_institution_id
            : (!empty($settings->platform_institution_id) ? (int) $settings->platform_institution_id : null);
        $poolUserId = $actor?->id
            ? (int) $actor->id
            : ($institutionId ? null : (int) ($schedule->id ?? 0));
        $hostId = $this->zoom->resolveHostUserId(
            $institutionId,
            $poolUserId > 0 ? $poolUserId : null,
            $actor?->email,
        );

        $meeting = $this->zoom->createMeeting([
            'topic' => $topic,
            'start_time' => $startLocal->format('Y-m-d\TH:i:s'),
            'timezone' => $tz,
            'duration' => $this->scheduleDurationMinutes($schedule),
            'agenda' => 'Registered participants webinar session',
            'auto_recording' => (bool) $settings->recording_enabled,
            'join_before_host' => true,
            'waiting_room' => true,
            'mute_upon_entry' => true,
        ], $hostId);

        if ($meeting === null) {
            return [
                'ok' => false,
                'message' => 'Unable to contact Zoom to create the scheduled webinar meeting.',
            ];
        }

        if (!empty($meeting['error'])) {
            return [
                'ok' => false,
                'message' => $meeting['body']['message'] ?? 'Zoom rejected meeting creation.',
                'details' => $meeting['body'] ?? null,
            ];
        }

        $meetingId = isset($meeting['id']) ? (string) $meeting['id'] : null;
        $joinUrl = $meeting['join_url'] ?? null;
        $startUrl = $meeting['start_url'] ?? null;

        if (!$meetingId || !$joinUrl) {
            return [
                'ok' => false,
                'message' => 'Zoom created a meeting but did not return join links.',
            ];
        }

        $settings->zoom_meeting_id = $meetingId;
        $settings->zoom_join_url = $joinUrl;
        $settings->zoom_start_url = $startUrl;
        $settings->zoom_scheduled_at = $startAt;
        $this->applyWebinarHostAssignment($settings, $hostId, $institutionId);
        $this->applyWebinarMeetingSecrets($settings, $meeting);
        $settings->save();

        $this->syncApprovedRegistrationZoomLinks($joinUrl, $meetingId, $institutionId);

        return [
            'ok' => true,
            'join_url' => $joinUrl,
            'start_url' => $startUrl,
            'meeting_id' => $meetingId,
            'reused' => false,
        ];
    }

    /**
     * @return array{ok: bool, message?: string, settings?: WebinarSetting, meeting?: array}
     */
    private function createWebinarZoomSession(WebinarSetting $settings, ?User $actor = null): array
    {
        if (!$this->zoom->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'Zoom API credentials are missing. Set ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, and ZOOM_CLIENT_SECRET on the server.',
            ];
        }

        $institutionId = !empty($settings->platform_institution_id)
            ? (int) $settings->platform_institution_id
            : null;
        $hostId = $this->zoom->resolveHostUserId(
            $institutionId,
            $actor?->id ? (int) $actor->id : null,
            $actor?->email,
        );

        $topic = 'Pathways Webinar - ' . now()->format('Y-m-d H:i');
        $meeting = $this->zoom->createInstantMeeting([
            'topic' => $topic,
            'duration' => 90,
            'agenda' => 'Registered participants webinar session',
            'auto_recording' => (bool) $settings->recording_enabled,
            'join_before_host' => false,
            'waiting_room' => true,
            'mute_upon_entry' => true,
        ], $hostId);

        if ($meeting === null) {
            return [
                'ok' => false,
                'message' => 'Unable to contact Zoom to create the webinar meeting.',
            ];
        }

        if (!empty($meeting['error'])) {
            $zoomMessage = $meeting['body']['message'] ?? 'Zoom rejected meeting creation.';

            return [
                'ok' => false,
                'message' => $zoomMessage,
                'details' => $meeting['body'] ?? null,
            ];
        }

        $meetingId = isset($meeting['id']) ? (string) $meeting['id'] : null;
        $joinUrl = $meeting['join_url'] ?? null;
        $startUrl = $meeting['start_url'] ?? null;

        if (!$meetingId || !$joinUrl || !$startUrl) {
            return [
                'ok' => false,
                'message' => 'Zoom created a meeting but did not return host/join links.',
            ];
        }

        $settings->zoom_meeting_id = $meetingId;
        $settings->zoom_join_url = $joinUrl;
        $settings->zoom_start_url = $startUrl;
        $settings->zoom_scheduled_at = now();
        $settings->session_started_at = now();
        $this->applyWebinarHostAssignment($settings, $hostId, $institutionId);
        $this->applyWebinarMeetingSecrets($settings, $meeting);
        $settings->save();

        $this->syncApprovedRegistrationZoomLinks($joinUrl, $meetingId, $institutionId);

        return [
            'ok' => true,
            'settings' => $settings,
            'meeting' => $meeting,
        ];
    }

    private function applyWebinarHostAssignment(WebinarSetting $settings, string $hostId, ?int $institutionId = null): void
    {
        if (Schema::hasColumn('webinar_settings', 'zoom_host_user_id')) {
            $settings->zoom_host_user_id = $hostId;
        }
        // Never reassign platform_institution_id on an existing settings row — that orphans the hub singleton.
        // Load the correct tenant row with WebinarSetting::forInstitution() before calling this.
    }

    /**
     * @param  array<string, mixed>  $meeting
     */
    private function applyWebinarMeetingSecrets(WebinarSetting $settings, array $meeting): void
    {
        $password = is_string($meeting['password'] ?? null) ? trim($meeting['password']) : '';
        if ($password === '') {
            $password = $this->zoom->extractPasswordFromJoinUrl($meeting['join_url'] ?? null) ?? '';
        }
        if ($password === '') {
            $password = $this->zoom->extractPasswordFromJoinUrl($meeting['start_url'] ?? null) ?? '';
        }

        if ($password !== '' && Schema::hasColumn('webinar_settings', 'zoom_password')) {
            $settings->zoom_password = $password;
        }
    }

    private function backfillWebinarMeetingSecrets(WebinarSetting $settings): void
    {
        if (!Schema::hasColumn('webinar_settings', 'zoom_password')) {
            return;
        }

        if (trim((string) ($settings->zoom_password ?? '')) !== '') {
            return;
        }

        $meetingId = trim((string) ($settings->zoom_meeting_id ?? ''));
        if ($meetingId === '') {
            return;
        }

        $meeting = ['join_url' => $settings->zoom_join_url, 'start_url' => $settings->zoom_start_url];
        if ($this->zoom->canManageMeetingViaApi($meetingId)) {
            $details = $this->zoom->getMeeting($meetingId);
            if (is_array($details) && empty($details['error'])) {
                $meeting = array_merge($meeting, $details);
            }
        }

        $this->applyWebinarMeetingSecrets($settings, $meeting);
        if ($settings->isDirty('zoom_password')) {
            $settings->save();
        }
    }

    private function resolvePathwaysStartUrl(WebinarSetting $settings): ?string
    {
        if (!empty($settings->zoom_start_url)) {
            return (string) $settings->zoom_start_url;
        }

        $configured = trim((string) config('services.pathways_webinar.zoom_start_url', ''));
        if ($configured !== '') {
            return $configured;
        }

        $meetingId = $settings->zoom_meeting_id;
        if ($meetingId && $this->zoom->canManageMeetingViaApi($meetingId)) {
            $meeting = $this->zoom->getMeeting($meetingId);
            if (is_array($meeting) && empty($meeting['error'])) {
                $startUrl = $meeting['start_url'] ?? null;
                if (is_string($startUrl) && $startUrl !== '') {
                    return $startUrl;
                }
            }
        }

        return null;
    }

    public function webinarStatus(Request $request)
    {
        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);
        $institutionId = WebinarTenant::actorInstitutionId($actor);
        $settings = WebinarTenant::settingsFor($institutionId);
        $approvedCount = $this->approvedRegistrationCount($institutionId);
        $share = $this->webinarSharePayload($settings);
        $isDaily = $this->webinarDaily->isDailyWebinar($settings) || $this->webinarDaily->shouldUseDaily();

        return response()->json(array_merge([
            'approved_participants' => $approvedCount,
            'can_start' => $approvedCount > 0,
            'recording_enabled' => (bool) $settings->recording_enabled,
            'join_url' => $isDaily
                ? ($share['app_participant_join_url'] ?? $settings->zoom_join_url)
                : $settings->zoom_join_url,
            'start_url' => $isDaily
                ? ($share['app_host_room_url'] ?? $this->webinarDaily->appHostRoomUrl())
                : $this->resolvePathwaysStartUrl($settings),
            'zoom_meeting_id' => $settings->zoom_meeting_id,
            'zoom_scheduled_at' => $settings->zoom_scheduled_at?->toIso8601String(),
            'session_started_at' => $settings->session_started_at?->toIso8601String(),
            'zoom_api_configured' => $this->zoom->isConfigured(),
            'daily_configured' => $this->webinarDaily->shouldUseDaily() || $this->webinarDaily->isDailyWebinar($settings),
            'meeting_provider' => $isDaily ? 'daily' : 'zoom',
            'platform_institution_id' => $institutionId,
            'session_active' => !empty($settings->zoom_meeting_id) && (
                $isDaily || !empty($settings->zoom_start_url)
            ),
        ], $share));
    }

    /**
     * @return array{
     *     topic: string,
     *     share_text: string|null,
     *     password: string|null,
     *     registration_url: string,
     *     app_host_room_url: string,
     *     app_participant_join_url: string|null,
     *     app_host_room_path: string,
     *     app_participant_join_path: string|null
     * }
     */
    private function webinarSharePayload(WebinarSetting $settings): array
    {
        $meetingId = trim((string) ($settings->zoom_meeting_id ?? ''));
        $isDaily = $meetingId !== '' && $this->webinarDaily->isDailyWebinar($settings);
        $base = \App\Support\FrontendUrl::base();
        $registrationUrl = $base . '/meeting-registration';
        $hostPath = '/meeting/room?webinar_host=1&role=1';
        $hostRoomUrl = $base . $hostPath;

        $password = '';
        if (!$isDaily && Schema::hasColumn('webinar_settings', 'zoom_password')) {
            $password = trim((string) ($settings->zoom_password ?? ''));
        }

        $participantPath = $meetingId !== ''
            ? MeetingRegistrationJoinUrl::participantPath($meetingId, $password !== '' ? $password : null)
            : null;
        $participantUrl = $participantPath ? $base . $participantPath : null;

        $lines = ['Meeting Registration — Pathways Webinar'];
        if ($settings->zoom_scheduled_at) {
            $lines[] = 'Scheduled: ' . $settings->zoom_scheduled_at->format('l, F j, Y g:i A T');
        }
        if ($meetingId !== '') {
            $lines[] = ($isDaily ? 'Room: ' : 'Meeting ID: ') . $meetingId;
        }
        if ($password !== '') {
            $lines[] = 'Passcode: ' . $password;
        }
        $lines[] = 'Registration page: ' . $registrationUrl;
        if ($participantUrl) {
            $lines[] = 'Join in app (approved participants): ' . $participantUrl;
        }
        $lines[] = 'Host: open Meeting Registration → Start Meeting to join in-app.';

        return [
            'topic' => 'Meeting Registration Webinar',
            'share_text' => implode("\n", $lines),
            'password' => $password !== '' ? $password : null,
            'registration_url' => $registrationUrl,
            'app_host_room_url' => $hostRoomUrl,
            'app_participant_join_url' => $participantUrl,
            'app_host_room_path' => $hostPath,
            'app_participant_join_path' => $participantPath,
            'meeting_provider' => $isDaily ? 'daily' : 'zoom',
        ];
    }

    public function startWebinar(Request $request)
    {
        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request) ?: $request->user();
        $institutionId = WebinarTenant::actorInstitutionId($actor instanceof User ? $actor : null);
        $approvedCount = $this->approvedRegistrationCount($institutionId);
        if ($approvedCount === 0) {
            return response()->json([
                'message' => 'Cannot start the webinar until at least one participant has registered and been approved.',
                'approved_participants' => 0,
                'can_start' => false,
            ], 422);
        }

        $settings = WebinarTenant::settingsFor($institutionId);

        if ($this->webinarDaily->shouldUseDaily()) {
            $ensured = $this->webinarDaily->ensureRoom($settings, $institutionId);
            if (!$ensured['ok']) {
                return response()->json([
                    'message' => $ensured['message'] ?? 'Could not prepare the Daily webinar room.',
                ], 502);
            }
            $settings = $ensured['settings'];
            $settings->session_started_at = now();
            $settings->save();

            $share = $this->webinarSharePayload($settings);

            return response()->json([
                'message' => 'Opening Daily host session. Share the in-app join link with approved participants.',
                'approved_participants' => $approvedCount,
                'start_url' => $share['app_host_room_url'],
                'join_url' => $share['app_participant_join_url'],
                'recording_enabled' => (bool) $settings->recording_enabled,
                'zoom_meeting_id' => $settings->zoom_meeting_id,
                'meeting_provider' => 'daily',
                'provider' => 'daily',
                'platform_institution_id' => $institutionId,
            ]);
        }

        if (empty($settings->zoom_start_url) || empty($settings->zoom_meeting_id)) {
            $latestQuery = MeetingRegistration::query()
                ->with('availableSchedule')
                ->whereRaw("LOWER(COALESCE(status, 'pending')) = 'approved'")
                ->orderByDesc('id');
            WebinarTenant::scopeRegistrations($latestQuery, $institutionId);
            $latest = $latestQuery->first();

            if ($latest) {
                $schedule = $latest->availableSchedule;
                $startAt = !empty($latest->zoom_start_time)
                    ? Carbon::parse($latest->zoom_start_time)
                    : ($schedule ? $this->getNextStartFromSchedule($schedule) : $this->getNextWebinarStartTime());

                $ensured = $this->ensureScheduledWebinarMeeting($settings, $startAt, $schedule, $actor instanceof User ? $actor : null);
                if (!$ensured['ok']) {
                    return response()->json([
                        'message' => $ensured['message'] ?? 'Could not prepare the Zoom meeting.',
                        'details' => $ensured['details'] ?? null,
                    ], 502);
                }
                $settings->refresh();
            } else {
                $created = $this->createWebinarZoomSession($settings, $actor instanceof User ? $actor : null);
                if (!$created['ok']) {
                    return response()->json([
                        'message' => $created['message'] ?? 'Failed to create Zoom webinar meeting.',
                        'details' => $created['details'] ?? null,
                    ], 502);
                }
                $settings = $created['settings'];
            }
        }

        $meetingId = (string) $settings->zoom_meeting_id;
        if ($settings->recording_enabled && $meetingId && $this->zoom->canManageMeetingViaApi($meetingId)) {
            $this->zoom->setMeetingAutoRecording($meetingId, true);
        }

        $this->backfillWebinarMeetingSecrets($settings);

        $settings->session_started_at = now();
        $settings->save();

        return response()->json([
            'message' => 'Opening host session. Registered participants already have the join link from their confirmation email.',
            'approved_participants' => $approvedCount,
            'start_url' => $settings->zoom_start_url,
            'join_url' => $settings->zoom_join_url,
            'recording_enabled' => (bool) $settings->recording_enabled,
            'zoom_meeting_id' => $settings->zoom_meeting_id,
            'meeting_provider' => 'zoom',
            'provider' => 'zoom',
            'platform_institution_id' => $institutionId,
        ]);
    }

    public function setWebinarRecording(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $enabled = (bool) $data['enabled'];
        $institutionId = WebinarTenant::fromRequest($request);
        $settings = WebinarTenant::settingsFor($institutionId);
        $settings->recording_enabled = $enabled;
        $settings->save();

        $activeMeetingId = $settings->zoom_meeting_id;
        if ($activeMeetingId && $this->zoom->canManageMeetingViaApi($activeMeetingId)) {
            $result = $this->zoom->setMeetingAutoRecording($activeMeetingId, $enabled);
            if ($result === null) {
                return response()->json([
                    'message' => 'Recording preference saved, but Zoom could not be contacted to update the live meeting.',
                    'recording_enabled' => $enabled,
                ], 503);
            }
            if (!empty($result['error'])) {
                $zoomMessage = $result['body']['message'] ?? 'Zoom rejected the recording setting change.';

                return response()->json([
                    'message' => $zoomMessage,
                    'details' => $result['body'] ?? null,
                    'recording_enabled' => $enabled,
                ], 502);
            }

            return response()->json([
                'message' => $enabled ? 'Cloud recording enabled on the active Zoom meeting.' : 'Cloud recording disabled on the active Zoom meeting.',
                'recording_enabled' => $enabled,
                'zoom_meeting_id' => $activeMeetingId,
            ]);
        }

        return response()->json([
            'message' => $enabled
                ? 'Cloud recording enabled. It will apply automatically when you click Start Meeting.'
                : 'Cloud recording disabled for the next webinar session.',
            'recording_enabled' => $enabled,
            'zoom_meeting_id' => null,
        ]);
    }

    public function webinarRecordings(Request $request)
    {
        $institutionId = WebinarTenant::fromRequest($request);
        $settings = WebinarTenant::settingsFor($institutionId);

        $trackedIds = AdminRecordingCatalog::trackedMeetingIds();
        $collected = $this->zoom->collectAllCloudRecordings($trackedIds, 12);
        $items = AdminRecordingCatalog::annotateItems(
            $this->zoom->formatRecordingItems(['meetings' => $collected['meetings']])
        );

        $tenantMeetingId = trim((string) ($settings->zoom_meeting_id ?? ''));
        if ($tenantMeetingId !== '') {
            $items = array_values(array_filter($items, function ($item) use ($tenantMeetingId) {
                return (string) ($item['id'] ?? '') === $tenantMeetingId;
            }));
        } else {
            $items = [];
        }

        return response()->json(['recordings' => $items]);
    }

    public function index(Request $request)
    {
        $institutionId = WebinarTenant::fromRequest($request);
        $query = MeetingRegistration::query()
            ->with('availableSchedule')
            ->orderByDesc('id');
        WebinarTenant::scopeRegistrations($query, $institutionId);

        if ($request->boolean('with_user')) {
            $query->with('user');
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        if (!Schema::hasColumn('meeting_registrations', 'available_schedule_id')) {
            return response()->json([
                'message' => 'available_schedule_id column is missing. Please run migrations.',
            ], 500);
        }

        $data = $request->validate([
            'full_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'learner_timezone' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'available_schedule_id' => 'required|exists:available_schedules,id',
            'schedule_label' => 'nullable|string',
            'meeting_at' => 'nullable|date',
            'platform_institution_id' => 'nullable|integer|min:1',
        ]);

        $scheduleLabelFromForm = $data['schedule_label'] ?? null;

        $schedule = null;
        if (!empty($data['available_schedule_id'])) {
            $schedule = AvailableSchedule::query()->find($data['available_schedule_id']);
        }
        if (!$schedule) {
            throw ValidationException::withMessages([
                'available_schedule_id' => ['The selected schedule could not be found.'],
            ]);
        }

        $scheduleInstitutionId = WebinarTenant::fromSchedule($schedule);
        $requestedInstitutionId = isset($data['platform_institution_id'])
            ? (int) $data['platform_institution_id']
            : null;

        // Keep hub and institution booking calendars fully independent.
        if ($requestedInstitutionId && $requestedInstitutionId > 0) {
            if ($scheduleInstitutionId !== $requestedInstitutionId) {
                throw ValidationException::withMessages([
                    'available_schedule_id' => ['This time slot is not available for this institution.'],
                ]);
            }
            $tenantInstitutionId = $requestedInstitutionId;
        } else {
            if ($scheduleInstitutionId) {
                throw ValidationException::withMessages([
                    'available_schedule_id' => ['This time slot belongs to an institution portal, not the main platform.'],
                ]);
            }
            $tenantInstitutionId = null;
        }

        $startAt = $this->resolveMeetingStartAt($schedule, $data['meeting_at'] ?? null);

        if ($this->meetingSlotIsTaken($startAt, $tenantInstitutionId)) {
            throw ValidationException::withMessages([
                'meeting_at' => ['This time slot is no longer available. Please choose another time.'],
            ]);
        }

        return DB::transaction(function () use ($data, $scheduleLabelFromForm, $schedule, $startAt, $tenantInstitutionId) {
            $user = User::where('email', $data['email'])->first();

            $hasPhone = Schema::hasColumn('users', 'phone');
            $hasStatus = Schema::hasColumn('users', 'status');

            $learnerTimezone = trim((string) ($data['learner_timezone'] ?? $data['country'] ?? ''));
            if ($learnerTimezone === '') {
                $learnerTimezone = null;
            }

            if (!$user) {
                $create = [
                    'name' => $data['full_name'],
                    'email' => $data['email'],
                    'password' => Hash::make(Str::random(32)),
                    'role' => 'meeting_user',
                ];

                if ($hasPhone) {
                    $create['phone'] = $data['phone'] ?? null;
                }
                if ($hasStatus) {
                    $create['status'] = 'Active';
                }

                $user = User::create($create);
            } else {
                $user->name = $data['full_name'];
                // Never demote institution owners / platform staff / instructors to meeting_user.
                $currentRole = strtolower(trim((string) ($user->role ?? '')));
                $protectedRoles = ['partner_company', 'admin', 'staff', 'instructor'];
                if (!in_array($currentRole, $protectedRoles, true)) {
                    $user->role = 'meeting_user';
                }

                if ($hasPhone && array_key_exists('phone', $data)) {
                    $user->phone = $data['phone'];
                }

                if ($hasStatus && empty($user->status)) {
                    $user->status = 'Active';
                }
                $user->save();
            }

            $createRegistration = [
                'user_id' => $user->id,
                'available_schedule_id' => $data['available_schedule_id'],
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'country' => $learnerTimezone,
                'notes' => $data['notes'] ?? null,
            ];

            if (Schema::hasColumn('meeting_registrations', 'platform_institution_id')) {
                $createRegistration['platform_institution_id'] = $tenantInstitutionId;
            }

            // Auto-approve on registration; Zoom + confirmation email run after the HTTP response.
            if (Schema::hasColumn('meeting_registrations', 'status')) {
                $createRegistration['status'] = 'Approved';
            }
            if (Schema::hasColumn('meeting_registrations', 'schedule_label') && $scheduleLabelFromForm) {
                $createRegistration['schedule_label'] = $scheduleLabelFromForm;
            }
            if (Schema::hasColumn('meeting_registrations', 'rejected_reason')) {
                $createRegistration['rejected_reason'] = null;
            }
            if (Schema::hasColumn('meeting_registrations', 'zoom_start_time')) {
                $createRegistration['zoom_start_time'] = $startAt->copy()->utc()->format('Y-m-d H:i:s');
            }
            if (Schema::hasColumn('meeting_registrations', 'reminder_sent_at')) {
                $createRegistration['reminder_sent_at'] = null;
            }
            if (Schema::hasColumn('meeting_registrations', 'final_reminder_sent_at')) {
                $createRegistration['final_reminder_sent_at'] = null;
            }

            $registration = MeetingRegistration::create($createRegistration);

            ProvisionMeetingRegistrationJob::dispatch($registration->id, $scheduleLabelFromForm)->afterResponse();

            return response()->json([
                'message' => 'Booking confirmed. A confirmation email with your meeting link will arrive shortly.',
                'role' => $user->role,
                'user' => $user,
                'registration' => $registration->fresh(),
            ], 201);
        });
    }

    public function update(Request $request, MeetingRegistration $meetingRegistration)
    {
        $data = $request->validate([
            'full_name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255',
            'phone' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'status' => 'nullable|string|max:255',
            'available_schedule_id' => 'nullable|integer',
        ]);

        if (!Schema::hasColumn('meeting_registrations', 'status')) {
            unset($data['status']);
        }

        if (!Schema::hasColumn('meeting_registrations', 'available_schedule_id')) {
            unset($data['available_schedule_id']);
        }
        if (array_key_exists('available_schedule_id', $data) && $data['available_schedule_id'] !== null) {
            $request->validate([
                'available_schedule_id' => 'exists:available_schedules,id',
            ]);
        }

        $meetingRegistration->fill($data);
        $meetingRegistration->save();

        return response()->json([
            'message' => 'Meeting registration updated',
            'registration' => $meetingRegistration,
        ]);
    }

    public function approve(MeetingRegistration $meetingRegistration)
    {
        $schedule = null;
        if (Schema::hasColumn('meeting_registrations', 'available_schedule_id') && !empty($meetingRegistration->available_schedule_id)) {
            $schedule = AvailableSchedule::query()->find($meetingRegistration->available_schedule_id);
        }

        $startAt = !empty($meetingRegistration->zoom_start_time)
            ? Carbon::parse($meetingRegistration->zoom_start_time)
            : ($schedule ? $this->getNextStartFromSchedule($schedule) : $this->getNextWebinarStartTime());

        if (Schema::hasColumn('meeting_registrations', 'status')) {
            $meetingRegistration->status = 'Approved';
            if (Schema::hasColumn('meeting_registrations', 'rejected_reason')) {
                $meetingRegistration->rejected_reason = null;
            }
            if (Schema::hasColumn('meeting_registrations', 'zoom_start_time')) {
                $meetingRegistration->zoom_start_time = $startAt->toDateTimeString();
            }
            if (Schema::hasColumn('meeting_registrations', 'reminder_sent_at')) {
                $meetingRegistration->reminder_sent_at = null;
            }

            $meetingRegistration->save();
        }

        ProvisionMeetingApprovalJob::dispatch($meetingRegistration->id)->afterResponse();

        return response()->json([
            'message' => 'Meeting registration approved. Zoom join link will be sent by email shortly.',
            'registration' => $meetingRegistration->fresh(),
        ]);
    }

    public function reject(Request $request, MeetingRegistration $meetingRegistration)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        if (Schema::hasColumn('meeting_registrations', 'status')) {
            $meetingRegistration->status = 'Rejected';
        }
        if (Schema::hasColumn('meeting_registrations', 'rejected_reason')) {
            $meetingRegistration->rejected_reason = $data['reason'];
        }

        $meetingRegistration->save();

        SendMeetingRegistrationStatusEmailJob::dispatch(
            $meetingRegistration->id,
            'Rejected',
            $data['reason'],
        )->afterResponse();

        return response()->json([
            'message' => 'Meeting registration rejected. Notification email will be sent shortly.',
            'registration' => $meetingRegistration,
        ]);
    }

    public function reschedule(Request $request, MeetingRegistration $meetingRegistration)
    {
        $data = $request->validate([
            'available_schedule_id' => 'nullable|integer|exists:available_schedules,id',
            'message' => 'nullable|string|max:2000',
        ]);

        if (!$meetingRegistration->email) {
            return response()->json([
                'message' => 'Registration has no email address to notify.',
            ], 422);
        }

        // Optionally move the meeting to a newly proposed schedule / time.
        if (array_key_exists('available_schedule_id', $data) && !empty($data['available_schedule_id'])
            && Schema::hasColumn('meeting_registrations', 'available_schedule_id')) {
            $schedule = AvailableSchedule::query()->find($data['available_schedule_id']);
            $meetingRegistration->available_schedule_id = $data['available_schedule_id'];

            if ($schedule && Schema::hasColumn('meeting_registrations', 'zoom_start_time')) {
                $meetingRegistration->zoom_start_time = $this->getNextStartFromSchedule($schedule)->toDateTimeString();
            }
        }

        if (Schema::hasColumn('meeting_registrations', 'status')) {
            $meetingRegistration->status = 'Rescheduled';
        }
        if (Schema::hasColumn('meeting_registrations', 'reminder_sent_at')) {
            $meetingRegistration->reminder_sent_at = null;
        }
        if (Schema::hasColumn('meeting_registrations', 'cancel_token') && empty($meetingRegistration->cancel_token)) {
            $meetingRegistration->cancel_token = Str::random(48);
        }

        $meetingRegistration->save();

        SendMeetingRegistrationStatusEmailJob::dispatch(
            $meetingRegistration->id,
            'Rescheduled',
            $data['message'] ?? null,
        )->afterResponse();

        return response()->json([
            'message' => 'Reschedule request sent. The attendee will receive an apology email with options to rebook or cancel.',
            'registration' => $meetingRegistration->fresh(),
        ]);
    }

    public function cancelByToken(Request $request, string $token)
    {
        $registration = null;
        if (Schema::hasColumn('meeting_registrations', 'cancel_token')) {
            $registration = MeetingRegistration::query()->where('cancel_token', $token)->first();
        }

        $rebookUrl = rtrim(FrontendUrl::base(), '/') . '/meeting-registration';

        if (!$registration) {
            return response()->view('cancel.meeting_cancel_result', [
                'appName' => config('app.name'),
                'success' => false,
                'name' => null,
                'rebookUrl' => $rebookUrl,
            ], 404);
        }

        $alreadyCancelled = strtolower((string) ($registration->status ?? '')) === 'cancelled';

        if (!$alreadyCancelled && Schema::hasColumn('meeting_registrations', 'status')) {
            $registration->status = 'Cancelled';
            $registration->save();
        }

        return response()->view('cancel.meeting_cancel_result', [
            'appName' => config('app.name'),
            'success' => true,
            'name' => $registration->full_name,
            'rebookUrl' => $rebookUrl,
        ]);
    }

    public function remind(Request $request, MeetingRegistration $meetingRegistration)
    {
        $data = $request->validate([
            'message' => 'nullable|string|max:2000',
        ]);

        SendMeetingRegistrationReminderEmailJob::dispatch(
            $meetingRegistration->id,
            $data['message'] ?? null,
        )->afterResponse();

        return response()->json([
            'message' => 'Reminder will be sent shortly.',
            'registration' => $meetingRegistration,
        ]);
    }

    public function resendJoinLink(MeetingRegistration $meetingRegistration)
    {
        if (!$meetingRegistration->email) {
            return response()->json([
                'message' => 'Registration has no email address.',
            ], 422);
        }

        $status = strtolower((string) ($meetingRegistration->status ?? 'pending'));
        if ($status !== 'approved') {
            return response()->json([
                'message' => 'Only approved registrations can receive a Zoom join link.',
            ], 422);
        }

        ResendMeetingJoinLinkJob::dispatch($meetingRegistration->id)->afterResponse();

        return response()->json([
            'message' => 'Zoom join link will be resent by email shortly.',
            'registration' => $meetingRegistration,
        ]);
    }

    public function destroy(MeetingRegistration $meetingRegistration)
    {
        $meetingRegistration->delete();

        return response()->json([
            'message' => 'Meeting registration deleted',
        ]);
    }
}
