<?php

namespace App\Services;

use App\Models\AvailableSchedule;
use App\Models\MeetingRegistration;
use App\Support\InstitutionEmailBranding;
use App\Support\MeetingRegistrationJoinUrl;
use App\Support\MeetingScheduleTimeFormatter;
use App\Support\WebinarTenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MeetingRegistrationNotificationService
{
    public function __construct(
        protected MailDeliveryService $mail,
        protected ZoomService $zoom,
    ) {
    }

    protected function institutionIdFor(MeetingRegistration $registration): ?int
    {
        $id = WebinarTenant::fromRegistration($registration);

        return $id && $id > 0 ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  callable(\Illuminate\Mail\Message): void  $callback
     * @param  array<string, mixed>  $context
     */
    protected function sendMeetingView(
        MeetingRegistration $registration,
        string $view,
        array $data,
        callable $callback,
        array $context = [],
    ): void {
        $institutionId = $this->institutionIdFor($registration);
        $brand = InstitutionEmailBranding::forInstitutionId($institutionId);
        $payload = array_merge($data, [
            'appName' => $brand['companyName'],
            'companyName' => $brand['companyName'],
            'meetBrand' => $brand['meetBrand'],
            'emailBrand' => $brand,
            'brandPrimary' => $brand['primaryColor'],
            'bookAnotherUrl' => $data['bookAnotherUrl'] ?? $brand['bookMeetingUrl'],
        ]);

        if ($institutionId) {
            $this->mail->sendViewForInstitution($institutionId, $view, $payload, $callback, $context);
            return;
        }

        $this->mail->sendView($view, $payload, $callback, $context);
    }

    public function sendStatusEmail(
        MeetingRegistration $meetingRegistration,
        string $status,
        ?string $reason = null,
        ?string $joinUrl = null,
        ?string $frontendScheduleLabel = null,
    ): void {
        $to = $meetingRegistration->email;
        if (!$to) {
            return;
        }

        try {
            if (strtolower($status) === 'rescheduled') {
                $meetingRegistration = $this->ensureCancelToken($meetingRegistration);
                $rebookUrl = $this->bookAnotherUrl($meetingRegistration);
                $cancelUrl = $this->cancelUrl($meetingRegistration);

                $proposedTime = null;
                try {
                    if (!empty($meetingRegistration->zoom_start_time)) {
                        $tz = (string) config('services.pathways_webinar.timezone', 'Africa/Kigali');
                        if ($meetingRegistration->relationLoaded('availableSchedule') && !empty($meetingRegistration->availableSchedule?->timezone)) {
                            $tz = (string) $meetingRegistration->availableSchedule->timezone;
                        }
                        $proposedTime = Carbon::parse($meetingRegistration->zoom_start_time)->setTimezone($tz)->format('l, M j, Y g:i A') . ' (' . $tz . ')';
                    }
                } catch (\Throwable $e) {
                    $proposedTime = null;
                }

                $defaultApology = "We sincerely apologize for any inconvenience. Due to an unexpected scheduling conflict, we need to reschedule your appointment. Please let us know your preferred date and time, and we will do our best to accommodate you. Thank you for your patience and understanding.";

                $this->sendMeetingView($meetingRegistration, 'emails.meeting_registration_rescheduled', [
                    'name' => $meetingRegistration->full_name ?? '',
                    'apologyMessage' => ($reason && trim($reason) !== '') ? $reason : $defaultApology,
                    'proposedTime' => $proposedTime,
                    'rebookUrl' => $rebookUrl,
                    'cancelUrl' => $cancelUrl,
                ], function ($message) use ($to) {
                    $message->to($to)->subject('We need to reschedule your appointment');
                }, [
                    'event' => 'meeting_registration_rescheduled',
                    'meeting_registration_id' => $meetingRegistration->id ?? null,
                    'to' => $to,
                ]);
            } elseif (strtolower($status) === 'rejected') {
                $this->sendMeetingView($meetingRegistration, 'emails.meeting_registration_rejected', [
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
                $meetingRegistration = $this->ensureCancelToken($meetingRegistration);

                $effectiveJoinUrl = $joinUrl;
                if (!$effectiveJoinUrl) {
                    $effectiveJoinUrl = MeetingRegistrationJoinUrl::forRegistration($meetingRegistration);
                }
                if (!$effectiveJoinUrl && !empty($meetingRegistration->zoom_join_url)) {
                    $effectiveJoinUrl = $meetingRegistration->zoom_join_url;
                }
                if (!$effectiveJoinUrl && !$this->zoom->isConfigured()) {
                    $effectiveJoinUrl = (string) config('services.pathways_webinar.zoom_join_url');
                }

                $savedLabel = trim((string) ($meetingRegistration->schedule_label ?? ''));
                $nextSessionText = $savedLabel !== '' ? $savedLabel : $frontendScheduleLabel;
                $sessionDetails = MeetingScheduleTimeFormatter::buildEmailDetails(
                    $meetingRegistration,
                    $nextSessionText ?: null
                );

                if (!$sessionDetails['learnerSession']) {
                    if ($meetingRegistration->relationLoaded('availableSchedule') && $meetingRegistration->availableSchedule) {
                        $nextSessionText = $this->learnerScheduleLabel(
                            $meetingRegistration->availableSchedule,
                            $meetingRegistration->country ?? null
                        );
                        if (!$nextSessionText) {
                            $nextSessionText = $this->scheduleLabel($meetingRegistration->availableSchedule);
                        }
                    }
                    if (!$nextSessionText) {
                        $tz = MeetingScheduleTimeFormatter::scheduleTimezone($meetingRegistration->availableSchedule);
                        try {
                            if (!empty($meetingRegistration->zoom_start_time)) {
                                $nextStart = Carbon::parse($meetingRegistration->zoom_start_time)->setTimezone($tz);
                                $nextSessionText = $nextStart->format('Y-m-d H:i') . ' (' . $tz . ')';
                            }
                        } catch (\Throwable $e) {
                            $nextSessionText = null;
                        }
                    }
                    $sessionDetails = MeetingScheduleTimeFormatter::buildEmailDetails(
                        $meetingRegistration,
                        $nextSessionText
                    );
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

                $topic = trim((string) ($scheduleDescription ?? ''));
                if ($topic === '') {
                    $topic = 'Potential Partnership Discussion';
                }

                $brand = InstitutionEmailBranding::forInstitutionId($this->institutionIdFor($meetingRegistration));
                $this->sendMeetingView($meetingRegistration, 'emails.meeting_registration_approved', [
                    'name' => $meetingRegistration->full_name ?? '',
                    'topic' => $topic,
                    'joinUrl' => $effectiveJoinUrl,
                    'joinUrlDisplay' => $this->friendlyJoinDisplay($effectiveJoinUrl),
                    'nextSession' => $sessionDetails['learnerSession'],
                    'hostSession' => $sessionDetails['hostSession'],
                    'duration' => $sessionDetails['duration'],
                    'learnerTimezone' => $sessionDetails['learnerTimezone'],
                    'hostTimezone' => $sessionDetails['hostTimezone'],
                    'platform' => $brand['meetBrand'],
                    'scheduleDescription' => $scheduleDescription,
                    'learnerNotes' => $learnerNotes,
                    'recipientEmail' => $to,
                    'cancelUrl' => $this->cancelUrl($meetingRegistration),
                    'bookAnotherUrl' => $this->bookAnotherUrl($meetingRegistration),
                ], function ($message) use ($to, $brand) {
                    $message->to($to)->subject('Your appointment is confirmed — ' . $brand['meetBrand']);
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
                $brand = InstitutionEmailBranding::forInstitutionId($this->institutionIdFor($meetingRegistration));
                $lines[] = 'Thank you,';
                $lines[] = $brand['companyName'];

                $this->mail->sendRaw(implode("\n", $lines), function ($message) use ($to, $subject, $brand) {
                    $message->from(
                        (string) config('mail.from.address'),
                        $brand['companyName']
                    )->to($to)->subject($subject);
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

    public function sendReminderEmail(MeetingRegistration $meetingRegistration, ?string $message = null): void
    {
        $to = $meetingRegistration->email;
        if (!$to) {
            return;
        }

        $meetingRegistration = $this->ensureCancelToken($meetingRegistration);

        $effectiveJoinUrl = MeetingRegistrationJoinUrl::forRegistration($meetingRegistration);
        if (!$effectiveJoinUrl && !empty($meetingRegistration->zoom_join_url)) {
            $effectiveJoinUrl = $meetingRegistration->zoom_join_url;
        }
        if (!$effectiveJoinUrl && !$this->zoom->isConfigured()) {
            $effectiveJoinUrl = (string) config('services.pathways_webinar.zoom_join_url');
        }

        $sessionDetails = MeetingScheduleTimeFormatter::buildEmailDetails($meetingRegistration);
        if (!$sessionDetails['learnerSession']) {
            $nextSessionText = null;
            if ($meetingRegistration->relationLoaded('availableSchedule') && $meetingRegistration->availableSchedule) {
                $nextSessionText = $this->learnerScheduleLabel($meetingRegistration->availableSchedule, $meetingRegistration->country ?? null);
                if (!$nextSessionText) {
                    $nextSessionText = $this->scheduleLabel($meetingRegistration->availableSchedule);
                }
            }
            if (!$nextSessionText) {
                $tz = MeetingScheduleTimeFormatter::scheduleTimezone($meetingRegistration->availableSchedule);
                try {
                    if (!empty($meetingRegistration->zoom_start_time)) {
                        $nextStart = Carbon::parse($meetingRegistration->zoom_start_time)->setTimezone($tz);
                        $nextSessionText = $nextStart->format('Y-m-d H:i') . ' (' . $tz . ')';
                    }
                } catch (\Throwable $e) {
                    $nextSessionText = null;
                }
            }
            $sessionDetails = MeetingScheduleTimeFormatter::buildEmailDetails($meetingRegistration, $nextSessionText);
        }

        $brand = InstitutionEmailBranding::forInstitutionId($this->institutionIdFor($meetingRegistration));
        $this->sendMeetingView($meetingRegistration, 'emails.meeting_registration_reminder', [
            'name' => $meetingRegistration->full_name ?? '',
            'joinUrl' => $effectiveJoinUrl,
            'joinUrlDisplay' => $this->friendlyJoinDisplay($effectiveJoinUrl),
            'nextSession' => $sessionDetails['learnerSession'],
            'hostSession' => $sessionDetails['hostSession'],
            'duration' => $sessionDetails['duration'],
            'learnerTimezone' => $sessionDetails['learnerTimezone'],
            'hostTimezone' => $sessionDetails['hostTimezone'],
            'platform' => $brand['meetBrand'],
            'customMessage' => $message,
            'cancelUrl' => $this->cancelUrl($meetingRegistration),
            'bookAnotherUrl' => $this->bookAnotherUrl($meetingRegistration),
            'recipientEmail' => $to,
        ], function ($messageObj) use ($to, $brand) {
            $messageObj->to($to)->subject('Reminder: Your upcoming ' . $brand['meetBrand'] . ' session');
        }, [
            'event' => 'meeting_registration_reminder',
            'meeting_registration_id' => $meetingRegistration->id ?? null,
            'to' => $to,
        ]);
    }

    protected function ensureCancelToken(MeetingRegistration $registration): MeetingRegistration
    {
        if (!Schema::hasColumn('meeting_registrations', 'cancel_token')) {
            return $registration;
        }

        if (!empty($registration->cancel_token)) {
            return $registration;
        }

        $registration->cancel_token = Str::random(48);
        $registration->save();

        return $registration->fresh() ?? $registration;
    }

    protected function cancelUrl(MeetingRegistration $registration): ?string
    {
        if (empty($registration->cancel_token)) {
            return null;
        }

        return rtrim((string) config('app.url'), '/') . '/meeting/cancel/' . $registration->cancel_token;
    }

    protected function bookAnotherUrl(?MeetingRegistration $registration = null): string
    {
        $institutionId = $registration ? $this->institutionIdFor($registration) : null;
        $brand = InstitutionEmailBranding::forInstitutionId($institutionId);

        return $brand['bookMeetingUrl'];
    }

    protected function friendlyJoinDisplay(?string $joinUrl): ?string
    {
        if (!$joinUrl) {
            return null;
        }

        $trimmed = preg_replace('#^https?://#i', '', $joinUrl) ?? $joinUrl;

        return rtrim($trimmed, '/');
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

        if (str_contains($country, '/')) {
            try {
                new \DateTimeZone($country);

                return $country;
            } catch (\Throwable $e) {
                // fall through
            }
        }

        $c = mb_strtolower($country);

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
        if (str_contains($c, 'canada')) {
            return 'America/Toronto';
        }
        if (str_contains($c, 'united states') || str_contains($c, 'usa')) {
            return 'America/New_York';
        }
        if (str_contains($c, 'united kingdom') || str_contains($c, 'uk')) {
            return 'Europe/London';
        }
        if (str_contains($c, 'france')) {
            return 'Europe/Paris';
        }
        if (str_contains($c, 'germany')) {
            return 'Europe/Berlin';
        }

        return $fallback;
    }

    private function learnerScheduleLabel(?AvailableSchedule $schedule, ?string $registrationCountry): ?string
    {
        if (!$schedule) {
            return null;
        }

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
                $core = substr($raw, 0, 5);
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

            $startText = $startLocal->format('D g:i A');
            $endText = $endLocal->format('g:i A');

            $suffix = $primaryCountry ? (' (' . $primaryCountry . ' time)') : '';

            return $startText . ' - ' . $endText . $suffix;
        } catch (\Throwable $e) {
            return $this->scheduleLabel($schedule);
        }
    }
}
