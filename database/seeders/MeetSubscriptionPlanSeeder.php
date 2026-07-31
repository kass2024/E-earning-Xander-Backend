<?php

namespace Database\Seeders;

use App\Models\MeetSubscriptionPlan;
use App\Services\Meet\MeetCreditCalculator;
use Illuminate\Database\Seeder;

class MeetSubscriptionPlanSeeder extends Seeder
{
    /** USD → RWF rate for MoPay collections. */
    private const RWF_PER_USD = 1300;

    public function run(): void
    {
        $calc = new MeetCreditCalculator();

        $tiers = [
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'description' => 'For solo hosts and small teams running weekly standups and client calls.',
                'max_participants' => 25,
                'storage_mb' => 10240,
                'avg_participants' => 8,
                'meetings_per_month' => 12,
                'price_usd_cents' => 2900,
                'price_rwf' => 35000,
                'sort_order' => 1,
                'features' => [
                    'Up to 25 participants per meeting',
                    '10 GB cloud recording storage',
                    '~12 hours of HD video/month',
                    'Daily.co powered rooms + scheduling',
                    'Meeting registrations',
                    'Email support',
                ],
            ],
            [
                'slug' => 'professional',
                'name' => 'Professional',
                'description' => 'For organizations hosting regular webinars, cohorts, and team meetings.',
                'max_participants' => 100,
                'storage_mb' => 51200,
                'avg_participants' => 35,
                'meetings_per_month' => 30,
                'price_usd_cents' => 7900,
                'price_rwf' => 95000,
                'sort_order' => 2,
                'features' => [
                    'Up to 100 participants per meeting',
                    '50 GB cloud recording storage',
                    '~60 hours of HD video/month',
                    'Webinars, Q&A, polls & breakouts',
                    'Usage dashboard & credit alerts',
                    'Priority support',
                ],
            ],
            [
                'slug' => 'business',
                'name' => 'Business',
                'description' => 'Large webinars, multi-room events, and white-label tenant portals.',
                'max_participants' => 500,
                'storage_mb' => 204800,
                'avg_participants' => 80,
                'meetings_per_month' => 60,
                'price_usd_cents' => 19900,
                'price_rwf' => 240000,
                'sort_order' => 3,
                'features' => [
                    'Up to 500 participants per meeting',
                    '200 GB cloud recording storage',
                    '~300 hours of HD video/month',
                    'Multi-tenant partner portals',
                    'Advanced moderation & stage control',
                    'Dedicated account manager',
                ],
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Custom scale for high-volume broadcast and enterprise compliance needs.',
                'max_participants' => 1000,
                'storage_mb' => 512000,
                'avg_participants' => 150,
                'meetings_per_month' => 120,
                'price_usd_cents' => 49900,
                'price_rwf' => 600000,
                'sort_order' => 4,
                'features' => [
                    'Up to 1,000 participants per meeting',
                    '500 GB cloud recording storage',
                    'Custom branding & domain',
                    'SLA & uptime guarantee',
                    'API access & webhooks',
                    '24/7 phone support',
                ],
            ],
        ];

        foreach ($tiers as $tier) {
            $monthlyCredits = $calc->participantMinutesToCredits(
                $tier['avg_participants'],
                60,
            ) * $tier['meetings_per_month'];

            MeetSubscriptionPlan::updateOrCreate(
                ['slug' => $tier['slug']],
                [
                    'name' => $tier['name'],
                    'description' => $tier['description'],
                    'max_participants' => $tier['max_participants'],
                    'storage_mb' => $tier['storage_mb'],
                    'monthly_credits' => $monthlyCredits,
                    'price_usd_cents' => $tier['price_usd_cents'],
                    'price_rwf' => $tier['price_rwf'],
                    'is_active' => true,
                    'sort_order' => $tier['sort_order'],
                    'features' => $tier['features'],
                ],
            );
        }
    }
}
