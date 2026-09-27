<?php

declare(strict_types=1);

use PrivacyFirst\ServerLogAnalytics\Classifier;
use PrivacyFirst\ServerLogAnalytics\Config;
use PrivacyFirst\ServerLogAnalytics\Database;
use PrivacyFirst\ServerLogAnalytics\Importer;
use PrivacyFirst\ServerLogAnalytics\ImportState;
use PrivacyFirst\ServerLogAnalytics\LogParser;
use PrivacyFirst\ServerLogAnalytics\RouteCatalog;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/Analytics.php';

final class ImportBusyException extends RuntimeException
{
}

$configPath = dirname(__DIR__) . '/config.php';
$inputs = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--config=')) {
        $configPath = substr($argument, 9);
    } elseif (!str_starts_with($argument, '--')) {
        $inputs[] = $argument;
    }
}

if (count($inputs) !== 1) {
    fwrite(STDERR, "Usage: php import.php [--config=/absolute/config.php] /absolute/access.log.gz\n");
    exit(2);
}

$state = null;
$lockHandle = null;
$stage = 'config';
try {
    $config = Config::load($configPath);
    $lockPath = (string) $config->get('import_lock_path');
    Database::ensurePrivateDirectory(dirname($lockPath));
    $lockHandle = fopen($lockPath, 'c');
    if ($lockHandle === false) {
        throw new RuntimeException('The import lock could not be opened.');
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new ImportBusyException('An import is already in progress.');
    }
    $stage = 'state';
    $state = new ImportState((string) $config->get('import_state_path'));
    $stage = 'routes';
    $routes = RouteCatalog::load((string) $config->get('route_manifest'), (string) $config->get('site_prefix'));
    $timezone = new DateTimeZone((string) $config->get('timezone'));
    $stage = 'database';
    $database = new Database($config);
    $stage = 'import';
    $importer = new Importer($config, new LogParser($timezone), new Classifier($config->all(), $routes), $database);
    $result = $importer->import($inputs[0]);
    $state->write('ok', [
        'skipped' => $result->skipped,
        'parsed_rows' => $result->parsedRows,
        'site_rows' => $result->siteRows,
        'pageviews' => $result->pageviews,
    ]);
    fwrite(STDOUT, json_encode([
        'status' => 'ok',
        'skipped' => $result->skipped,
        'parsed_rows' => $result->parsedRows,
        'site_rows' => $result->siteRows,
        'pageviews' => $result->pageviews,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (ImportBusyException) {
    fwrite(STDOUT, "{\"status\":\"busy\"}\n");
} catch (Throwable $error) {
    if ($state instanceof ImportState) {
        try {
            $state->write('error', ['reason' => 'import_failed', 'stage' => $stage]);
        } catch (Throwable) {
        }
    }
    fwrite(STDERR, 'Import failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
