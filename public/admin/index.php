<?php

declare(strict_types=1);

define('PRIVACY_LOG_ANALYTICS_ENTRY', true);

$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
$configuredRoot = getenv('PRIVACY_LOG_ANALYTICS_PRIVATE_ROOT');
$privateRoot = is_string($configuredRoot) && $configuredRoot !== ''
    ? $configuredRoot
    : dirname($documentRoot) . DIRECTORY_SEPARATOR . 'privacy-log-analytics';

try {
    require $privateRoot . DIRECTORY_SEPARATOR . 'app.php';
} catch (Throwable $error) {
    error_log('Privacy-first Server Log Analytics bootstrap failed: ' . $error->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Analytics is temporarily unavailable.';
}
