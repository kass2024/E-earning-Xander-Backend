<?php

use App\Support\PlatformUserService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        PlatformUserService::dedupeDuplicateEmails();
        PlatformUserService::deleteLegacyEmails();

        try {
            PlatformUserService::ensureUniqueEmailIndex();
        } catch (\Throwable) {
            // Hosting may already have a differently named unique index.
        }
    }

    public function down(): void
    {
        // Non-destructive data repair — no rollback.
    }
};
