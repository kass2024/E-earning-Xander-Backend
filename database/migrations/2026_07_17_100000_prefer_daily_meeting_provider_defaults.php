<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prefer Daily as the system-wide default meeting provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_settings')) {
            $exists = DB::table('platform_settings')
                ->where('key', 'main_platform_meeting_provider')
                ->exists();

            if (!$exists) {
                DB::table('platform_settings')->insert([
                    'key' => 'main_platform_meeting_provider',
                    'value' => 'daily',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('platform_institutions') && Schema::hasColumn('platform_institutions', 'meeting_provider')) {
            // Only backfill empty/null — do not override an explicit Zoom choice.
            DB::table('platform_institutions')
                ->where(function ($q) {
                    $q->whereNull('meeting_provider')->orWhere('meeting_provider', '');
                })
                ->update(['meeting_provider' => 'daily']);
        }

        if (Schema::hasTable('admin_zoom_meetings') && Schema::hasColumn('admin_zoom_meetings', 'meeting_provider')) {
            try {
                DB::statement("ALTER TABLE admin_zoom_meetings MODIFY meeting_provider VARCHAR(16) NOT NULL DEFAULT 'daily'");
            } catch (\Throwable) {
                // Non-MySQL or already matching — ignore.
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty — Daily remains the preferred default.
    }
};
