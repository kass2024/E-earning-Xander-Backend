<?php

namespace Database\Seeders;

use App\Models\MeetSubscriptionPlan;
use App\Services\Meet\MeetCreditCalculator;
use Illuminate\Database\Seeder;

class MeetSubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $calc = new MeetCreditCalculator();

        $tiers = [
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'description' => 'Perfect for small teams and solo hosts running weekly meetings.',
                'max_participants' => 25,
                'storage_mb' => 10240,
                'monthly_credits' => 5000,
                'price_usd_cents' => 2900,
                'price_rwf' => 35000,
                'sort_order' => 1,
                'features' => [
                    'Up to 25 participants per meeting',
                    '10 GB recording storage',
                    '~3 hours of HD meetings/month',
                    'Daily.co powered video rooms',
                    'Meeting registrations & scheduling',
                    'Email support',
                ],
            ],
            [
                'slug' => 'professional',
                'name' => 'Professional',
                'description' => 'For growing organizations hosting regular webinars and team meetings.',
                'max_participants' => 100,
                'storage_mb' => 51200,
                'monthly_credits' => 25000,
                'price_usd_cents' => 7900,
                'price_rwf' => 95000,
                'sort_order' => 2,
                'features' => [
                    'Up to 100 participants per meeting',
                    '50 GB recording storage',
                    '~4 hours of HD meetings/day',
                    'Webinars & live cohorts',
                    'Q&A, polls & breakouts',
                    'Priority support',
                ],
            ],
            [
                'slug' => 'business',
                'name' => 'Business',
                'description' => 'Enterprise-grade hosting for large webinars and multi-room events.',
                'max_participants' => 500,
                'storage_mb' => 204800,
                'monthly_credits' => 100000,
                'price_usd_cents' => 19900,
                'price_rwf' => 240000,
                'sort_order' => 3,
                'features' => [
                    'Up to 500 participants per meeting',
                    '200 GB recording storage',
                    'Unlimited meeting rooms',
                    'White-label tenant portal',
                    'Advanced moderation & stage control',
                    'Dedicated account manager',
                ],
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Custom scale for organizations with high-volume meeting needs.',
                'max_participants' => 1000,
                'storage_mb' => 512000,
                'monthly_credits' => 500000,
                'price_usd_cents' => 49900,
                'price_rwf' => 600000,
                'sort_order' => 4,
                'features' => [
                    'Up to 1,000 participants per meeting',
                    '500 GB recording storage',
                    'Custom branding & domain',
                    'SLA & uptime guarantee',
                    'API access & webhooks',
                    '24/7 phone support',
                ],
            ],
        ];

        foreach ($tiers as $tier) {
            MeetSubscriptionPlan::updateOrCreate(
                ['slug' => $tier['slug']],
                $tier,
            );
        }
    }
}
