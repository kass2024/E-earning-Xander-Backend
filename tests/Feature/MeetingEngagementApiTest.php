<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Automated suite for meeting engagement APIs (Q&A, polls, stage).
 * Skips gracefully if migrations have not been applied in the test DB.
 */
class MeetingEngagementApiTest extends TestCase
{
    use RefreshDatabase;

    private function skipUnlessReady(): void
    {
        if (!Schema::hasTable('meeting_qa_items') || !Schema::hasTable('users')) {
            $this->markTestSkipped('Engagement tables not available.');
        }
    }

    private function makeHost(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'email' => 'host-engage-' . uniqid() . '@example.com',
        ]);
    }

    public function test_ask_and_list_questions(): void
    {
        $this->skipUnlessReady();
        $host = $this->makeHost();

        $ask = $this->actingAs($host, 'sanctum')->postJson('/api/admin/meetings/engagement/questions', [
            'meeting_key' => 'meet-e2e-1',
            'question' => 'What is the homework?',
            'author_name' => 'Learner',
            'daily_session_id' => 'sess-1',
        ]);

        $ask->assertOk()->assertJsonPath('question.question', 'What is the homework?');

        $list = $this->actingAs($host, 'sanctum')->getJson(
            '/api/admin/meetings/engagement/questions?meeting_key=meet-e2e-1',
        );
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, count($list->json('questions')));
    }

    public function test_create_and_vote_poll(): void
    {
        $this->skipUnlessReady();
        $host = $this->makeHost();

        $create = $this->actingAs($host, 'sanctum')->postJson('/api/admin/meetings/engagement/polls', [
            'meeting_key' => 'meet-e2e-2',
            'question' => 'Ready?',
            'options' => ['Yes', 'No'],
            'open_now' => true,
        ]);
        $create->assertOk();
        $pollId = $create->json('poll.id');

        $vote = $this->actingAs($host, 'sanctum')->postJson('/api/admin/meetings/engagement/polls/vote', [
            'meeting_key' => 'meet-e2e-2',
            'poll_id' => $pollId,
            'option_indexes' => [0],
            'daily_session_id' => 'sess-vote',
        ]);
        $vote->assertOk();
        $this->assertSame(1, $vote->json('poll.counts.0'));
    }

    public function test_reorder_webinar_stage(): void
    {
        $this->skipUnlessReady();
        $host = $this->makeHost();

        $res = $this->actingAs($host, 'sanctum')->postJson('/api/admin/meetings/engagement/stage/reorder', [
            'meeting_key' => 'meet-e2e-3',
            'members' => [
                ['daily_session_id' => 'a', 'display_name' => 'Alice', 'stage_role' => 'host'],
                ['daily_session_id' => 'b', 'display_name' => 'Bob', 'stage_role' => 'panelist'],
            ],
        ]);

        $res->assertOk();
        $this->assertCount(2, $res->json('stage'));
        $this->assertSame('Alice', $res->json('stage.0.display_name'));
    }

    public function test_non_moderator_cannot_create_poll(): void
    {
        $this->skipUnlessReady();
        if (!method_exists(User::class, 'factory')) {
            $this->markTestSkipped('User factory missing.');
        }

        $student = User::factory()->create([
            'role' => 'student',
            'email' => 'student-engage-' . uniqid() . '@example.com',
        ]);

        $res = $this->actingAs($student, 'sanctum')->postJson('/api/admin/meetings/engagement/polls', [
            'meeting_key' => 'meet-e2e-4',
            'question' => 'Nope',
            'options' => ['A', 'B'],
        ]);

        $res->assertStatus(422);
    }
}
