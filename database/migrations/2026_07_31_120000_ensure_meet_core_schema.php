<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent repair for Xander Meet deployments — ensures login-critical columns exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'role')) {
                    $table->string('role')->default('admin')->after('password');
                }
                if (!Schema::hasColumn('users', 'status')) {
                    $table->string('status')->default('Active')->after('role');
                }
                if (!Schema::hasColumn('users', 'phone')) {
                    $table->string('phone')->nullable()->after('status');
                }
                if (!Schema::hasColumn('users', 'avatar')) {
                    $table->string('avatar')->nullable()->after('email');
                }
                if (!Schema::hasColumn('users', 'platform_institution_id')) {
                    $table->unsignedBigInteger('platform_institution_id')->nullable()->after('role');
                }
                if (!Schema::hasColumn('users', 'zoom_host_user_id')) {
                    $table->string('zoom_host_user_id', 255)->nullable()->after('platform_institution_id');
                }
            });
        }

        if (!Schema::hasTable('platform_institutions')) {
            Schema::create('platform_institutions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('contact_email')->nullable();
                $table->string('status')->default('active');
                $table->string('payment_status')->default('unpaid');
                $table->unsignedBigInteger('owner_user_id')->nullable();
                $table->boolean('mail_use_custom')->default(false);
                $table->string('mail_host')->nullable();
                $table->string('portal_tagline')->nullable();
                $table->string('portal_hero_title')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Repair migration — no rollback.
    }
};
