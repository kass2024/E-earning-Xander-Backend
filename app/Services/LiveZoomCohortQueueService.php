<?php

namespace App\Services;

use App\Enums\MeetingProvider;
use App\Models\LiveZoomCohort;
use App\Models\LiveZoomCohortQueueEntry;
use App\Support\LiveZoomCohortHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LiveZoomCohortQueueService
{
    /** @var array<int, string> */
    protected array $activeStatuses = ['waiting', 'admitted', 'in_meeting'];

    public function isQueueEnabled(): bool
    {
        return Schema::hasTable('livezoom_cohort_queue_entries')
            && Schema::hasColumn('livezoom_cohort', 'session_status');
    }

    /**
     * @return array<string, mixed>
     */
    public function startSession(LiveZoomCohort $cohort): array
    {
        $this->assertQueueEnabled();

        if (trim((string) ($cohort->zoom_link ?? '')) === '') {
            throw new \RuntimeException('Add a Zoom link before starting this cohort session.');
        }

        $cohort->session_status = 'live';
        $cohort->session_started_at = now();
        $cohort->session_ended_at = null;
        $cohort->current_queue_entry_id = null;
        $cohort->save();

        return $this->sessionPayload($cohort);
    }

    /**
     * @return array<string, mixed>
     */
    public function endSession(LiveZoomCohort $cohort): array
    {
        $this->assertQueueEnabled();

        DB::transaction(function () use ($cohort) {
            LiveZoomCohortQueueEntry::query()
                ->where('livezoom_cohort_id', $cohort->id)
                ->where('status', 'waiting')
                ->update(['status' => 'skipped', 'released_at' => now()]);

            LiveZoomCohortQueueEntry::query()
                ->where('livezoom_cohort_id', $cohort->id)
                ->whereIn('status', ['admitted', 'in_meeting'])
                ->update(['status' => 'left', 'released_at' => now()]);

            $cohort->session_status = 'ended';
            $cohort->session_ended_at = now();
            $cohort->current_queue_entry_id = null;
            $cohort->save();
        });

        $this->clearHostInMeeting($cohort);

        return $this->sessionPayload($cohort->fresh());
    }

    protected function hostInMeetingCacheKey(LiveZoomCohort|int $cohort): string
    {
        $id = $cohort instanceof LiveZoomCohort ? $cohort->id : $cohort;

        return 'live_cohort_host_in_meeting_' . $id;
    }

    public function isHostInMeeting(LiveZoomCohort $cohort): bool
    {
        return (bool) Cache::get($this->hostInMeetingCacheKey($cohort), false);
    }

    /**
     * Host connected in embedded SDK — cache flag used for joiner can_join gate.
     */
    public function resolveHostInMeeting(LiveZoomCohort $cohort): bool
    {
        return $this->isHostInMeeting($cohort);
    }

    public function clearHostInMeeting(LiveZoomCohort $cohort): void
    {
        Cache::forget($this->hostInMeetingCacheKey($cohort));
    }

    /**
     * @return array<string, mixed>
     */
    public function markHostInMeeting(LiveZoomCohort $cohort): array
    {
        $this->assertQueueEnabled();

        Cache::put($this->hostInMeetingCacheKey($cohort), true, now()->addHours(12));

        $cohort = $cohort->fresh();
        $autoAdmitted = null;

        if (($cohort->session_status ?? 'idle') === 'live' && !$this->currentParticipant($cohort)) {
            $next = $this->admitNextWaiting($cohort);
            if ($next) {
                $autoAdmitted = $this->entryPayload($cohort->fresh(), $next);
            }
        }

        return [
            'host_in_meeting' => true,
            'auto_admitted' => $autoAdmitted,
            'session' => $this->sessionPayload($cohort->fresh()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function joinQueue(
        LiveZoomCohort $cohort,
        ?int $studentId,
        string $displayName,
        ?string $guestToken = null,
        ?string $guestEmail = null,
        ?string $guestPhone = null
    ): array {
        $this->assertQueueEnabled();

        $isLive = ($cohort->session_status ?? 'idle') === 'live';

        $displayName = trim($displayName);
        if ($displayName === '') {
            throw new \RuntimeException('Please enter your name to join the queue.');
        }

        if ($studentId === null) {
            $guestToken = $guestToken ?: (string) Str::uuid();
            $guestEmail = trim((string) $guestEmail);
            $guestPhone = trim((string) $guestPhone);

            if ($guestEmail === '') {
                throw new \RuntimeException('Please enter your email to join the queue.');
            }

            if (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Please enter a valid email address.');
            }

            if ($guestPhone === '') {
                throw new \RuntimeException('Please enter your phone number to join the queue.');
            }
        }

        $existing = $this->findActiveEntry($cohort, $studentId, $guestToken);
        if ($existing) {
            if ($studentId === null) {
                $existing->display_name = $displayName;
                $existing->guest_email = $guestEmail;
                $existing->guest_phone = $guestPhone;
                $existing->save();
            }

            return $this->entryPayload($cohort, $existing->fresh());
        }

        return DB::transaction(function () use ($cohort, $studentId, $displayName, $guestToken, $guestEmail, $guestPhone, $isLive) {
            $hasActiveParticipant = $isLive && LiveZoomCohortQueueEntry::query()
                ->where('livezoom_cohort_id', $cohort->id)
                ->whereIn('status', ['admitted', 'in_meeting'])
                ->exists();

            // Waiting-room model: first guest auto-enters when the session is live and
            // nobody is already admitted / in-meeting. Later joiners get a queue number.
            $shouldAutoAdmit = $isLive && !$hasActiveParticipant;

            $entry = LiveZoomCohortQueueEntry::create([
                'livezoom_cohort_id' => $cohort->id,
                'student_id' => $studentId,
                'guest_token' => $studentId === null ? $guestToken : null,
                'guest_email' => $studentId === null ? $guestEmail : null,
                'guest_phone' => $studentId === null ? $guestPhone : null,
                'display_name' => $displayName,
                'status' => $shouldAutoAdmit ? 'admitted' : 'waiting',
                'queue_position' => $shouldAutoAdmit
                    ? 0
                    : $this->nextWaitingPosition($cohort),
                'joined_at' => now(),
                'admitted_at' => $shouldAutoAdmit ? now() : null,
            ]);

            if ($shouldAutoAdmit) {
                $cohort->current_queue_entry_id = $entry->id;
                $cohort->save();
            }

            return $this->entryPayload($cohort->fresh(), $entry->fresh());
        });
    }

    /**
     * Backwards-compatible learner join.
     *
     * @return array<string, mixed>
     */
    public function joinQueueAsStudent(LiveZoomCohort $cohort, int $studentId, string $displayName): array
    {
        return $this->joinQueue($cohort, $studentId, $displayName, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function queueStatus(LiveZoomCohort $cohort, ?int $studentId = null, ?string $guestToken = null): array
    {
        $this->assertQueueEnabled();

        $entry = null;
        if ($studentId || $guestToken) {
            $entry = $this->findActiveEntry($cohort, $studentId, $guestToken);
        }

        $payload = $this->sessionPayload($cohort);
        $payload['my_entry'] = $entry ? $this->entryPayload($cohort, $entry) : null;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function leaveQueue(LiveZoomCohort $cohort, ?int $studentId = null, ?string $guestToken = null): array
    {
        $this->assertQueueEnabled();

        $entry = $this->findActiveEntry($cohort, $studentId, $guestToken);

        if (!$entry) {
            return ['message' => 'You are not in the queue.', 'session' => $this->sessionPayload($cohort)];
        }

        DB::transaction(function () use ($cohort, $entry) {
            $wasActive = in_array($entry->status, ['admitted', 'in_meeting'], true);
            $entry->status = 'cancelled';
            $entry->released_at = now();
            $entry->save();

            if ($wasActive) {
                $this->refreshCurrentQueuePointer($cohort);
            } else {
                $this->recalculateWaitingPositions($cohort);
            }
        });

        return [
            'message' => 'Removed from queue.',
            'session' => $this->sessionPayload($cohort->fresh()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function markJoined(LiveZoomCohort $cohort, ?int $studentId = null, ?string $guestToken = null): array
    {
        $entry = $this->findActiveEntry($cohort, $studentId, $guestToken);
        if (!$entry || $entry->status !== 'admitted') {
            throw new \RuntimeException('You are not currently admitted to join.');
        }

        $entry->status = 'in_meeting';
        if (!$entry->attended_at) {
            $entry->attended_at = now();
        }
        $entry->save();

        return $this->entryPayload($cohort, $entry);
    }

    /**
     * @return array<string, mixed>
     */
    public function releaseParticipantTurn(LiveZoomCohort $cohort, ?int $studentId = null, ?string $guestToken = null): array
    {
        $entry = $this->findActiveEntry($cohort, $studentId, $guestToken);
        if (!$entry || !in_array($entry->status, ['admitted', 'in_meeting'], true)) {
            throw new \RuntimeException('You are not the active participant.');
        }

        return DB::transaction(function () use ($cohort, $entry) {
            $entry->status = 'left';
            $entry->released_at = now();
            $entry->save();

            // Waiting-room model: leaving does not auto-admit the next person.
            $this->refreshCurrentQueuePointer($cohort);

            return [
                'released' => $this->entryPayload($cohort, $entry),
                'admitted' => null,
                'session' => $this->sessionPayload($cohort->fresh()),
            ];
        });
    }

    protected function findActiveEntry(
        LiveZoomCohort $cohort,
        ?int $studentId,
        ?string $guestToken
    ): ?LiveZoomCohortQueueEntry {
        $query = LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->whereIn('status', $this->activeStatuses);

        if ($studentId) {
            return $query->where('student_id', $studentId)->first();
        }

        if ($guestToken) {
            return $query
                ->whereNull('student_id')
                ->where('guest_token', $guestToken)
                ->first();
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function releaseCurrent(LiveZoomCohort $cohort): ?array
    {
        $this->assertQueueEnabled();

        return DB::transaction(function () use ($cohort) {
            $current = $this->currentParticipant($cohort);
            if ($current) {
                $current->status = 'left';
                $current->released_at = now();
                $current->save();
            }

            $this->refreshCurrentQueuePointer($cohort);
            $next = $this->admitNextWaiting($cohort);

            return [
                'released' => $current ? $this->entryPayload($cohort, $current) : null,
                'admitted' => $next ? $this->entryPayload($cohort, $next) : null,
                'session' => $this->sessionPayload($cohort->fresh()),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function admitWaitingEntry(LiveZoomCohort $cohort, int $entryId): array
    {
        $this->assertQueueEnabled();

        return DB::transaction(function () use ($cohort, $entryId) {
            $entry = LiveZoomCohortQueueEntry::query()
                ->where('livezoom_cohort_id', $cohort->id)
                ->where('id', $entryId)
                ->where('status', 'waiting')
                ->lockForUpdate()
                ->first();

            if (!$entry) {
                throw new \RuntimeException('That participant is no longer waiting in the queue.');
            }

            $entry->status = 'admitted';
            $entry->queue_position = 0;
            $entry->admitted_at = now();
            $entry->save();

            if (!$cohort->current_queue_entry_id) {
                $cohort->current_queue_entry_id = $entry->id;
                $cohort->save();
            }

            $this->recalculateWaitingPositions($cohort);

            return [
                'message' => $entry->display_name . ' has been admitted.',
                'admitted' => $this->entryPayload($cohort, $entry->fresh()),
                'session' => $this->sessionPayload($cohort->fresh()),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function admitAllWaiting(LiveZoomCohort $cohort): array
    {
        $this->assertQueueEnabled();

        return DB::transaction(function () use ($cohort) {
            $waiting = LiveZoomCohortQueueEntry::query()
                ->where('livezoom_cohort_id', $cohort->id)
                ->where('status', 'waiting')
                ->orderBy('joined_at')
                ->lockForUpdate()
                ->get();

            if ($waiting->isEmpty()) {
                return [
                    'message' => 'No one is waiting in the queue.',
                    'admitted' => [],
                    'count' => 0,
                    'session' => $this->sessionPayload($cohort),
                ];
            }

            $admitted = [];
            $first = $waiting->first();

            foreach ($waiting as $entry) {
                $entry->status = 'admitted';
                $entry->queue_position = 0;
                $entry->admitted_at = now();
                $entry->save();
                $admitted[] = $this->entryPayload($cohort, $entry->fresh());
            }

            if (!$cohort->current_queue_entry_id && $first) {
                $cohort->current_queue_entry_id = $first->id;
                $cohort->save();
            }
            $this->recalculateWaitingPositions($cohort);

            return [
                'message' => count($admitted) . ' participant(s) admitted. They can join the meeting now.',
                'admitted' => $admitted,
                'count' => count($admitted),
                'session' => $this->sessionPayload($cohort->fresh()),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function admitNextIfAvailable(LiveZoomCohort $cohort): array
    {
        $this->assertQueueEnabled();

        return DB::transaction(function () use ($cohort) {
            $next = $this->admitNextWaiting($cohort);

            return [
                'message' => $next
                    ? $next->display_name . ' has been admitted.'
                    : 'No one is waiting in the queue.',
                'admitted' => $next ? $this->entryPayload($cohort, $next) : null,
                'session' => $this->sessionPayload($cohort->fresh()),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function adminQueue(LiveZoomCohort $cohort): array
    {
        $this->assertQueueEnabled();

        $waiting = LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->where('status', 'waiting')
            ->orderBy('joined_at')
            ->get()
            ->map(fn (LiveZoomCohortQueueEntry $entry) => $this->entryPayload($cohort, $entry));

        $inSession = LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->whereIn('status', ['admitted', 'in_meeting'])
            ->orderBy('admitted_at')
            ->get();

        $current = $this->currentParticipant($cohort);
        $admittedWaiting = $inSession
            ->filter(fn (LiveZoomCohortQueueEntry $entry) => $entry->status === 'admitted')
            ->values()
            ->map(fn (LiveZoomCohortQueueEntry $entry) => $this->entryPayload($cohort, $entry));

        $inSessionPayload = $inSession
            ->map(fn (LiveZoomCohortQueueEntry $entry) => $this->entryPayload($cohort, $entry))
            ->values()
            ->all();

        return [
            'session' => $this->sessionPayload($cohort),
            'current' => $current ? $this->entryPayload($cohort, $current) : null,
            'in_session' => $inSessionPayload,
            'in_session_count' => count($inSessionPayload),
            'waiting' => $waiting,
            'waiting_count' => $waiting->count(),
            'admitted_ready' => $admittedWaiting->all(),
            'admitted_ready_count' => $admittedWaiting->count(),
        ];
    }

    protected function admitNextWaiting(LiveZoomCohort $cohort): ?LiveZoomCohortQueueEntry
    {
        $next = LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->where('status', 'waiting')
            ->orderBy('joined_at')
            ->lockForUpdate()
            ->first();

        if (!$next) {
            $this->recalculateWaitingPositions($cohort);

            return null;
        }

        $next->status = 'admitted';
        $next->queue_position = 0;
        $next->admitted_at = now();
        $next->save();

        if (!$cohort->current_queue_entry_id) {
            $cohort->current_queue_entry_id = $next->id;
            $cohort->save();
        }

        $this->recalculateWaitingPositions($cohort);

        return $next;
    }

    /**
     * Keep current_queue_entry_id pointing at an active admitted/in_meeting row, or null.
     */
    protected function refreshCurrentQueuePointer(LiveZoomCohort $cohort): void
    {
        $active = LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->whereIn('status', ['admitted', 'in_meeting'])
            ->orderBy('admitted_at')
            ->first();

        $cohort->current_queue_entry_id = $active?->id;
        $cohort->save();
    }

    protected function recalculateWaitingPositions(LiveZoomCohort $cohort): void
    {
        $waiting = LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->where('status', 'waiting')
            ->orderBy('joined_at')
            ->get();

        $position = 1;
        foreach ($waiting as $entry) {
            if ((int) $entry->queue_position !== $position) {
                $entry->queue_position = $position;
                $entry->save();
            }
            $position++;
        }
    }

    protected function nextWaitingPosition(LiveZoomCohort $cohort): int
    {
        return (int) LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->where('status', 'waiting')
            ->count() + 1;
    }

    protected function currentParticipant(LiveZoomCohort $cohort): ?LiveZoomCohortQueueEntry
    {
        if ($cohort->current_queue_entry_id) {
            $entry = LiveZoomCohortQueueEntry::query()->find($cohort->current_queue_entry_id);
            if ($entry && in_array($entry->status, ['admitted', 'in_meeting'], true)) {
                return $entry;
            }
        }

        return LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->whereIn('status', ['admitted', 'in_meeting'])
            ->orderBy('admitted_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function publicQueueSnapshot(LiveZoomCohort $cohort): array
    {
        $this->assertQueueEnabled();

        $waiting = LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->where('status', 'waiting')
            ->orderBy('joined_at')
            ->get()
            ->map(fn (LiveZoomCohortQueueEntry $entry) => [
                'id' => $entry->id,
                'display_name' => $entry->display_name,
                'queue_position' => (int) $entry->queue_position,
                'joined_at' => $entry->joined_at?->toIso8601String(),
            ]);

        $current = $this->currentParticipant($cohort);

        return [
            'session_status' => $cohort->session_status ?? 'idle',
            'is_live' => ($cohort->session_status ?? 'idle') === 'live',
            'waiting_count' => $waiting->count(),
            'waiting' => $waiting->values()->all(),
            'current_participant' => $current?->display_name,
            'has_active_participant' => $current !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attendanceList(LiveZoomCohort $cohort): array
    {
        $this->assertQueueEnabled();

        $entries = LiveZoomCohortQueueEntry::query()
            ->with('student:id,first_name,last_name,email,phone')
            ->where('livezoom_cohort_id', $cohort->id)
            ->whereNotIn('status', ['cancelled', 'skipped'])
            ->orderBy('joined_at')
            ->get()
            ->map(fn (LiveZoomCohortQueueEntry $entry) => $this->attendancePayload($entry));

        return [
            'cohort_id' => $cohort->id,
            'cohort_title' => $cohort->notes ?: 'Live Zoom Cohort',
            'session_status' => $cohort->session_status ?? 'idle',
            'session_started_at' => $cohort->session_started_at?->toIso8601String(),
            'session_ended_at' => $cohort->session_ended_at?->toIso8601String(),
            'total' => $entries->count(),
            'attended_count' => $entries->where('attended', true)->count(),
            'entries' => $entries->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function attendancePayload(LiveZoomCohortQueueEntry $entry): array
    {
        $isGuest = $entry->student_id === null;
        $email = $isGuest ? $entry->guest_email : ($entry->student?->email ?? null);
        $phone = $isGuest ? $entry->guest_phone : ($entry->student?->phone ?? null);
        $attended = $entry->attended_at !== null
            || in_array($entry->status, ['in_meeting', 'left'], true);

        return [
            'id' => $entry->id,
            'display_name' => $entry->display_name,
            'email' => $email,
            'phone' => $phone,
            'is_guest' => $isGuest,
            'student_id' => $entry->student_id,
            'status' => $entry->status,
            'attended' => $attended,
            'joined_at' => $entry->joined_at?->toIso8601String(),
            'admitted_at' => $entry->admitted_at?->toIso8601String(),
            'attended_at' => $entry->attended_at?->toIso8601String(),
            'released_at' => $entry->released_at?->toIso8601String(),
        ];
    }

    /**
     * Daily rooms do not need the Zoom-style "host already in SDK" gate —
     * admitted guests can enter as soon as the session is live.
     */
    protected function cohortUsesDaily(LiveZoomCohort $cohort): bool
    {
        if (Schema::hasColumn('livezoom_cohort', 'meeting_provider')) {
            $stored = MeetingProvider::tryFromString($cohort->meeting_provider ?? null);
            if ($stored === MeetingProvider::Daily) {
                return true;
            }
            if ($stored === MeetingProvider::Zoom) {
                return false;
            }
        }

        return trim((string) ($cohort->daily_room_name ?? '')) !== ''
            || str_contains(strtolower((string) ($cohort->zoom_link ?? '')), 'daily.co');
    }

    /**
     * @return array<string, mixed>
     */
    protected function entryPayload(LiveZoomCohort $cohort, LiveZoomCohortQueueEntry $entry): array
    {
        $ahead = max(0, (int) $entry->queue_position - 1);
        $sessionLive = ($cohort->session_status ?? 'idle') === 'live';
        $hostInMeeting = $this->resolveHostInMeeting($cohort);
        $usesDaily = $this->cohortUsesDaily($cohort);
        // Daily: admitted + live is enough (avoid multi-second wait for host-in-meeting flag).
        $canJoin = in_array($entry->status, ['admitted', 'in_meeting'], true)
            && $sessionLive
            && ($usesDaily || $hostInMeeting);

        return [
            'id' => $entry->id,
            'student_id' => $entry->student_id,
            'guest_token' => $entry->guest_token,
            'guest_email' => $entry->guest_email,
            'guest_phone' => $entry->guest_phone,
            'is_guest' => $entry->student_id === null,
            'display_name' => $entry->display_name,
            'status' => $entry->status,
            'queue_position' => (int) $entry->queue_position,
            'ahead_count' => $ahead,
            'is_waiting' => $entry->status === 'waiting',
            'is_admitted' => in_array($entry->status, ['admitted', 'in_meeting'], true),
            'can_join' => $canJoin,
            'in_app_room_path' => $canJoin ? LiveZoomCohortHelper::participantRoomPath($cohort) : null,
            'joined_at' => $entry->joined_at?->toIso8601String(),
            'admitted_at' => $entry->admitted_at?->toIso8601String(),
            'message' => $this->statusMessage($entry, $cohort),
        ];
    }

    protected function statusMessage(LiveZoomCohortQueueEntry $entry, ?LiveZoomCohort $cohort = null): string
    {
        $sessionLive = $cohort ? (($cohort->session_status ?? 'idle') === 'live') : true;
        $hostInMeeting = $cohort ? $this->resolveHostInMeeting($cohort) : true;

        return match ($entry->status) {
            'waiting' => !$sessionLive
                ? 'You are in the waiting room (#' . max(1, (int) $entry->queue_position) . '). The host can admit you at any time.'
                : ($entry->queue_position <= 1
                    ? 'You are #1 in the waiting room. The host will admit you shortly, or you may enter when admitted.'
                    : 'You are #' . $entry->queue_position . ' in the waiting room. Please stay on this page — the host will admit you.'),
            'admitted' => !$sessionLive
                ? 'The host admitted you. The session will open shortly — stay on this page.'
                : (!$hostInMeeting && !($cohort && $this->cohortUsesDaily($cohort))
                    ? 'You are admitted. Waiting for the host to connect — stay on this page.'
                    : 'You have been admitted. Opening the meeting…'),
            'in_meeting' => 'You are in the session.',
            default => 'Queue status updated.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function sessionPayload(LiveZoomCohort $cohort): array
    {
        $waitingCount = LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->where('status', 'waiting')
            ->count();

        $inSessionCount = LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->whereIn('status', ['admitted', 'in_meeting'])
            ->count();

        $current = $this->currentParticipant($cohort);

        return [
            'cohort_id' => $cohort->id,
            'session_status' => $cohort->session_status ?? 'idle',
            'session_started_at' => $cohort->session_started_at?->toIso8601String(),
            'session_ended_at' => $cohort->session_ended_at?->toIso8601String(),
            'is_live' => ($cohort->session_status ?? 'idle') === 'live',
            'waiting_count' => $waitingCount,
            'in_session_count' => $inSessionCount,
            'has_active_participant' => $current !== null,
            'current_participant' => $current?->display_name,
            'host_in_meeting' => $this->isHostInMeeting($cohort),
            'queue_enabled' => true,
            'waiting_room_mode' => 'multi_admit',
        ];
    }

    protected function assertQueueEnabled(): void
    {
        if (!$this->isQueueEnabled()) {
            throw new \RuntimeException('Live cohort queue is not available. Run database migrations.');
        }
    }
}
