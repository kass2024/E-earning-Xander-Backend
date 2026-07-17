<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Meetings\DailyPermissionPolicy;
use App\Services\Meetings\MeetingModerationService;
use App\Support\PlatformInstitutionHelper;
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

        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);
        $result = $this->moderation->raiseHand(
            $this->normalizeMeetingKey($data['meeting_key']),
            $data['daily_session_id'],
            (string) ($data['participant_name'] ?? ($actor?->name ?? 'Participant')),
            $actor?->id,
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

        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);
        $result = $this->moderation->cancelHand(
            $this->normalizeMeetingKey($data['meeting_key']),
            $data['daily_session_id'],
            $actor?->id,
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

        // App auth is email-based (user_email), not Sanctum — resolve actor the same way as Zoom routes.
        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);
        if (!$this->moderation->actorCanModerate($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'hands' => $this->moderation->pendingHands($this->normalizeMeetingKey($data['meeting_key'])),
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

        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);
        if (!$actor || !$this->moderation->actorCanModerate($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $result = $this->moderation->approveSpeaking(
            $this->normalizeMeetingKey($data['meeting_key']),
            $data['daily_session_id'],
            $actor,
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

        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);
        if (!$actor || !$this->moderation->actorCanModerate($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $action = (string) ($data['action'] ?? 'revoke');
        if ($action === 'stop') {
            $action = 'revoke';
        }

        $result = $this->moderation->revokeSpeaking(
            $this->normalizeMeetingKey($data['meeting_key']),
            $data['daily_session_id'],
            $actor,
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

        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);
        if (!$actor || !$this->moderation->actorCanModerate($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $result = $this->moderation->denyHand(
            $this->normalizeMeetingKey($data['meeting_key']),
            (int) $data['hand_raise_id'],
            $actor,
        );
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

        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);
        $meetingKey = $this->normalizeMeetingKey($data['meeting_key']);
        $this->moderation->clearSession($meetingKey, $data['daily_session_id']);
        $this->moderation->audit(
            $meetingKey,
            $actor?->id,
            $actor?->id,
            $data['daily_session_id'],
            'participant_left',
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Prefer Daily room name over full join URL so host/guest share one meeting_key.
     */
    protected function normalizeMeetingKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return $key;
        }

        if (preg_match('#daily\.co/([^/?#]+)#i', $key, $m)) {
            return rawurldecode($m[1]);
        }

        if (str_contains($key, '/') || str_contains($key, '?')) {
            $path = parse_url($key, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $base = trim(basename($path));
                if ($base !== '') {
                    return $base;
                }
            }
        }

        return $key;
    }
}
