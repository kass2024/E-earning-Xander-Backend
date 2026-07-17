<?php

namespace App\Console\Commands;

use App\Models\AdminZoomMeeting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RepairAdminMeetingTenants extends Command
{
    protected $signature = 'institutions:repair-admin-meetings {--dry-run : List rows without updating}';

    protected $description = 'Backfill platform_institution_id on admin meetings created by partners but stamped as hub (null)';

    public function handle(): int
    {
        if (!Schema::hasTable('admin_zoom_meetings')
            || !Schema::hasColumn('admin_zoom_meetings', 'platform_institution_id')) {
            $this->warn('admin_zoom_meetings.platform_institution_id not available.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $fixed = 0;

        AdminZoomMeeting::query()
            ->whereNull('platform_institution_id')
            ->whereNotNull('created_by_user_id')
            ->orderBy('id')
            ->each(function (AdminZoomMeeting $meeting) use ($dryRun, &$fixed) {
                $creator = User::query()->find($meeting->created_by_user_id);
                if (!$creator || empty($creator->platform_institution_id)) {
                    return;
                }
                if (\App\Support\PlatformInstitutionHelper::isMainPlatformAdmin($creator)) {
                    return;
                }

                $institutionId = (int) $creator->platform_institution_id;
                $this->line(
                    ($dryRun ? 'Would fix' : 'Fixed')
                    . " meeting #{$meeting->id} {$meeting->topic} → institution {$institutionId}"
                );
                if (!$dryRun) {
                    $meeting->platform_institution_id = $institutionId;
                    $meeting->save();
                }
                $fixed++;
            });

        $this->info(($dryRun ? 'Would repair' : 'Repaired') . " {$fixed} meeting(s).");

        return self::SUCCESS;
    }
}
