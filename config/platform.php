<?php

return [
    'contact_email' => env('PLATFORM_CONTACT_EMAIL', 'infos@xanderglobalscholars.com'),
    /** Default dashboard password — local dev and cPanel (override via SEED_PLATFORM_PASSWORD in .env). */
    'default_password' => 'Xander@2026',
    'seed_password' => trim(
        (string) env('SEED_PLATFORM_PASSWORD', 'Xander@2026'),
        " \t\n\r\0\x0B'\""
    ),
    'admin_name' => trim((string) env('PLATFORM_ADMIN_NAME', 'Xander Global Scholars Admin')),
    'certificate_prefix' => 'XGS',
    'admin_email' => strtolower(trim((string) env(
        'PLATFORM_ADMIN_EMAIL',
        'info@xanderglobalscholars.com'
    ))),
];
