<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Meetings\MeetingEngagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetingEngagementController extends Controller
{
    public function __construct(
        private readonly MeetingEngagementService $engagement,
    ) {}

    public function listQuestions(Request $request): JsonResponse
    {
        $data = $request->validate(['meeting_key' => 'required|string|max:128']);

        return response()->json([
            'questions' => $this->engagement->listQuestions($data['meeting_key']),
        ]);
    }

    public function askQuestion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'question' => 'required|string|max:2000',
            'author_name' => 'nullable|string|max:191',
            'daily_session_id' => 'nullable|string|max:128',
            'is_anonymous' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $result = $this->engagement->askQuestion(
            $data['meeting_key'],
            $data['question'],
            (string) ($data['author_name'] ?? ($user?->name ?? 'Participant')),
            $user?->id,
            $data['daily_session_id'] ?? null,
            (bool) ($data['is_anonymous'] ?? false),
        );

        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to ask.'], 422);
        }

        return response()->json(['question' => $result['question']]);
    }

    public function upvoteQuestion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'question_id' => 'required|integer',
        ]);

        $result = $this->engagement->upvoteQuestion($data['meeting_key'], (int) $data['question_id']);
        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to upvote.'], 422);
        }

        return response()->json(['question' => $result['question']]);
    }

    public function answerQuestion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'question_id' => 'required|integer',
            'answer' => 'nullable|string|max:4000',
            'status' => 'nullable|string|in:open,answered,dismissed,pinned',
        ]);

        $user = $request->user();
        if (!$user || !$this->engagement->actorCanModerate($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if (!empty($data['status']) && empty($data['answer'])) {
            $result = $this->engagement->setQuestionStatus(
                $data['meeting_key'],
                (int) $data['question_id'],
                $user,
                $data['status'],
            );
        } else {
            $result = $this->engagement->answerQuestion(
                $data['meeting_key'],
                (int) $data['question_id'],
                $user,
                (string) ($data['answer'] ?? ''),
            );
        }

        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to update.'], 422);
        }

        return response()->json(['question' => $result['question']]);
    }

    public function listPolls(Request $request): JsonResponse
    {
        $data = $request->validate(['meeting_key' => 'required|string|max:128']);
        $user = $request->user();
        $includeDrafts = $user && $this->engagement->actorCanModerate($user);

        return response()->json([
            'polls' => $this->engagement->listPolls($data['meeting_key'], $includeDrafts),
        ]);
    }

    public function createPoll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'question' => 'required|string|max:500',
            'options' => 'required|array|min:2|max:8',
            'options.*' => 'required|string|max:200',
            'allow_multiple' => 'nullable|boolean',
            'open_now' => 'nullable|boolean',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $result = $this->engagement->createPoll(
            $data['meeting_key'],
            $user,
            $data['question'],
            $data['options'],
            (bool) ($data['allow_multiple'] ?? false),
            (bool) ($data['open_now'] ?? true),
        );

        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to create poll.'], 422);
        }

        return response()->json(['poll' => $result['poll']]);
    }

    public function openPoll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'poll_id' => 'required|integer',
        ]);
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $result = $this->engagement->openPoll($data['meeting_key'], (int) $data['poll_id'], $user);
        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to open.'], 422);
        }

        return response()->json(['poll' => $result['poll']]);
    }

    public function closePoll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'poll_id' => 'required|integer',
        ]);
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $result = $this->engagement->closePoll($data['meeting_key'], (int) $data['poll_id'], $user);
        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to close.'], 422);
        }

        return response()->json(['poll' => $result['poll']]);
    }

    public function votePoll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'poll_id' => 'required|integer',
            'option_indexes' => 'required|array|min:1',
            'option_indexes.*' => 'integer|min:0|max:7',
            'daily_session_id' => 'nullable|string|max:128',
        ]);

        $result = $this->engagement->votePoll(
            $data['meeting_key'],
            (int) $data['poll_id'],
            $data['option_indexes'],
            $request->user()?->id,
            $data['daily_session_id'] ?? null,
        );

        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to vote.'], 422);
        }

        return response()->json(['poll' => $result['poll']]);
    }

    public function listBreakouts(Request $request): JsonResponse
    {
        $data = $request->validate(['meeting_key' => 'required|string|max:128']);

        return response()->json([
            'rooms' => $this->engagement->listBreakouts($data['meeting_key']),
        ]);
    }

    public function createBreakouts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'names' => 'nullable|array|max:12',
            'names.*' => 'string|max:191',
            'count' => 'nullable|integer|min:1|max:12',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $names = $data['names'] ?? [];
        if ($names === [] && !empty($data['count'])) {
            for ($i = 1; $i <= (int) $data['count']; $i++) {
                $names[] = 'Breakout ' . $i;
            }
        }

        $result = $this->engagement->createBreakouts($data['meeting_key'], $user, $names);
        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to create.'], 422);
        }

        return response()->json(['rooms' => $result['rooms']]);
    }

    public function assignBreakout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'room_id' => 'required|integer',
            'session_ids' => 'required|array',
            'session_ids.*' => 'string|max:128',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $result = $this->engagement->assignBreakout(
            $data['meeting_key'],
            (int) $data['room_id'],
            $data['session_ids'],
            $user,
        );

        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to assign.'], 422);
        }

        return response()->json(['room' => $result['room']]);
    }

    public function openBreakouts(Request $request): JsonResponse
    {
        $data = $request->validate(['meeting_key' => 'required|string|max:128']);
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $result = $this->engagement->openBreakouts($data['meeting_key'], $user);
        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to open.'], 422);
        }

        return response()->json(['rooms' => $result['rooms']]);
    }

    public function closeBreakouts(Request $request): JsonResponse
    {
        $data = $request->validate(['meeting_key' => 'required|string|max:128']);
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $result = $this->engagement->closeBreakouts($data['meeting_key'], $user);
        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to close.'], 422);
        }

        return response()->json(['rooms' => $result['rooms']]);
    }

    public function listStage(Request $request): JsonResponse
    {
        $data = $request->validate(['meeting_key' => 'required|string|max:128']);

        return response()->json([
            'stage' => $this->engagement->listStage($data['meeting_key']),
        ]);
    }

    public function reorderStage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'members' => 'required|array|max:40',
            'members.*.daily_session_id' => 'required|string|max:128',
            'members.*.display_name' => 'nullable|string|max:191',
            'members.*.user_id' => 'nullable|integer',
            'members.*.stage_role' => 'nullable|string|max:32',
            'members.*.spotlighted' => 'nullable|boolean',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $result = $this->engagement->reorderStage($data['meeting_key'], $user, $data['members']);
        if (!$result['ok']) {
            return response()->json(['message' => $result['message'] ?? 'Unable to reorder.'], 422);
        }

        return response()->json(['stage' => $result['stage']]);
    }

    public function speakingTimer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'required|string|max:128',
            'daily_session_id' => 'required|string|max:128',
        ]);

        $expired = $this->engagement->expireSpeakingGrants($data['meeting_key']);
        $grant = $this->engagement->activeSpeakingGrant($data['meeting_key'], $data['daily_session_id']);

        return response()->json([
            'grant' => $grant,
            'expired' => $expired,
        ]);
    }

    public function expireTimers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_key' => 'nullable|string|max:128',
        ]);

        $user = $request->user();
        if (!$user || !$this->engagement->actorCanModerate($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'expired' => $this->engagement->expireSpeakingGrants($data['meeting_key'] ?? null),
        ]);
    }
}
