<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\AuthController;
use App\Support\PlatformUserService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VerifyLocalPlatform extends Command
{
    protected $signature = 'platform:verify-local
                            {--repair : Run users:repair-platform before checks}
                            {--seed : Run db:seed before checks}';

    protected $description = 'Verify local DB, platform users, and login API before deploy';

    public function handle(): int
    {
        $this->info('Parrot platform — local pre-deploy verification');
        $this->newLine();

        if ($this->option('seed')) {
            $this->call('db:seed', ['--force' => true]);
        }

        if ($this->option('repair')) {
            $this->call('users:repair-platform');
        }

        $failed = false;

        try {
            DB::connection()->getPdo();
            $this->info('[OK] Database connection');
        } catch (\Throwable $e) {
            $this->error('[FAIL] Database: ' . $e->getMessage());
            $failed = true;
        }

        if (!Schema::hasTable('users')) {
            $this->error('[FAIL] users table missing — run php artisan migrate');
            $failed = true;
        } else {
            $this->info('[OK] users table exists');
        }

        $dupes = PlatformUserService::findNormalizedDuplicateGroups();
        if ($dupes !== []) {
            $this->error('[FAIL] Duplicate platform emails — run: php artisan users:repair-platform');
            foreach ($dupes as $row) {
                $this->line("  {$row['normalized_email']} ({$row['count']} rows)");
            }
            $failed = true;
        } else {
            $this->info('[OK] No duplicate platform emails');
        }

        $adminEmail = PlatformUserService::adminEmail();
        $password = PlatformUserService::defaultPassword();

        $this->newLine();
        $this->line('Default platform login (local + cPanel):');
        $this->line("  Email:    {$adminEmail}");
        $this->line("  Password: {$password}");
        $this->line('  Legacy aliases also work: info@xanderglobalscholars.com');

        $this->newLine();
        $this->line('Login API check:');

        foreach ([$adminEmail, 'info@xanderglobalscholars.com'] as $email) {
            $request = Request::create('/api/admin/auth/login', 'POST', [
                'username' => $email,
                'password' => $password,
            ]);

            $response = app(AuthController::class)->login($request);
            $status = $response->getStatusCode();
            $body = json_decode($response->getContent(), true);
            $message = (string) ($body['message'] ?? '');

            if ($status === 200 && str_contains(strtolower($message), 'successful')) {
                $this->info("[OK] {$email} → {$message}");
            } else {
                $this->error("[FAIL] {$email} → HTTP {$status}: {$message}");
                $failed = true;
            }
        }

        $this->newLine();
        if ($failed) {
            $this->error('Verification failed. Fix issues above before deploying.');
            $this->line('Try: php artisan platform:verify-local --seed --repair');

            return self::FAILURE;
        }

        $this->info('All checks passed — safe to deploy backend + rebuild frontend dist.');

        return self::SUCCESS;
    }
}
