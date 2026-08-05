<?php

namespace Database\Seeders;

use App\Support\PlatformUserService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        PlatformUserService::dedupeDuplicateEmails();
        PlatformUserService::deleteLegacyEmails();

        $this->call([
            MeetSubscriptionPlanSeeder::class,
            MeetBootstrapSeeder::class,
            AvailableScheduleSeeder::class,
            PlatformInstitutionSeeder::class,
        ]);
    }
}
