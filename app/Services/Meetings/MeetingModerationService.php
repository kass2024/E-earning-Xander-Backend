<?php

namespace App\Services\Meetings;

use App\Models\MeetingHandRaise;
use App\Models\MeetingModerationEvent;
use App\Models\MeetingSpeakingGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MeetingModerationService
{
    public function __construct(
        private readonly DailyPermissionPolicy $permissions,
    ) {}

    public function tablesReady(): bool
    {
        return Schema::hasTable('meeting_hand_raises')
            && Schema::hasTable('meeting_speaking_grants')
            && Schema::hasTable('meeting_moderation_events');
    }

    public function actorCanModerate(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));

        return in_array($role, ['admin', 'staff', 'instructor', 'partner_company'], true);
    }

    /**
     * @return array{ok: bool, request?: MeetingHandRaise, message?: string}
     */
    public function raiseHand(
        string $meetingKey,
        string $sessionId,
        string $participantName,
        ?int $userId,
        string $meetingMode = DailyPermissionPolicy::MODE_MEETING,
    ): array {
        if (!$this->tablesReady()) {
            return ['ok' => false, 'message' => 'Moderation tables are not migrated yet.'];
        }

        $meetingKey = trim($meetingKey);
        $sessionId = trim($sessionId);
        if ($meetingKey === '' || $sessionId === '') {
            return ['ok' => false, 'message' => 'Meeting and session are required.'];
        }

        $existing = MeetingHandRaise::query()
            ->where('meeting_key', $meetingKey)
            ->where('daily_session_id', $sessionId)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return ['ok' => true, 'request' => $existing];
        }

        $request = MeetingHandRaise::query()->create([
            'meeting_key' => $meetingKey,
            'meeting_mode' => $meetingMode === DailyPermissionPolicy::MODE_WEBINAR
                ? DailyPermissionPolicy::MODE_WEBINAR
                : DailyPermissionPolicy::MODE_MEETING,
            'user_id' => $userId,
            'daily_session_id' => $sessionId,
            'participant_name' => mb_substr(trim($participantName) ?: 'Participant', 0, 191),
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->audit($meetingKey, $userId, $userId, $sessionId, 'hand_raised');

        return ['ok' => true, 'request' => $request];
    }

    /**
     * @return array{ok: bool, request?: MeetingHandRaise, message?: string}
     */
    public function cancelHand(string $meetingKey, string $sessionId, ?int $userId): array
    {
        if (!$this->tablesReady()) {
            return ['ok' => false, 'message' => 'Moderation tables are not migrated yet.'];
        }

        $request = MeetingHandRaise::query()
            ->where('meeting_key', $meetingKey)
            ->where('daily_session_id', $sessionId)
            ->where('status', 'pending')
            ->first();

        if (!$request) {
            return ['ok' => true];
        }

        $request->status = 'cancelled';
        $request->reviewed_at = now();
        $request->save();

        $this->audit($meetingKey, $userId, $request->user_id, $sessionId, 'hand_cancelled');

        return ['ok' => true, 'request' => $request];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingHands(string $meetingKey): array
    {
        if (!$this->tablesReady()) {
            return [];
        }

        return MeetingHandRaise::query()
            ->where('meeting_key', $meetingKey)
            ->where('status', 'pending')
            ->orderBy('requested_at')
            ->get()
            ->map(fn (MeetingHandRaise $row) => [
                'id' => $row->id,
                'meeting_key' => $row->meeting_key,
                'meeting_mode' => $row->meeting_mode,
                'user_id' => $row->user_id,
                'daily_session_id' => $row->daily_session_id,
                'participant_name' => $row->participant_name,
                'status' => $row->status,
                'requested_at' => $row->requested_at?->toIso8601String(),
                'waiting_seconds' => $row->requested_at ? max(0, now()->diffInSeconds($row->requested_at)) : 0,
            ])
            ->all();
    }

    /**
     * Approve speaking. Host client must apply Daily updateParticipant with returned permissions.
     *
     * @return array{ok: bool, grant?: MeetingSpeakingGrant, daily_permissions?: array<string, mixed>, message?: string}
     */
    public function approveSpeaking(
        string $meetingKey,
        string $sessionId,
        User $actor,
        bool $audio = true,
        bool $video = false,
        bool $screenShare = false,
        bool $inviteToStage = false,
        ?int $durationSeconds = null,
        ?int $targetUserId = null,
        ?int $handRaiseId = null,
    ): array {
        if (!$this->tablesReady()) {
            return ['ok' => false, 'message' => 'Moderation tables are not migrated yet.'];
        }
        if (!$this->actorCanModerate($actor)) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }

        return DB::transaction(function () use (
            $meetingKey,
            $sessionId,
            $actor,
            $audio,
            $video,
            $screenShare,
            $inviteToStage,
            $durationSeconds,
            $targetUserId,
            $handRaiseId,
        ) {
            $hand = null;
            if ($handRaiseId) {
                $hand = MeetingHandRaise::query()
                    ->where('id', $handRaiseId)
                    ->where('meeting_key', $meetingKey)
                    ->lockForUpdate()
                    ->first();
            } else {
                $hand = MeetingHandRaise::query()
                    ->where('meeting_key', $meetingKey)
                    ->where('daily_session_id', $sessionId)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();
            }

            if ($hand && $hand->status === 'pending') {
                $hand->status = 'approved';
                $hand->reviewed_at = now();
                $hand->reviewed_by = $actor->id;
                if ($durationSeconds) {
                    $hand->speaking_duration_seconds = $durationSeconds;
                }
                $hand->save();
            }

            $expiresAt = $durationSeconds && $durationSeconds > 0
                ? now()->addSeconds($durationSeconds)
                : null;

            $grant = MeetingSpeakingGrant::query()->updateOrCreate(
                [
                    'meeting_key' => $meetingKey,
                    'daily_session_id' => $sessionId,
                ],
                [
                    'user_id' => $targetUserId ?? $hand?->user_id,
                    'speaking_state' => 'approved',
                    'audio_granted' => $audio,
                    'video_granted' => $video,
                    'screen_share_granted' => $screenShare,
                    'on_stage' => $inviteToStage,
                    'granted_by' => $actor->id,
                    'granted_at' => now(),
                    'expires_at' => $expiresAt,
                    'revoked_at' => null,
                ],
            );

            $this->audit(
                $meetingKey,
                $actor->id,
                $grant->user_id,
                $sessionId,
                'speaking_approved',
                [
                    'audio' => $audio,
                    'video' => $video,
                    'screen_share' => $screenShare,
                    'on_stage' => $inviteToStage,
                    'expires_at' => $expiresAt?->toIso8601String(),
                ],
            );

            Log::info('meeting.speaking_approved', [
                'meeting_key' => $meetingKey,
                'actor_user_id' => $actor->id,
                'target_session_id' => $sessionId,
                // never log tokens
            ]);

            return [
                'ok' => true,
                'grant' => $grant,
                'daily_permissions' => $this->permissions->speakingGrantUpdate($audio, $video, $screenShare),
            ];
        });
    }

    /**
     * @return array{ok: bool, grant?: MeetingSpeakingGrant, daily_permissions?: array<string, mixed>, message?: string}
     */
    public function revokeSpeaking(
        string $meetingKey,
        string $sessionId,
        User $actor,
        string $action = 'revoke',
    ): array {
        if (!$this->tablesReady()) {
            return ['ok' => false, 'message' => 'Moderation tables are not migrated yet.'];
        }
        if (!$this->actorCanModerate($actor)) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }

        $grant = MeetingSpeakingGrant::query()
            ->where('meeting_key', $meetingKey)
            ->where('daily_session_id', $sessionId)
            ->first();

        if ($grant) {
            $grant->speaking_state = $action === 'mute' ? 'listening' : 'revoked';
            if ($action !== 'mute') {
                $grant->audio_granted = false;
                $grant->video_granted = false;
                $grant->screen_share_granted = false;
                $grant->on_stage = false;
                $grant->revoked_at = now();
                $grant->expires_at = null;
            } else {
                // Mute only — keep grant so they can unmute again if still approved.
                $grant->speaking_state = 'approved';
            }
            $grant->save();
        }

        $this->audit(
            $meetingKey,
            $actor->id,
            $grant?->user_id,
            $sessionId,
            $action === 'mute' ? 'participant_muted' : 'speaking_revoked',
        );

        return [
            'ok' => true,
            'grant' => $grant,
            'daily_permissions' => $action === 'mute'
                ? null
                : $this->permissions->revokePublishUpdate(),
            'set_audio' => false,
            'set_video' => $action === 'mute' ? null : false,
        ];
    }

    /**
     * @return array{ok: bool, request?: MeetingHandRaise, message?: string}
     */
    public function denyHand(string $meetingKey, int $handRaiseId, User $actor): array
    {
        if (!$this->tablesReady()) {
            return ['ok' => false, 'message' => 'Moderation tables are not migrated yet.'];
        }
        if (!$this->actorCanModerate($actor)) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }

        $hand = MeetingHandRaise::query()
            ->where('id', $handRaiseId)
            ->where('meeting_key', $meetingKey)
            ->where('status', 'pending')
            ->first();

        if (!$hand) {
            return ['ok' => false, 'message' => 'Hand raise not found.'];
        }

        $hand->status = 'denied';
        $hand->reviewed_at = now();
        $hand->reviewed_by = $actor->id;
        $hand->save();

        $this->audit($meetingKey, $actor->id, $hand->user_id, $hand->daily_session_id, 'hand_denied');

        return ['ok' => true, 'request' => $hand];
    }

    public function clearSession(string $meetingKey, string $sessionId): void
    {
        if (!$this->tablesReady()) {
            return;
        }

        MeetingHandRaise::query()
            ->where('meeting_key', $meetingKey)
            ->where('daily_session_id', $sessionId)
            ->where('status', 'pending')
            ->update([
                'status' => 'expired',
                'reviewed_at' => now(),
            ]);

        MeetingSpeakingGrant::query()
            ->where('meeting_key', $meetingKey)
            ->where('daily_session_id', $sessionId)
            ->update([
                'speaking_state' => 'listening',
                'audio_granted' => false,
                'video_granted' => false,
                'screen_share_granted' => false,
                'on_stage' => false,
                'revoked_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function audit(
        string $meetingKey,
        ?int $actorUserId,
        ?int $targetUserId,
        ?string $targetSessionId,
        string $action,
        array $meta = [],
    ): void {
        if (!Schema::hasTable('meeting_moderation_events')) {
            return;
        }

        MeetingModerationEvent::query()->create([
            'meeting_key' => $meetingKey,
            'actor_user_id' => $actorUserId,
            'target_user_id' => $targetUserId,
            'target_session_id' => $targetSessionId,
            'action' => $action,
            'meta' => $meta ?: null,
        ]);
    }
}
