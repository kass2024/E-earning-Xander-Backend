<?php

namespace App\Console\Commands;

use App\Services\InstitutionSignupService;
use Illuminate\Console\Command;

class PurgeOrphanPartnerAccounts extends Command
{
    protected $signature = 'institutions:purge-orphan-accounts {--dry-run : List orphans without deleting}';

    protected $description = 'Delete leftover partner_company accounts not tied to any institution (frees emails after institution delete)';

    public function handle(InstitutionSignupService $signup): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $removed = $signup->purgeOrphanPartnerAccounts($dryRun);

        if ($removed === []) {
            $this->info('No orphan partner/meeting accounts found.');

            return self::SUCCESS;
        }

        foreach ($removed as $row) {
            $verb = $dryRun ? 'Would delete' : 'Deleted';
            $this->line("{$verb} #{$row['id']} {$row['email']} ({$row['role']})");
        }

        $this->info(($dryRun ? 'Would purge' : 'Purged') . ' ' . count($removed) . ' account(s).');

        return self::SUCCESS;
    }
}
