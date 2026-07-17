<?php

namespace App\Services\Meetings;

use App\Models\MeetingBreakoutRoom;
use App\Models\MeetingPoll;
use App\Models\MeetingPollVote;
use App\Models\MeetingQaItem;
use App\Models\MeetingSpeakingGrant;
use App\Models\MeetingStageMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MeetingEngagementService
{
    public function __construct(
        private readonly MeetingModerationService $moderation,
        private readonly DailyApiService $daily,
    ) {}

    public function tablesReady(): bool
    {
        return Schema::hasTable('meeting_qa_items')
            && Schema::hasTable('meeting_polls')
            && Schema::hasTable('meeting_breakout_rooms')
            && Schema::hasTable('meeting_stage_members');
    }

    public function actorCanModerate(?User $user): bool
    {
        return $this->moderation->actorCanModerate($user);
    }

    /** @return list<array<string, mixed>> */
    public function listQuestions(string $meetingKey): array
    {
        if (!$this->tablesReady()) {
            return [];
        }

        return MeetingQaItem::query()
            ->where('meeting_key', $meetingKey)
            ->whereIn('status', ['open', 'answered', 'pinned'])
            ->orderByRaw("FIELD(status, 'pinned', 'open', 'answered')")
            ->orderByDesc('upvotes')
            ->orderBy('created_at')
            ->get()
            ->map(fn (MeetingQaItem $row) => $this->serializeQuestion($row))
            ->all();
    }

    /**
     * @return array{ok: bool, question?: array<string, mixed>, message?: string}
     */
    public function askQuestion(
        string $meetingKey,
        string $question,
        string $authorName,
        ?int $userId,
        ?string $sessionId,
        bool $anonymous = false,
    ): array {
        if (!$this->tablesReady()) {
            return ['ok' => false, 'message' => 'Engagement tables are not migrated yet.'];
        }

        $text = trim($question);
        if ($text === '' || mb_strlen($text) > 2000) {
            return ['ok' => false, 'message' => 'Question must be 1–2000 characters.'];
        }

        $row = MeetingQaItem::query()->create([
            'meeting_key' => $meetingKey,
            'user_id' => $userId,
            'daily_session_id' => $sessionId,
            'author_name' => $anonymous ? 'Anonymous' : mb_substr(trim($authorName) ?: 'Participant', 0, 191),
            'question' => $text,
            'status' => 'open',
            'is_anonymous' => $anonymous,
        ]);

        $this->moderation->audit($meetingKey, $userId, $userId, $sessionId, 'qa_asked', ['qa_id' => $row->id]);

        return ['ok' => true, 'question' => $this->serializeQuestion($row)];
    }

    /**
     * @return array{ok: bool, question?: array<string, mixed>, message?: string}
     */
    public function upvoteQuestion(string $meetingKey, int $questionId): array
    {
        $row = MeetingQaItem::query()
            ->where('meeting_key', $meetingKey)
            ->where('id', $questionId)
            ->first();

        if (!$row) {
            return ['ok' => false, 'message' => 'Question not found.'];
        }

        $row->upvotes = (int) $row->upvotes + 1;
        $row->save();

        return ['ok' => true, 'question' => $this->serializeQuestion($row)];
    }

    /**
     * @return array{ok: bool, question?: array<string, mixed>, message?: string}
     */
    public function answerQuestion(string $meetingKey, int $questionId, User $actor, string $answer): array
    {
        if (!$this->actorCanModerate($actor)) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }

        $row = MeetingQaItem::query()
            ->where('meeting_key', $meetingKey)
            ->where('id', $questionId)
            ->first();

        if (!$row) {
            return ['ok' => false, 'message' => 'Question not found.'];
        }

        $row->answer = trim($answer) !== '' ? trim($answer) : $row->answer;
        $row->status = 'answered';
        $row->answered_by = $actor->id;
        $row->answered_at = now();
        $row->save();

        $this->moderation->audit($meetingKey, $actor->id, $row->user_id, $row->daily_session_id, 'qa_answered', [
            'qa_id' => $row->id,
        ]);

        return ['ok' => true, 'question' => $this->serializeQuestion($row)];
    }

    /**
     * @return array{ok: bool, question?: array<string, mixed>, message?: string}
     */
    public function setQuestionStatus(string $meetingKey, int $questionId, User $actor, string $status): array
    {
        if (!$this->actorCanModerate($actor)) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }

        if (!in_array($status, ['open', 'answered', 'dismissed', 'pinned'], true)) {
            return ['ok' => false, 'message' => 'Invalid status.'];
        }

        $row = MeetingQaItem::query()
            ->where('meeting_key', $meetingKey)
            ->where('id', $questionId)
            ->first();

        if (!$row) {
            return ['ok' => false, 'message' => 'Question not found.'];
        }

        $row->status = $status;
        $row->save();

        return ['ok' => true, 'question' => $this->serializeQuestion($row)];
    }

    /** @return list<array<string, mixed>> */
    public function listPolls(string $meetingKey, bool $includeDrafts = false): array
    {
        if (!$this->tablesReady()) {
            return [];
        }

        $q = MeetingPoll::query()->where('meeting_key', $meetingKey)->orderByDesc('id');
        if (!$includeDrafts) {
            $q->whereIn('status', ['open', 'closed']);
        }

        return $q->with('votes')->get()->map(fn (MeetingPoll $poll) => $this->serializePoll($poll))->all();
    }

    /**
     * @param  list<string>  $options
     * @return array{ok: bool, poll?: array<string, mixed>, message?: string}
     */
    public function createPoll(
        string $meetingKey,
        User $actor,
        string $question,
        array $options,
        bool $allowMultiple = false,
        bool $openNow = true,
    ): array {
        if (!$this->actorCanModerate($actor)) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }
        if (!$this->tablesReady()) {
            return ['ok' => false, 'message' => 'Engagement tables are not migrated yet.'];
        }

        $cleanOptions = array_values(array_filter(array_map(
            fn ($o) => mb_substr(trim((string) $o), 0, 200),
            $options,
        ), fn ($o) => $o !== ''));

        if (count($cleanOptions) < 2 || count($cleanOptions) > 8) {
            return ['ok' => false, 'message' => 'Polls need 2–8 options.'];
        }

        $poll = MeetingPoll::query()->create([
            'meeting_key' => $meetingKey,
            'created_by' => $actor->id,
            'question' => mb_substr(trim($question), 0, 500),
            'options' => $cleanOptions,
            'status' => $openNow ? 'open' : 'draft',
            'allow_multiple' => $allowMultiple,
            'opened_at' => $openNow ? now() : null,
        ]);

        $this->moderation->audit($meetingKey, $actor->id, null, null, 'poll_created', ['poll_id' => $poll->id]);

        return ['ok' => true, 'poll' => $this->serializePoll($poll)];
    }

    /**
     * @return array{ok: bool, poll?: array<string, mixed>, message?: string}
     */
    public function openPoll(string $meetingKey, int $pollId, User $actor): array
    {
        if (!$this->actorCanModerate($actor)) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }

        $poll = MeetingPoll::query()->where('meeting_key', $meetingKey)->where('id', $pollId)->first();
        if (!$poll) {
            return ['ok' => false, 'message' => 'Poll not found.'];
        }

        $poll->status = 'open';
        $poll->opened_at = now();
        $poll->closed_at = null;
        $poll->save();

        return ['ok' => true, 'poll' => $this->serializePoll($poll)];
    }

    /**
     * @return array{ok: bool, poll?: array<string, mixed>, message?: string}
     */
    public function closePoll(string $meetingKey, int $pollId, User $actor): array
    {
        if (!$this->actorCanModerate($actor)) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }

        $poll = MeetingPoll::query()->where('meeting_key', $meetingKey)->where('id', $pollId)->first();
        if (!$poll) {
            return ['ok' => false, 'message' => 'Poll not found.'];
        }

        $poll->status = 'closed';
        $poll->closed_at = now();
        $poll->save();

        return ['ok' => true, 'poll' => $this->serializePoll($poll)];
    }

    /**
     * @param  list<int>  $optionIndexes
     * @return array{ok: bool, poll?: array<string, mixed>, message?: string}
     */
    public function votePoll(
        string $meetingKey,
        int $pollId,
        array $optionIndexes,
        ?int $userId,
        ?string $sessionId,
    ): array {
        $poll = MeetingPoll::query()->where('meeting_key', $meetingKey)->where('id', $pollId)->first();
        if (!$poll || $poll->status !== 'open') {
            return ['ok' => false, 'message' => 'Poll is not open.'];
        }

        $max = count($poll->options ?? []) - 1;
        $indexes = array_values(array_unique(array_filter(
            array_map('intval', $optionIndexes),
            fn ($i) => $i >= 0 && $i <= $max,
        )));

        if ($indexes === []) {
            return ['ok' => false, 'message' => 'Select a valid option.'];
        }

        if (!$poll->allow_multiple) {
            $indexes = [$indexes[0]];
        }

        return DB::transaction(function () use ($poll, $indexes, $userId, $sessionId) {
            $sessionKey = $sessionId ?: ('user:' . ($userId ?? 'anon'));
            MeetingPollVote::query()
                ->where('poll_id', $poll->id)
                ->where(function ($q) use ($userId, $sessionId, $sessionKey) {
                    if ($sessionId) {
                        $q->where('daily_session_id', $sessionId);
                    } elseif ($userId) {
                        $q->where('user_id', $userId);
                    } else {
                        $q->where('daily_session_id', $sessionKey);
                    }
                })
                ->delete();

            foreach ($indexes as $idx) {
                MeetingPollVote::query()->create([
                    'poll_id' => $poll->id,
                    'user_id' => $userId,
                    'daily_session_id' => $sessionId ?: $sessionKey,
                    'option_index' => $idx,
                ]);
            }

            return ['ok' => true, 'poll' => $this->serializePoll($poll->fresh())];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listBreakouts(string $meetingKey): array
    {
        if (!$this->tablesReady()) {
            return [];
        }

        return MeetingBreakoutRoom::query()
            ->where('meeting_key', $meetingKey)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (MeetingBreakoutRoom $r) => $this->serializeBreakout($r))
            ->all();
    }

    /**
     * @param  list<string>  $names
     * @return array{ok: bool, rooms?: list<array<string, mixed>>, message?: string}
     */
    public function createBreakouts(string $meetingKey, User $actor, array $names): array
    {
        if (!$this->actorCanModerate($actor)) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }
        if (!$this->tablesReady()) {
            return ['ok' => false, 'message' => 'Engagement tables are not migrated yet.'];
        }

        $clean = array_values(array_filter(array_map(
            fn ($n) => mb_substr(trim((string) $n), 0, 191),
            $names,
        ), fn ($n) => $n !== ''));

        if ($clean === []) {
            $clean = ['Breakout 1', 'Breakout 2'];
        }
        if (count($clean) > 12) {
            return ['ok' => false, 'message' => 'Maximum 12 breakout rooms.'];
        }

        $created = [];
        $base = 'bo-' . Str::slug(Str::limit($meetingKey, 40, '')) . '-' . Str::lower(Str::random(4));

        foreach ($clean as $i => $name) {
            $roomName = $base . '-' . ($i + 1);
            $url = null;
            try {
                $room = $this->daily->createRoom($roomName, $this->daily->classroomRoomProperties([
                    'exp' => now()->addHours(8)->timestamp,
                ]));
                $url = $room['url'] ?? null;
                $roomName = $room['name'] ?? $roomName;
            } catch (\Throwable $e) {
                // Keep DB row even if Daily room creation fails; host can retry open.
                $url = null;
            }

            $row = MeetingBreakoutRoom::query()->create([
                'meeting_key' => $meetingKey,
                'name' => $name,
                'daily_room_name' => $roomName,
                'daily_room_url' => $url,
                'status' => 'ready',
                'sort_order' => $i,
                'assigned_session_ids' => [],
            ]);
            $created[] = $this->serializeBreakout($row);
        }

        $this->moderation->audit($meetingKey, $actor->id, null, null, 'breakouts_created', [
            'count' => count($created),
        ]);

        return ['ok' => true, 'rooms' => $created];
    }

    /**
     * @param  list<string>  $sessionIds
     * @return array{ok: bool, room?: array<string, mixed>, message?: string}
     */
    public function assignBreakout(string $meetingKey, int $roomId, array $sessionIds, User $actor): array
    {
        if (!$this->actorCanModerate($actor)) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }

        $room = MeetingBreakoutRoom::query()->where('meeting_key', $meetingKey)->where('id', $roomId)->first();
        if (!$room) {
            return ['ok' => false, 'message' => 'Breakout not found.'];
        }

        $ids = array_values(array_unique(array_filter(array_map('strval', $sessionIds))));
        $room->assigned_session_ids = $ids;
        $room->save();

        return ['ok' => true, 'room' => $this->serializeBreakout($room)];
    }

    /**
     * @return array{ok: bool, rooms?: list<array<string, mixed>>, message?: string}
     */
    public function openBreakouts(string $meetingKey, User $actor): array
    {
        if (!$this->actorCanModerate($actor)) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }

        MeetingBreakoutRoom::query()
            ->where('meeting_key', $meetingKey)
            ->whereIn('status', ['ready', 'closed'])
            ->update(['status' => 'open']);

        $this->moderation->audit($meetingKey, $actor->id, null, null, 'breakouts_opened');

        return ['ok' => true, 'rooms' => $this->listBreakouts($meetingKey)];
    }

    /**
     * @return array{ok: bool, rooms?: list<array<string, mixed>>, message?: string}
     */
    public function closeBreakouts(string $meetingKey, User $actor): array
    {
        if (!$this->actorCanModerate($actor)) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }

        MeetingBreakoutRoom::query()
            ->where('meeting_key', $meetingKey)
            ->where('status', 'open')
            ->update(['status' => 'closed']);

        $this->moderation->audit($meetingKey, $actor->id, null, null, 'breakouts_closed');

        return ['ok' => true, 'rooms' => $this->listBreakouts($meetingKey)];
    }

    /** @return list<array<string, mixed>> */
    public function listStage(string $meetingKey): array
    {
        if (!$this->tablesReady()) {
            return [];
        }

        return MeetingStageMember::query()
            ->where('meeting_key', $meetingKey)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (MeetingStageMember $m) => [
                'id' => $m->id,
                'meeting_key' => $m->meeting_key,
                'daily_session_id' => $m->daily_session_id,
                'user_id' => $m->user_id,
                'display_name' => $m->display_name,
                'stage_role' => $m->stage_role,
                'sort_order' => $m->sort_order,
                'spotlighted' => (bool) $m->spotlighted,
            ])
            ->all();
    }

    /**
     * @param  list<array{daily_session_id: string, display_name?: string, user_id?: int|null, stage_role?: string, spotlighted?: bool}>  $members
     * @return array{ok: bool, stage?: list<array<string, mixed>>, message?: string}
     */
    public function reorderStage(string $meetingKey, User $actor, array $members): array
    {
        if (!$this->actorCanModerate($actor)) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }
        if (!$this->tablesReady()) {
            return ['ok' => false, 'message' => 'Engagement tables are not migrated yet.'];
        }

        return DB::transaction(function () use ($meetingKey, $actor, $members) {
            MeetingStageMember::query()->where('meeting_key', $meetingKey)->delete();

            foreach (array_values($members) as $i => $member) {
                $sessionId = trim((string) ($member['daily_session_id'] ?? ''));
                if ($sessionId === '') {
                    continue;
                }
                MeetingStageMember::query()->create([
                    'meeting_key' => $meetingKey,
                    'daily_session_id' => $sessionId,
                    'user_id' => isset($member['user_id']) ? (int) $member['user_id'] : null,
                    'display_name' => mb_substr((string) ($member['display_name'] ?? 'Panelist'), 0, 191),
                    'stage_role' => (string) ($member['stage_role'] ?? 'panelist'),
                    'sort_order' => $i,
                    'spotlighted' => (bool) ($member['spotlighted'] ?? false),
                ]);
            }

            $this->moderation->audit($meetingKey, $actor->id, null, null, 'stage_reordered', [
                'count' => count($members),
            ]);

            return ['ok' => true, 'stage' => $this->listStage($meetingKey)];
        });
    }

    /**
     * Expire speaking grants past expires_at. Host client should apply Daily revoke.
     *
     * @return list<array{meeting_key: string, daily_session_id: string}>
     */
    public function expireSpeakingGrants(?string $meetingKey = null): array
    {
        if (!Schema::hasTable('meeting_speaking_grants')) {
            return [];
        }

        $q = MeetingSpeakingGrant::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereIn('speaking_state', ['approved', 'speaking'])
            ->whereNull('revoked_at');

        if ($meetingKey) {
            $q->where('meeting_key', $meetingKey);
        }

        $expired = [];
        foreach ($q->get() as $grant) {
            $grant->speaking_state = 'revoked';
            $grant->audio_granted = false;
            $grant->video_granted = false;
            $grant->screen_share_granted = false;
            $grant->on_stage = false;
            $grant->revoked_at = now();
            $grant->save();
            $expired[] = [
                'meeting_key' => $grant->meeting_key,
                'daily_session_id' => $grant->daily_session_id,
                'expires_at' => $grant->expires_at?->toIso8601String(),
            ];
        }

        return $expired;
    }

    /**
     * Active speaking grant for a session (for timer UI).
     *
     * @return array<string, mixed>|null
     */
    public function activeSpeakingGrant(string $meetingKey, string $sessionId): ?array
    {
        if (!Schema::hasTable('meeting_speaking_grants')) {
            return null;
        }

        $grant = MeetingSpeakingGrant::query()
            ->where('meeting_key', $meetingKey)
            ->where('daily_session_id', $sessionId)
            ->whereIn('speaking_state', ['approved', 'speaking'])
            ->whereNull('revoked_at')
            ->first();

        if (!$grant) {
            return null;
        }

        if ($grant->expires_at && $grant->expires_at->isPast()) {
            return null;
        }

        return [
            'daily_session_id' => $grant->daily_session_id,
            'speaking_state' => $grant->speaking_state,
            'expires_at' => $grant->expires_at?->toIso8601String(),
            'remaining_seconds' => $grant->expires_at
                ? max(0, $grant->expires_at->getTimestamp() - now()->getTimestamp())
                : null,
            'audio_granted' => (bool) $grant->audio_granted,
            'video_granted' => (bool) $grant->video_granted,
            'on_stage' => (bool) $grant->on_stage,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeQuestion(MeetingQaItem $row): array
    {
        return [
            'id' => $row->id,
            'meeting_key' => $row->meeting_key,
            'author_name' => $row->author_name,
            'question' => $row->question,
            'answer' => $row->answer,
            'status' => $row->status,
            'is_anonymous' => (bool) $row->is_anonymous,
            'upvotes' => (int) $row->upvotes,
            'answered_at' => $row->answered_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializePoll(MeetingPoll $poll): array
    {
        $counts = array_fill(0, count($poll->options ?? []), 0);
        foreach ($poll->votes as $vote) {
            $idx = (int) $vote->option_index;
            if (isset($counts[$idx])) {
                $counts[$idx]++;
            }
        }

        return [
            'id' => $poll->id,
            'meeting_key' => $poll->meeting_key,
            'question' => $poll->question,
            'options' => $poll->options,
            'status' => $poll->status,
            'allow_multiple' => (bool) $poll->allow_multiple,
            'show_results' => (bool) $poll->show_results,
            'counts' => $counts,
            'total_votes' => array_sum($counts),
            'opened_at' => $poll->opened_at?->toIso8601String(),
            'closed_at' => $poll->closed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeBreakout(MeetingBreakoutRoom $room): array
    {
        return [
            'id' => $room->id,
            'meeting_key' => $room->meeting_key,
            'name' => $room->name,
            'daily_room_name' => $room->daily_room_name,
            'daily_room_url' => $room->daily_room_url,
            'status' => $room->status,
            'sort_order' => $room->sort_order,
            'assigned_session_ids' => $room->assigned_session_ids ?? [],
            'assigned_count' => count($room->assigned_session_ids ?? []),
        ];
    }
}
