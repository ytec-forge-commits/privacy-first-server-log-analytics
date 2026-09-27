<?php

declare(strict_types=1);

use PrivacyFirst\ServerLogAnalytics\Config;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/Analytics.php';

try {
    $config = Config::load(dirname(__DIR__) . '/config.php');
    $timezone = new DateTimeZone((string) $config->get('timezone'));
    $date = (new DateTimeImmutable('yesterday', $timezone))->format('Ymd');
    $directory = rtrim((string) $config->get('access_log_directory'), '/');
    $prefix = (string) $config->get('access_log_prefix');
    if (preg_match('/^[A-Za-z0-9._-]+$/', $prefix) !== 1) {
        throw new RuntimeException('The access log prefix is invalid.');
    }
    $logPath = $directory . '/' . $prefix . $date . '.gz';
    if (!is_file($logPath) || !is_readable($logPath)) {
        throw new RuntimeException('The daily access log is not ready.');
    }
    $argv = [__FILE__, $logPath];
    require __DIR__ . '/import.php';
} catch (Throwable $error) {
    fwrite(STDERR, 'Daily import failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
