<?php

declare(strict_types=1);

use PrivacyFirst\ServerLogAnalytics\Config;
use PrivacyFirst\ServerLogAnalytics\LogParser;
use PrivacyFirst\ServerLogAnalytics\RouteCatalog;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/Analytics.php';

$configPath = dirname(__DIR__) . '/config.php';
$documentRoot = null;
$logPath = null;
$reportPath = null;
$arguments = array_slice($argv, 1);
for ($index = 0; $index < count($arguments); $index++) {
    $argument = $arguments[$index];
    if (str_starts_with($argument, '--config=')) {
        $configPath = substr($argument, 9);
    } elseif ($argument === '--config' && isset($arguments[$index + 1])) {
        $configPath = $arguments[++$index];
    } elseif (str_starts_with($argument, '--document-root=')) {
        $documentRoot = substr($argument, 16);
    } elseif ($argument === '--document-root' && isset($arguments[$index + 1])) {
        $documentRoot = $arguments[++$index];
    } elseif (str_starts_with($argument, '--log=')) {
        $logPath = substr($argument, 6);
    } elseif ($argument === '--log' && isset($arguments[$index + 1])) {
        $logPath = $arguments[++$index];
    } elseif (str_starts_with($argument, '--report=')) {
        $reportPath = substr($argument, 9);
    } elseif ($argument === '--report' && isset($arguments[$index + 1])) {
        $reportPath = $arguments[++$index];
    }
}

$stage = 'load_config';
try {
    $config = Config::load($configPath);
    $stage = 'document_root';
    if ($documentRoot === null || !is_dir($documentRoot)) {
        throw new RuntimeException('--document-root must name the actual DocumentRoot.');
    }
    $config->assertPrivatePathsOutside($documentRoot);
    $stage = 'route_manifest';
    RouteCatalog::load((string) $config->get('route_manifest'), (string) $config->get('site_prefix'));

    $stage = 'private_directories';
    foreach (['db_path', 'session_path', 'login_lock_path', 'import_state_path', 'import_lock_path'] as $key) {
        $parent = dirname((string) $config->get($key));
        if (!is_dir($parent) || !is_writable($parent)) {
            throw new RuntimeException("Parent directory for {$key} is not present and writable.");
        }
    }

    // Verify that this account can create, write, and remove a SQLite database
    // in the configured private directory without leaving a production database
    // or any request data behind.
    $stage = 'sqlite_private_write';
    $databaseProbe = (string) $config->get('db_path') . '.preflight-' . bin2hex(random_bytes(8));
    $databaseCheck = ['checked' => false, 'created' => false, 'written' => false, 'removed' => false];
    try {
        $probe = new PDO('sqlite:' . $databaseProbe, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $databaseCheck['created'] = is_file($databaseProbe);
        $probe->exec('CREATE TABLE preflight_probe (value INTEGER NOT NULL)');
        $probe->exec('INSERT INTO preflight_probe (value) VALUES (1)');
        $databaseCheck['written'] = ((int) $probe->query('SELECT value FROM preflight_probe')->fetchColumn()) === 1;
        $databaseCheck['checked'] = true;
    } finally {
        unset($probe);
        foreach ([$databaseProbe, $databaseProbe . '-journal', $databaseProbe . '-wal', $databaseProbe . '-shm'] as $probeFile) {
            if (is_file($probeFile) && !unlink($probeFile)) {
                throw new RuntimeException('The temporary SQLite preflight probe could not be removed.');
            }
        }
        $databaseCheck['removed'] = !is_file($databaseProbe)
            && !is_file($databaseProbe . '-journal')
            && !is_file($databaseProbe . '-wal')
            && !is_file($databaseProbe . '-shm');
    }
    if (!$databaseCheck['checked'] || !$databaseCheck['created'] || !$databaseCheck['written'] || !$databaseCheck['removed']) {
        throw new RuntimeException('SQLite create/write/remove preflight did not complete.');
    }

    $stage = 'sample_log';
    $sample = ['checked' => false, 'parsed' => 0, 'lines' => 0];
    if ($logPath !== null) {
        if (!is_file($logPath) || !is_readable($logPath)) {
            throw new RuntimeException('The sample access log is not readable.');
        }
        $gzip = str_ends_with(strtolower($logPath), '.gz');
        $handle = $gzip ? gzopen($logPath, 'rb') : fopen($logPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The sample access log could not be opened.');
        }
        $parser = new LogParser(new DateTimeZone((string) $config->get('timezone')));
        try {
            while ($sample['lines'] < 200 && ($line = $gzip ? gzgets($handle) : fgets($handle)) !== false) {
                $sample['lines']++;
                if ($parser->parse($line) !== null) {
                    $sample['parsed']++;
                }
            }
        } finally {
            $gzip ? gzclose($handle) : fclose($handle);
        }
        $sample['checked'] = true;
        if ($sample['lines'] === 0 || $sample['parsed'] / $sample['lines'] < 0.99) {
            throw new RuntimeException('The sample log format did not meet the 99% parse threshold.');
        }
    }

    $result = [
        'status' => 'pass',
        'php_version' => PHP_VERSION,
        'pdo_sqlite' => true,
        'sqlite_private_write' => $databaseCheck,
        'private_paths_outside_document_root' => true,
        'route_manifest' => true,
        'sample_log' => $sample,
        'http_reachability' => 'NOT_CHECKED_RUN_EXTERNAL_CURL',
        'cron' => 'NOT_CHECKED_VERIFY_IN_SERVER_PANEL',
    ];
    $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    fwrite(STDOUT, $encoded);
    if ($reportPath !== null) {
        if (file_put_contents($reportPath, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('The requested preflight report could not be written.');
        }
    }
} catch (Throwable $error) {
    if ($reportPath !== null) {
        // Do not persist exception text: it may contain a filesystem detail.
        file_put_contents($reportPath, json_encode([
            'status' => 'fail',
            'stage' => $stage,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    }
    fwrite(STDERR, 'Preflight failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
