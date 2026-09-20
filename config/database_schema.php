<?php

/**
 * Expected database schema for xanderglobalscholars.
 * Used by DatabaseSchemaService to detect incomplete deployments and trigger auto-migrate.
 * When adding a migration, append the table/columns here (or add to sync_xander_hub_schema migration).
 */
return [
    'users' => ['id', 'name', 'email', 'password', 'role', 'status', 'phone', 'platform_institution_id', 'zoom_host_user_id'],
    'students' => ['id', 'email', 'first_name', 'last_name', 'status', 'password', 'country', 'phone', 'primary_goal', 'platform_institution_id'],
    'courses' => ['id', 'title', 'status', 'price', 'course_code', 'program_id', 'platform_institution_id'],
    'elearning_programs' => ['id', 'name', 'status', 'sort_order', 'platform_institution_id'],
    'course_enrollments' => ['id', 'student_id', 'course_id', 'status', 'level', 'study_shift_id'],
    'course_payments' => ['id', 'course_id', 'student_id', 'amount_cents', 'status', 'provider', 'platform_institution_id'],
    'platform_institutions' => [
        'id', 'name', 'slug', 'contact_email', 'status', 'payment_status', 'owner_user_id',
        'mail_use_custom', 'mail_host', 'portal_tagline', 'portal_hero_title',
    ],
    'institution_promo_codes' => ['id', 'code', 'max_uses', 'uses_count', 'is_active'],
    'institution_payments' => ['id', 'platform_institution_id', 'amount_cents', 'status'],
    'assign_cours' => ['user_id', 'course_id'],
    'meeting_registrations' => ['id', 'email', 'status'],
    'available_schedules' => ['id', 'available_on_date'],
    'livezoom_cohort' => ['id', 'available_on_date', 'platform_institution_id'],
    'livezoom_cohort_queue_entries' => ['id'],
    'instructor_payout_requests' => ['id', 'instructor_id', 'amount', 'status', 'payment_method'],
    'webinar_settings' => ['id'],
    'site_settings' => ['id', 'promo_banner_published', 'star_banner_published'],
    'study_shifts' => ['id', 'name', 'day_of_week', 'start_time', 'end_time', 'is_active', 'platform_institution_id'],
    'course_enrollment_study_shifts' => ['id', 'course_enrollment_id', 'study_shift_id'],
    'study_shift_change_requests' => ['id', 'course_enrollment_id', 'student_id', 'course_id', 'status'],
    'course_materials' => ['id', 'course_id'],
    'quiz_attempts' => ['id'],
    'meet_subscription_plans' => ['id', 'slug', 'name', 'max_participants', 'monthly_credits', 'price_usd_cents', 'price_rwf', 'is_active'],
    'meet_subscriptions' => ['id', 'plan_id', 'status', 'billing_provider'],
    'meet_usage_credits' => ['id', 'subscription_id', 'credits_allocated', 'credits_used'],
    'meet_subscription_payments' => ['id', 'subscription_id', 'plan_id', 'status', 'provider'],
    'meet_subscription_promo_codes' => ['id', 'code', 'max_uses', 'uses_count', 'is_active', 'plan_id'],
];
