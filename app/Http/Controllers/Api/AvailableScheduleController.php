<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AvailableSchedule;
use App\Models\MeetingRegistration;
use App\Support\PlatformInstitutionHelper;
use App\Support\WebinarTenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AvailableScheduleController extends Controller
{
    private function ensureDefaultSchedules(): void
    {
        // Availability is managed per date on the admin calendar — no auto weekly rows.
    }

    private function resolveInstitutionId(Request $request): ?int
    {
        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);

        // Partners are always locked to their own institution (never hub / never another tenant).
        if ($actor && strtolower(trim((string) ($actor->role ?? ''))) === 'partner_company') {
            $id = (int) ($actor->platform_institution_id ?? 0);
            if ($id > 0) {
                return $id;
            }

            return 0;
        }

        // Public institution booking pages pass this explicitly (even if a hub admin is browsing).
        if ($request->filled('platform_institution_id')) {
            $requested = (int) $request->input('platform_institution_id');
            if ($requested > 0) {
                return $requested;
            }
        }

        return WebinarTenant::actorInstitutionId($actor);
    }

    private function resolveInstitutionIdForWrite(Request $request): ?int
    {
        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request) ?: $request->user();
        if ($actor && strtolower(trim((string) ($actor->role ?? ''))) === 'partner_company') {
            $id = (int) ($actor->platform_institution_id ?? 0);
            if ($id > 0) {
                return $id;
            }

            // Heal unlinked partner sessions that still send the institution id from the client.
            $requested = (int) $request->input('platform_institution_id', 0);
            if ($requested > 0) {
                $owns = \App\Models\PlatformInstitution::query()
                    ->where('id', $requested)
                    ->where(function ($q) use ($actor) {
                        $q->where('owner_user_id', $actor->id)
                            ->orWhereRaw('LOWER(TRIM(contact_email)) = ?', [
                                strtolower(trim((string) ($actor->email ?? ''))),
                            ]);
                    })
                    ->exists();
                if ($owns) {
                    $actor->platform_institution_id = $requested;
                    $actor->save();

                    return $requested;
                }
            }

            return 0;
        }

        return WebinarTenant::actorInstitutionId($actor instanceof \App\Models\User ? $actor : null);
    }

    private function syncDayOfWeek(array $data): array
    {
        if (!empty($data['available_on_date'])) {
            try {
                $data['day_of_week'] = Carbon::parse($data['available_on_date'])->dayOfWeek;
            } catch (\Throwable $e) {
                // keep provided day_of_week
            }
        }

        return $data;
    }

    private function calendarPayload(?int $institutionId = null): array
    {
        $settings = WebinarTenant::settingsFor($institutionId);

        return [
            'blocked_months' => array_values($settings->calendar_blocked_months ?? []),
            'blocked_dates' => array_values($settings->calendar_blocked_dates ?? []),
        ];
    }

    private function bookedSlotsPayload(?int $institutionId = null): array
    {
        if (!Schema::hasColumn('meeting_registrations', 'zoom_start_time')) {
            return [];
        }

        $query = MeetingRegistration::query()
            ->whereNotNull('zoom_start_time')
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereRaw("LOWER(COALESCE(status, 'pending')) = 'approved'")
                    ->orWhereRaw("LOWER(COALESCE(status, 'pending')) = 'pending'");
            })
            ->orderBy('zoom_start_time');
        WebinarTenant::scopeRegistrations($query, $institutionId);

        return $query
            ->get([
                'id',
                'full_name',
                'email',
                'status',
                'schedule_label',
                'zoom_start_time',
                'available_schedule_id',
            ])
            ->map(function (MeetingRegistration $registration) {
                try {
                    $startsAt = Carbon::parse($registration->zoom_start_time)->utc()->toIso8601String();
                } catch (\Throwable $e) {
                    return null;
                }

                return [
                    'registration_id' => $registration->id,
                    'starts_at' => $startsAt,
                    'schedule_id' => $registration->available_schedule_id,
                    'full_name' => $registration->full_name,
                    'email' => $registration->email,
                    'status' => $registration->status,
                    'schedule_label' => $registration->schedule_label,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function index(Request $request)
    {
        $this->ensureDefaultSchedules();
        $institutionId = $this->resolveInstitutionId($request);

        $query = AvailableSchedule::query()
            ->whereNotNull('available_on_date')
            ->orderBy('available_on_date')
            ->orderBy('start_time');
        WebinarTenant::scopeSchedules($query, $institutionId);

        return response()->json([
            'schedules' => $query->get(),
            'calendar' => $this->calendarPayload($institutionId),
            'booked_slots' => $this->bookedSlotsPayload($institutionId),
            'platform_institution_id' => $institutionId,
        ], 200);
    }

    public function updateCalendar(Request $request)
    {
        $data = $request->validate([
            'blocked_months' => 'nullable|array',
            'blocked_months.*' => 'string|regex:/^\d{4}-\d{2}$/',
            'blocked_dates' => 'nullable|array',
            'blocked_dates.*' => 'string|regex:/^\d{4}-\d{2}-\d{2}$/',
        ]);

        $institutionId = $this->resolveInstitutionId($request);
        $settings = WebinarTenant::settingsFor($institutionId);
        $settings->calendar_blocked_months = array_values(array_unique($data['blocked_months'] ?? []));
        $settings->calendar_blocked_dates = array_values(array_unique($data['blocked_dates'] ?? []));
        $settings->save();

        return response()->json([
            'message' => 'Meeting calendar availability updated',
            'calendar' => $this->calendarPayload($institutionId),
        ], 200);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'day_of_week' => 'nullable|integer|min:0|max:6',
            'available_on_date' => 'required|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'meeting_duration_minutes' => 'nullable|integer|min:15|max:180',
            'timezone' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $data = $this->syncDayOfWeek($data);

        $data['timezone'] = $data['timezone'] ?? 'Africa/Nairobi';
        $data['meeting_duration_minutes'] = (int) ($data['meeting_duration_minutes'] ?? 60);
        $data['is_active'] = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

        $user = PlatformInstitutionHelper::resolveActorFromRequest($request) ?: $request->user();
        if ($user) {
            $data['created_by'] = $user->id;
        }

        $institutionId = $this->resolveInstitutionIdForWrite($request);
        if ($institutionId === 0) {
            return response()->json([
                'message' => 'Your institution account is not linked. Re-login and try again.',
            ], 422);
        }
        if (Schema::hasColumn('available_schedules', 'platform_institution_id')) {
            $data['platform_institution_id'] = $institutionId && $institutionId > 0 ? $institutionId : null;
        }

        $slot = AvailableSchedule::create($data);

        return response()->json([
            'message' => 'Available schedule created',
            'slot' => $slot,
        ], 201);
    }

    public function bulkUpsert(Request $request)
    {
        $data = $request->validate([
            'dates' => 'required|array|min:1|max:400',
            'dates.*' => 'date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'meeting_duration_minutes' => 'nullable|integer|min:15|max:180',
            'timezone' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $timezone = $data['timezone'] ?? 'Africa/Nairobi';
        $duration = (int) ($data['meeting_duration_minutes'] ?? 60);
        $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
        $notes = $data['notes'] ?? null;
        $user = PlatformInstitutionHelper::resolveActorFromRequest($request) ?: $request->user();
        $userId = $user?->id;
        $institutionId = $this->resolveInstitutionIdForWrite($request);
        if ($institutionId === 0) {
            return response()->json([
                'message' => 'Your institution account is not linked. Re-login and try again.',
            ], 422);
        }

        $created = 0;
        $updated = 0;

        foreach (array_values(array_unique($data['dates'])) as $date) {
            $payload = $this->syncDayOfWeek([
                'available_on_date' => $date,
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'meeting_duration_minutes' => $duration,
                'timezone' => $timezone,
                'is_active' => $isActive,
                'notes' => $notes,
            ]);

            if (Schema::hasColumn('available_schedules', 'platform_institution_id')) {
                $payload['platform_institution_id'] = $institutionId && $institutionId > 0 ? $institutionId : null;
            }

            $existingQuery = AvailableSchedule::query()->where('available_on_date', $date);
            WebinarTenant::scopeSchedules($existingQuery, $institutionId);
            $existing = $existingQuery->first();

            if ($existing) {
                $existing->fill($payload);
                if ($userId) {
                    $existing->created_by = $userId;
                }
                $existing->save();
                $updated++;
            } else {
                if ($userId) {
                    $payload['created_by'] = $userId;
                }
                AvailableSchedule::create($payload);
                $created++;
            }
        }

        return response()->json([
            'message' => 'Availability saved for '.count($data['dates']).' date(s)',
            'created' => $created,
            'updated' => $updated,
        ], 200);
    }

    public function update(Request $request, AvailableSchedule $availableSchedule)
    {
        $data = $request->validate([
            'day_of_week' => 'sometimes|nullable|integer|min:0|max:6',
            'available_on_date' => 'sometimes|required|date_format:Y-m-d',
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i',
            'meeting_duration_minutes' => 'nullable|integer|min:15|max:180',
            'timezone' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $data = $this->syncDayOfWeek($data);

        if (array_key_exists('start_time', $data) && array_key_exists('end_time', $data)) {
            if ($data['end_time'] <= $data['start_time']) {
                return response()->json(['message' => 'end_time must be after start_time'], 422);
            }
        }

        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);
        $actorInstitutionId = WebinarTenant::actorInstitutionId($actor);
        $scheduleInstitutionId = WebinarTenant::fromSchedule($availableSchedule);
        if ($actorInstitutionId !== $scheduleInstitutionId && !PlatformInstitutionHelper::isMainPlatformAdmin($actor)) {
            return response()->json(['message' => 'Not allowed to update this schedule.'], 403);
        }
        if (PlatformInstitutionHelper::isMainPlatformAdmin($actor) && $scheduleInstitutionId) {
            return response()->json(['message' => 'Hub operators manage hub schedules only.'], 403);
        }

        $availableSchedule->fill($data);
        $availableSchedule->save();

        return response()->json([
            'message' => 'Available schedule updated',
            'slot' => $availableSchedule,
        ], 200);
    }

    public function destroy(Request $request, AvailableSchedule $availableSchedule)
    {
        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);
        $actorInstitutionId = WebinarTenant::actorInstitutionId($actor);
        $scheduleInstitutionId = WebinarTenant::fromSchedule($availableSchedule);
        if ($actorInstitutionId !== $scheduleInstitutionId && !PlatformInstitutionHelper::isMainPlatformAdmin($actor)) {
            return response()->json(['message' => 'Not allowed to delete this schedule.'], 403);
        }
        if (PlatformInstitutionHelper::isMainPlatformAdmin($actor) && $scheduleInstitutionId) {
            return response()->json(['message' => 'Hub operators manage hub schedules only.'], 403);
        }

        $availableSchedule->delete();

        return response()->json([
            'message' => 'Available schedule deleted',
        ], 200);
    }
}
