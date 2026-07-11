<?php

namespace App\Console\Commands;

use App\Services\ZoomHostAssignmentService;
use App\Services\ZoomService;
use Illuminate\Console\Command;

class AssignInstitutionZoomHosts extends Command
{
    protected $signature = 'zoom:assign-institution-hosts {--dry-run : Preview assignments without saving} {--refresh : Refresh licensed users from Zoom API}';

    protected $description = 'Assign a dedicated Zoom host to each partner institution missing one';

    public function handle(ZoomHostAssignmentService $assignment, ZoomService $zoom): int
    {
        if ($this->option('refresh')) {
            $zoom->invalidateLicensedAccountUsersCache();
        }

        $inventory = $assignment->getHostInventory();
        $discovered = (int) ($inventory['zoom_users_discovered'] ?? 0);
        $this->line('Licensed Zoom users discovered: ' . $discovered);

        if (!$inventory['zoom_api_connected']) {
            $this->warn('Zoom API is not configured. Falling back to ZOOM_HOST_POOL env entries only.');
        } elseif ($discovered === 0) {
            $this->warn('No licensed users returned from Zoom. Add scope user:read:list_users:admin to your S2S app, or set ZOOM_HOST_POOL in .env.');
        }

        $dryRun = (bool) $this->option('dry-run');
        $results = $assignment->backfillMissingHosts($dryRun);

        if ($results === []) {
            $this->info('All institutions already have a Zoom host assigned.');

            return self::SUCCESS;
        }

        foreach ($results as $row) {
            if ($dryRun) {
                $host = $row['would_assign'] ?? '(none available)';
                $this->line("[dry-run] #{$row['id']} {$row['name']} -> {$host}");
            } else {
                $host = $row['assigned'] ?? '(none available)';
                $this->line("Assigned #{$row['id']} {$row['name']} -> {$host}");
            }
        }

        $this->info(($dryRun ? 'Preview complete.' : 'Assignment complete.') . ' ' . count($results) . ' institution(s) processed.');

        return self::SUCCESS;
    }
}
