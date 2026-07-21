<?php

namespace App\Console\Commands;

use App\Models\AvailableSchedule;
use App\Models\MeetingRegistration;
use App\Models\User;
use App\Support\PlatformInstitutionHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Institution availability/bookings stamped as hub (null) leak into the main dashboard.
 * Backfill platform_institution_id from the creator or linked schedule.
 */
class RepairMeetingTenantIsolation extends Command
{
    protected $signature = 'institutions:repair-meeting-tenants {--dry-run : List rows without updating}';

    protected $description = 'Backfill platform_institution_id on available schedules and meeting registrations that belong to partners but were saved as hub';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $schedulesFixed = $this->repairSchedules($dryRun);
        $regsFixed = $this->repairRegistrations($dryRun);

        $this->info(
            ($dryRun ? 'Would repair' : 'Repaired')
            . " {$schedulesFixed} schedule(s) and {$regsFixed} registration(s)."
        );

        return self::SUCCESS;
    }

    private function repairSchedules(bool $dryRun): int
    {
        if (!Schema::hasColumn('available_schedules', 'platform_institution_id')
            || !Schema::hasColumn('available_schedules', 'created_by')) {
            $this->warn('available_schedules tenant columns not available.');

            return 0;
        }

        $fixed = 0;
        AvailableSchedule::query()
            ->whereNull('platform_institution_id')
            ->whereNotNull('created_by')
            ->orderBy('id')
            ->each(function (AvailableSchedule $schedule) use ($dryRun, &$fixed) {
                $creator = User::query()->find($schedule->created_by);
                if (!$creator || empty($creator->platform_institution_id)) {
                    return;
                }
                if (PlatformInstitutionHelper::isMainPlatformAdmin($creator)) {
                    return;
                }

                $institutionId = (int) $creator->platform_institution_id;
                $this->line(
                    ($dryRun ? 'Would fix' : 'Fixed')
                    . " schedule #{$schedule->id} {$schedule->available_on_date} → institution {$institutionId}"
                );
                if (!$dryRun) {
                    $schedule->platform_institution_id = $institutionId;
                    $schedule->save();
                }
                $fixed++;
            });

        return $fixed;
    }

    private function repairRegistrations(bool $dryRun): int
    {
        if (!Schema::hasColumn('meeting_registrations', 'platform_institution_id')) {
            $this->warn('meeting_registrations.platform_institution_id not available.');

            return 0;
        }

        $fixed = 0;
        MeetingRegistration::query()
            ->with('availableSchedule')
            ->whereNull('platform_institution_id')
            ->orderBy('id')
            ->each(function (MeetingRegistration $registration) use ($dryRun, &$fixed) {
                $institutionId = 0;
                $schedule = $registration->availableSchedule;
                if ($schedule && !empty($schedule->platform_institution_id)) {
                    $institutionId = (int) $schedule->platform_institution_id;
                }

                if ($institutionId <= 0 && Schema::hasColumn('available_schedules', 'created_by') && $schedule?->created_by) {
                    $creator = User::query()->find($schedule->created_by);
                    if ($creator
                        && !empty($creator->platform_institution_id)
                        && !PlatformInstitutionHelper::isMainPlatformAdmin($creator)
                    ) {
                        $institutionId = (int) $creator->platform_institution_id;
                        if (!$dryRun && empty($schedule->platform_institution_id)) {
                            $schedule->platform_institution_id = $institutionId;
                            $schedule->save();
                        }
                    }
                }

                if ($institutionId <= 0) {
                    return;
                }

                $this->line(
                    ($dryRun ? 'Would fix' : 'Fixed')
                    . " registration #{$registration->id} {$registration->email} → institution {$institutionId}"
                );
                if (!$dryRun) {
                    $registration->platform_institution_id = $institutionId;
                    $registration->save();
                }
                $fixed++;
            });

        return $fixed;
    }
}
