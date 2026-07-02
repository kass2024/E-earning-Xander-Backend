<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Student;
use App\Models\User;
use App\Support\PlatformUserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class RepairPlatformUsers extends Command
{
    protected $signature = 'users:repair-platform
                            {--password= : Plain password to set on platform accounts}
                            {--debug-email= : Email to test against --debug-password}
                            {--debug-password= : Password to test with --debug-email}';

    protected $description = 'Deduplicate platform users, enforce unique emails, and reset admin passwords';

    public function handle(): int
    {
        if (!Schema::hasTable('users')) {
            $this->error('users table does not exist.');

            return self::FAILURE;
        }

        $debugEmail = (string) ($this->option('debug-email') ?? '');
        $debugPassword = (string) ($this->option('debug-password') ?? '');
        if ($debugEmail !== '' && $debugPassword !== '') {
            $this->printLoginDebug($debugEmail, $debugPassword);

            return self::SUCCESS;
        }

        $normalizedDupes = PlatformUserService::findNormalizedDuplicateGroups();
        if ($normalizedDupes !== []) {
            $this->warn('Normalized duplicate emails found:');
            foreach ($normalizedDupes as $row) {
                $this->line("  {$row['normalized_email']} ({$row['count']} rows)");
            }
        }

        $removed = PlatformUserService::dedupeDuplicateEmails();
        $legacyRemoved = PlatformUserService::deleteLegacyEmails();
        $this->info("Removed {$removed} duplicate user row(s) and {$legacyRemoved} legacy account(s).");

        $normalized = PlatformUserService::normalizeStoredEmails();
        if ($normalized > 0) {
            $this->info("Normalized {$normalized} stored email value(s).");
        }

        try {
            PlatformUserService::ensureUniqueEmailIndex();
            $this->info('Unique index on users.email is in place.');
        } catch (\Throwable $e) {
            $this->warn('Could not add unique email index: ' . $e->getMessage());
        }

        $plain = trim((string) ($this->option('password') ?: PlatformUserService::seedPassword()), " \t\n\r\0\x0B'\"");
        $updated = PlatformUserService::resetPlatformUserPasswords($plain);
        $this->info("Reset password on {$updated} user row(s).");

        $this->newLine();
        $this->line('Platform logins:');
        foreach (PlatformUserService::PLATFORM_EMAILS as $email) {
            $this->line("  {$email}");
        }
        $this->line("  password: {$plain}");

        return self::SUCCESS;
    }

    private function printLoginDebug(string $email, string $password): void
    {
        $normalized = PlatformUserService::normalizeEmail($email);

        $this->info("Login debug for: {$email}");
        $this->line('Normalized email: ' . $normalized);
        $this->line('Seed password config: ' . PlatformUserService::seedPassword());
        $this->newLine();

        $normalizedDupes = PlatformUserService::findNormalizedDuplicateGroups();
        if ($normalizedDupes === []) {
            $this->info('No normalized duplicate user emails.');
        } else {
            $this->error('Normalized duplicate emails (same inbox, different stored values):');
            foreach ($normalizedDupes as $row) {
                $this->line("  {$row['normalized_email']}: {$row['count']} rows");
            }
        }

        $exactDupes = DB::table('users')
            ->select('email', DB::raw('COUNT(*) as count'))
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($exactDupes->isNotEmpty()) {
            $this->warn('Exact-string duplicate emails:');
            foreach ($exactDupes as $row) {
                $this->line("  [{$row->email}] ({$row->count} rows)");
            }
        }

        $this->newLine();
        $this->line('users table:');
        $users = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->warn('  No user rows for this email.');
        }

        foreach ($users as $user) {
            $stored = PlatformUserService::storedPasswordHash($user);
            $matches = PlatformUserService::verifyPassword($user, $password) ? 'YES' : 'NO';
            $rawEmail = (string) $user->getRawOriginal('email');
            $this->line("  id={$user->id} raw_email=[{$rawEmail}] len=" . strlen($rawEmail) . " role={$user->role} status={$user->status} updated={$user->updated_at} matches={$matches}");
        }

        $seedMatches = password_verify(PlatformUserService::seedPassword(), Hash::make(PlatformUserService::seedPassword())) ? 'YES' : 'NO';
        $this->newLine();
        $this->line("Config seed password verifies against fresh hash: {$seedMatches}");

        if (Schema::hasTable('students')) {
            $this->newLine();
            $this->line('students table (checked before users in old login — can block admin):');
            $students = Student::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
                ->orderBy('id')
                ->get();

            if ($students->isEmpty()) {
                $this->line('  No student rows for this email.');
            }

            foreach ($students as $student) {
                $stored = PlatformUserService::storedPasswordHash($student);
                $matches = PlatformUserService::verifyPassword($student, $password) ? 'YES' : 'NO';
                $this->line("  id={$student->id} status={$student->status} hash={$stored} matches={$matches}");
            }
        }

        if (Schema::hasTable('agents')) {
            $this->newLine();
            $this->line('agents table:');
            $agents = Agent::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
                ->orderBy('id')
                ->get();

            if ($agents->isEmpty()) {
                $this->line('  No agent rows for this email.');
            }

            foreach ($agents as $agent) {
                $stored = PlatformUserService::storedPasswordHash($agent);
                $matches = PlatformUserService::verifyPassword($agent, $password) ? 'YES' : 'NO';
                $this->line("  id={$agent->id} hash={$stored} matches={$matches}");
            }
        }

        $firstUser = User::query()
            ->where('email', $email)
            ->orWhere('name', $email)
            ->first();

        $this->newLine();
        $this->line('Old login query (first() only): id=' . ($firstUser?->id ?? 'none'));
    }
}
