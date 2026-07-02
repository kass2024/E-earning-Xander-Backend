<?php

return [
    'contact_email' => env('PLATFORM_CONTACT_EMAIL', 'infos@parrotglobalstudyacademy.ca'),
    /** Default dashboard password — local dev and cPanel (override via SEED_PLATFORM_PASSWORD in .env). */
    'default_password' => 'Parrot@2025',
    'seed_password' => trim(
        (string) env('SEED_PLATFORM_PASSWORD', 'Parrot@2025'),
        " \t\n\r\0\x0B'\""
    ),
    'certificate_prefix' => 'PGS',
    'admin_email' => 'infos@parrotglobalstudyacademy.ca',
];
