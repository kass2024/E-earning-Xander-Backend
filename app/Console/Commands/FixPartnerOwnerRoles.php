<?php

namespace App\Console\Commands;

use App\Models\PlatformInstitution;
use App\Models\User;
use App\Support\PlatformInstitutionHelper;
use Illuminate\Console\Command;

class FixPartnerOwnerRoles extends Command
{
    protected $signature = 'institutions:fix-owner-roles';

    protected $description = 'Restore partner_company role for institution owners demoted to meeting_user';

    public function handle(): int
    {
        $fixed = 0;

        $institutions = PlatformInstitution::query()
            ->whereNotNull('contact_email')
            ->orWhereNotNull('owner_user_id')
            ->get();

        foreach ($institutions as $institution) {
            $owners = User::query()
                ->where(function ($q) use ($institution) {
                    if (!empty($institution->owner_user_id)) {
                        $q->orWhere('id', (int) $institution->owner_user_id);
                    }
                    $email = strtolower(trim((string) ($institution->contact_email ?? '')));
                    if ($email !== '') {
                        $q->orWhereRaw('LOWER(TRIM(email)) = ?', [$email]);
                    }
                })
                ->get();

            foreach ($owners as $user) {
                $before = strtolower(trim((string) ($user->role ?? '')));
                $restored = PlatformInstitutionHelper::restorePartnerOwnerRole($user);
                $after = strtolower(trim((string) ($restored->role ?? '')));
                if ($before !== $after || (int) ($restored->platform_institution_id ?? 0) !== (int) $institution->id) {
                    $this->line("Fixed {$restored->email}: {$before} -> {$after} (institution #{$institution->id})");
                    $fixed++;
                }
            }
        }

        $this->info("Done. Updated {$fixed} account(s).");

        return self::SUCCESS;
    }
}
