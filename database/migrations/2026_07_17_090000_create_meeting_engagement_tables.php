<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('meeting_qa_items')) {
            Schema::create('meeting_qa_items', function (Blueprint $table) {
                $table->id();
                $table->string('meeting_key', 128)->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('daily_session_id', 128)->nullable();
                $table->string('author_name', 191);
                $table->text('question');
                $table->text('answer')->nullable();
                $table->string('status', 24)->default('open')->index(); // open|answered|dismissed|pinned
                $table->boolean('is_anonymous')->default(false);
                $table->unsignedInteger('upvotes')->default(0);
                $table->unsignedBigInteger('answered_by')->nullable();
                $table->timestamp('answered_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('meeting_polls')) {
            Schema::create('meeting_polls', function (Blueprint $table) {
                $table->id();
                $table->string('meeting_key', 128)->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('question', 500);
                $table->json('options'); // ["A","B","C"]
                $table->string('status', 24)->default('draft')->index(); // draft|open|closed
                $table->boolean('allow_multiple')->default(false);
                $table->boolean('show_results')->default(true);
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('meeting_poll_votes')) {
            Schema::create('meeting_poll_votes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('poll_id')->constrained('meeting_polls')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('daily_session_id', 128)->nullable()->index();
                $table->unsignedTinyInteger('option_index');
                $table->timestamps();
                $table->unique(['poll_id', 'daily_session_id', 'option_index'], 'poll_session_option_unique');
            });
        }

        if (!Schema::hasTable('meeting_breakout_rooms')) {
            Schema::create('meeting_breakout_rooms', function (Blueprint $table) {
                $table->id();
                $table->string('meeting_key', 128)->index();
                $table->string('name', 191);
                $table->string('daily_room_name', 128)->nullable();
                $table->string('daily_room_url')->nullable();
                $table->string('status', 24)->default('ready')->index(); // ready|open|closed
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->json('assigned_session_ids')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('meeting_stage_members')) {
            Schema::create('meeting_stage_members', function (Blueprint $table) {
                $table->id();
                $table->string('meeting_key', 128)->index();
                $table->string('daily_session_id', 128);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('display_name', 191)->nullable();
                $table->string('stage_role', 32)->default('panelist'); // host|moderator|panelist|guest
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('spotlighted')->default(false);
                $table->timestamps();
                $table->unique(['meeting_key', 'daily_session_id'], 'stage_member_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_stage_members');
        Schema::dropIfExists('meeting_breakout_rooms');
        Schema::dropIfExists('meeting_poll_votes');
        Schema::dropIfExists('meeting_polls');
        Schema::dropIfExists('meeting_qa_items');
    }
};
