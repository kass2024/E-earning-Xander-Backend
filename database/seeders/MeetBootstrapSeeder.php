<?php

namespace Database\Seeders;

use App\Models\MeetSubscription;
use App\Models\MeetSubscriptionPlan;
use App\Models\User;
use App\Services\Meet\MeetUsageService;
use App\Support\PlatformUserService;
use Illuminate\Database\Seeder;

/**
 * Xander Meet production bootstrap: platform users, roles, and an active admin subscription.
 */
class MeetBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $password = PlatformUserService::seedPassword();

        PlatformUserService::dedupeDuplicateEmails();
        PlatformUserService::deleteLegacyEmails();

        $admin = PlatformUserService::ensureAdminFromEnv($password);
        $admin->forceFill(['name' => 'Xander Meet Admin'])->save();

        $this->seedUser('staff@xanderglobalscholars.com', 'Xander Meet Staff', 'staff', $password);
        $this->seedUser('coordinator@xandertech.llc', 'Meeting Coordinator', 'meeting_user', $password);
        $this->seedUser('emmanuel@xanderglobalscholars.com', 'Emmanuel Niyonzima', 'staff', $password);

        $this->seedAdminSubscription($admin);
    }

    private function seedUser(string $email, string $name, string $role, string $password): User
    {
        $user = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($email))])->first();

        if (!$user) {
            return User::create([
                'email' => $email,
                'name' => $name,
                'password' => $password,
                'role' => $role,
                'status' => 'Active',
            ]);
        }

        $user->fill(['name' => $name, 'role' => $role, 'status' => 'Active']);
        $user->password = $password;
        $user->save();

        return $user;
    }

    private function seedAdminSubscription(User $admin): void
    {
        $plan = MeetSubscriptionPlan::query()->where('slug', 'professional')->first()
            ?? MeetSubscriptionPlan::query()->orderBy('sort_order')->first();

        if (!$plan) {
            return;
        }

        $existing = MeetSubscription::query()
            ->where('user_id', $admin->id)
            ->where('status', 'active')
            ->where('current_period_end', '>', now())
            ->first();

        if ($existing) {
            return;
        }

        $sub = MeetSubscription::create([
            'user_id' => $admin->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_provider' => 'seed',
            'current_period_start' => now(),
            'current_period_end' => now()->addYear(),
            'metadata' => ['seed' => true, 'note' => 'Platform admin complimentary subscription'],
        ]);

        app(MeetUsageService::class)->allocatePeriodCredits($sub);
    }
}
