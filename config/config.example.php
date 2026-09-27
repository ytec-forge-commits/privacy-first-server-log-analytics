<?php

declare(strict_types=1);

return [
    'timezone' => 'Asia/Tokyo',
    // Set to 'ja' or 'en'. The dashboard has no visitor-facing tracking code.
    'locale' => 'ja',
    'site_prefix' => '/your-site',
    // Every path below must be absolute and outside the web server DocumentRoot.
    'db_path' => '/home/ACCOUNT/private-analytics/data/analytics.sqlite',
    'session_path' => '/home/ACCOUNT/private-analytics/sessions',
    'login_lock_path' => '/home/ACCOUNT/private-analytics/locks/login.json',
    'import_state_path' => '/home/ACCOUNT/private-analytics/state/import.json',
    'import_lock_path' => '/home/ACCOUNT/private-analytics/locks/import.lock',
    'route_manifest' => '/home/ACCOUNT/private-analytics/routes.json',
    'access_log_directory' => '/home/ACCOUNT/DOMAIN/log',
    'access_log_prefix' => 'DOMAIN.access_log_',
    // Generate with: php bin/hash-password.php < password.txt
    'password_hash' => 'REPLACE_WITH_PASSWORD_HASH',
    'internal_hosts' => ['example.com', 'www.example.com'],
    // Store only these explicit external domains; all others become external:other.
    'referrer_domains' => ['google.com', 'github.com'],
    // The public URL path of public/admin/ (must end with a slash).
    'admin_cookie_path' => '/your-site/admin/',
    'retention_days' => 90,
    'retention_months' => 24,
    'stale_after_hours' => 36,
    'session_ttl_seconds' => 3600,
    'login_window_seconds' => 900,
    'login_max_failures' => 5,
    'login_lock_seconds' => 1800,
    'max_database_bytes' => 67108864,
    'max_parse_error_ratio' => 0.01,
    'max_log_lines' => 250000,
    'max_log_uncompressed_bytes' => 33554432,
    'max_import_seconds' => 120,
    'max_referrer_domains_per_day' => 100,
    'journal_mode' => 'WAL',
];
