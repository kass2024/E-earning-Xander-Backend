<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_zoom_meetings')) {
            Schema::table('admin_zoom_meetings', function (Blueprint $table) {
                if (!Schema::hasColumn('admin_zoom_meetings', 'meeting_provider')) {
                    $table->string('meeting_provider', 16)->default('zoom')->after('meta');
                }
                if (!Schema::hasColumn('admin_zoom_meetings', 'meeting_mode')) {
                    $table->string('meeting_mode', 16)->default('meeting')->after('meeting_provider');
                }
                if (!Schema::hasColumn('admin_zoom_meetings', 'daily_room_name')) {
                    $table->string('daily_room_name', 128)->nullable()->after('meeting_mode');
                }
                if (!Schema::hasColumn('admin_zoom_meetings', 'daily_room_url')) {
                    $table->text('daily_room_url')->nullable()->after('daily_room_name');
                }
                if (!Schema::hasColumn('admin_zoom_meetings', 'session_status')) {
                    $table->string('session_status', 32)->default('scheduled')->after('daily_room_url');
                }
            });
        }

        if (!Schema::hasTable('meeting_hand_raises')) {
            Schema::create('meeting_hand_raises', function (Blueprint $table) {
                $table->id();
                $table->string('meeting_key', 128)->index();
                $table->string('meeting_mode', 16)->default('meeting');
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('daily_session_id', 128)->index();
                $table->string('participant_name', 191);
                $table->string('status', 24)->default('pending')->index();
                $table->timestamp('requested_at');
                $table->timestamp('reviewed_at')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->unsignedInteger('speaking_duration_seconds')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['meeting_key', 'daily_session_id', 'status'], 'hand_raise_lookup_idx');
            });
        }

        if (!Schema::hasTable('meeting_speaking_grants')) {
            Schema::create('meeting_speaking_grants', function (Blueprint $table) {
                $table->id();
                $table->string('meeting_key', 128)->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('daily_session_id', 128)->index();
                $table->string('speaking_state', 32)->default('listening');
                $table->boolean('audio_granted')->default(false);
                $table->boolean('video_granted')->default(false);
                $table->boolean('screen_share_granted')->default(false);
                $table->boolean('on_stage')->default(false);
                $table->unsignedBigInteger('granted_by')->nullable();
                $table->timestamp('granted_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['meeting_key', 'daily_session_id'], 'speaking_grant_session_unique');
            });
        }

        if (!Schema::hasTable('meeting_moderation_events')) {
            Schema::create('meeting_moderation_events', function (Blueprint $table) {
                $table->id();
                $table->string('meeting_key', 128)->index();
                $table->unsignedBigInteger('actor_user_id')->nullable()->index();
                $table->unsignedBigInteger('target_user_id')->nullable()->index();
                $table->string('target_session_id', 128)->nullable();
                $table->string('action', 64)->index();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_moderation_events');
        Schema::dropIfExists('meeting_speaking_grants');
        Schema::dropIfExists('meeting_hand_raises');

        if (Schema::hasTable('admin_zoom_meetings')) {
            Schema::table('admin_zoom_meetings', function (Blueprint $table) {
                foreach (['session_status', 'daily_room_url', 'daily_room_name', 'meeting_mode', 'meeting_provider'] as $col) {
                    if (Schema::hasColumn('admin_zoom_meetings', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
