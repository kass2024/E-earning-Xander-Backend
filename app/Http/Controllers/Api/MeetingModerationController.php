<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Meetings\DailyPermissionPolicy;
use App\Services\Meetings\MeetingModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetingModerationController extends Controller
{
    public function __construct(
        private readonly MeetingModerationService $moderation,
        private readonly DailyPermissionPolicy $permissions,
    ) {}

    public function raiseHand(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'daily_session_id' => 'required|string|max:128',
            'participant_name' => 'nullable|string|max:191',
            'meeting_mode' => 'nullable|string|in:meeting,webinar',
        ]);

        $user = $request->user();
        $result = $this->moderation->raiseHand(
            $data['meeting_key'],
            $data['daily_session_id'],
            (string) ($data['participant_name'] ?? ($user?->name ?? 'Participant')),
            $user?->id,
            (string) ($data['meeting_mode'] ?? DailyPermissionPolicy::MODE_MEETING),
        );

        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to raise hand.'], 422);
        }

        return response()->json([
            'request' => $result['request'],
            'message' => 'Hand raised. Waiting for host approval.',
        ]);
    }

    public function cancelHand(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'daily_session_id' => 'required|string|max:128',
        ]);

        $result = $this->moderation->cancelHand(
            $data['meeting_key'],
            $data['daily_session_id'],
            $request->user()?->id,
        );

        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to cancel.'], 422);
        }

        return response()->json(['ok' => true, 'request' => $result['request'] ?? null]);
    }

    public function pendingHands(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
        ]);

        $user = $request->user();
        if (!$this->moderation->actorCanModerate($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'hands' => $this->moderation->pendingHands($data['meeting_key']),
        ]);
    }

    public function approveSpeaking(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'daily_session_id' => 'required|string|max:128',
            'hand_raise_id' => 'nullable|integer',
            'target_user_id' => 'nullable|integer',
            'audio' => 'nullable|boolean',
            'video' => 'nullable|boolean',
            'screen_share' => 'nullable|boolean',
            'invite_to_stage' => 'nullable|boolean',
            'duration_seconds' => 'nullable|integer|min:0|max:7200',
        ]);

        $user = $request->user();
        if (!$user || !$this->moderation->actorCanModerate($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $result = $this->moderation->approveSpeaking(
            $data['meeting_key'],
            $data['daily_session_id'],
            $user,
            (bool) ($data['audio'] ?? true),
            (bool) ($data['video'] ?? false),
            (bool) ($data['screen_share'] ?? false),
            (bool) ($data['invite_to_stage'] ?? false),
            isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null,
            isset($data['target_user_id']) ? (int) $data['target_user_id'] : null,
            isset($data['hand_raise_id']) ? (int) $data['hand_raise_id'] : null,
        );

        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to approve.'], 422);
        }

        return response()->json([
            'grant' => $result['grant'],
            'daily_permissions' => $result['daily_permissions'],
            'message' => 'Speaking permission granted. Apply Daily permissions from the host client.',
        ]);
    }

    public function revokeSpeaking(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'daily_session_id' => 'required|string|max:128',
            'action' => 'nullable|string|in:mute,revoke,stop',
        ]);

        $user = $request->user();
        if (!$user || !$this->moderation->actorCanModerate($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $action = (string) ($data['action'] ?? 'revoke');
        if ($action === 'stop') {
            $action = 'revoke';
        }

        $result = $this->moderation->revokeSpeaking(
            $data['meeting_key'],
            $data['daily_session_id'],
            $user,
            $action,
        );

        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to revoke.'], 422);
        }

        return response()->json([
            'grant' => $result['grant'] ?? null,
            'daily_permissions' => $result['daily_permissions'] ?? $this->permissions->revokePublishUpdate(),
            'set_audio' => false,
            'set_video' => $result['set_video'] ?? false,
            'message' => $action === 'mute' ? 'Participant muted.' : 'Speaking permission revoked.',
        ]);
    }

    public function denyHand(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'hand_raise_id' => 'required|integer',
        ]);

        $user = $request->user();
        if (!$user || !$this->moderation->actorCanModerate($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $result = $this->moderation->denyHand($data['meeting_key'], (int) $data['hand_raise_id'], $user);
        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to deny.'], 422);
        }

        return response()->json(['ok' => true, 'request' => $result['request']]);
    }

    public function leaveSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'daily_session_id' => 'required|string|max:128',
        ]);

        $this->moderation->clearSession($data['meeting_key'], $data['daily_session_id']);
        $this->moderation->audit(
            $data['meeting_key'],
            $request->user()?->id,
            $request->user()?->id,
            $data['daily_session_id'],
            'participant_left',
        );

        return response()->json(['ok' => true]);
    }
}
