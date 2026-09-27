<?php

declare(strict_types=1);

use PrivacyFirst\ServerLogAnalytics\Config;
use PrivacyFirst\ServerLogAnalytics\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/Analytics.php';

$configPath = dirname(__DIR__) . '/config.php';
$output = null;
$documentRoot = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--config=')) $configPath = substr($argument, 9);
    if (str_starts_with($argument, '--output=')) $output = substr($argument, 9);
    if (str_starts_with($argument, '--document-root=')) $documentRoot = substr($argument, 16);
}

try {
    if (!is_string($output) || $output === '' || (!str_starts_with($output, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $output) !== 1)) {
        throw new RuntimeException('--output must be an absolute path.');
    }
    if (!is_string($documentRoot) || $documentRoot === '' || !is_dir($documentRoot)) {
        throw new RuntimeException('--document-root must name the actual DocumentRoot.');
    }
    if (file_exists($output)) {
        throw new RuntimeException('Snapshot output already exists.');
    }
    $config = Config::load($configPath);
    $config->assertPrivatePathsOutside($documentRoot);
    Config::assertPathOutsideDocumentRoot($output, $documentRoot, 'Snapshot output must be outside DocumentRoot.');
    $dbPath = (string) $config->get('db_path');
    if (strcasecmp($dbPath, $output) === 0) {
        throw new RuntimeException('Snapshot output must differ from the live database.');
    }
    Database::ensurePrivateDirectory(dirname($output));
    $database = new Database($config);
    $database->pdo()->exec('VACUUM INTO ' . $database->pdo()->quote($output));
    @chmod($output, 0600);
    fwrite(STDOUT, json_encode([
        'status' => 'ok',
        'bytes' => filesize($output),
        'sha256' => hash_file('sha256', $output),
        'action_required' => 'DOWNLOAD_ENCRYPT_VERIFY_THEN_DELETE_SERVER_SNAPSHOT',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Snapshot failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
