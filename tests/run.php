<?php

declare(strict_types=1);

use PrivacyFirst\ServerLogAnalytics\Classifier;
use PrivacyFirst\ServerLogAnalytics\Config;
use PrivacyFirst\ServerLogAnalytics\Database;
use PrivacyFirst\ServerLogAnalytics\DashboardRepository;
use PrivacyFirst\ServerLogAnalytics\Importer;
use PrivacyFirst\ServerLogAnalytics\LoginLimiter;
use PrivacyFirst\ServerLogAnalytics\LogParser;
use PrivacyFirst\ServerLogAnalytics\RouteCatalog;

require dirname(__DIR__) . '/src/Analytics.php';

$assertions = 0;
function expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

/** @return array{exit_code: int, stdout: string, stderr: string} */
function runPhpScript(string $script): array
{
    $command = [PHP_BINARY];
    if (PHP_OS_FAMILY === 'Windows' && extension_loaded('pdo_sqlite')) {
        $extension = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'php_pdo_sqlite.dll';
        if (is_file($extension)) {
            $command[] = '-d';
            $command[] = 'extension=' . $extension;
        }
    }
    $command[] = $script;

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the PHP subprocess.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'privacy-log-analytics-test-' . bin2hex(random_bytes(6));
mkdir($testRoot, 0700, true);

try {
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo'));
    $dateToken = $today->format('d/M/Y');
    $fixture = str_replace('__DATE__', $dateToken, file_get_contents(__DIR__ . '/fixtures/access.log'));
    $logPath = $testRoot . '/access.log.gz';
    $gzip = gzopen($logPath, 'wb9');
    gzwrite($gzip, $fixture);
    gzclose($gzip);

    $manifestPath = $testRoot . '/routes.json';
    file_put_contents($manifestPath, json_encode([
        'version' => 1,
        'base' => '/example',
        'pages' => [
            ['path' => '/example/', 'label' => 'Example home'],
            ['path' => '/example/docs/', 'label' => 'Documentation'],
            ['path' => '/example/en/', 'label' => 'Example home EN'],
        ],
    ], JSON_THROW_ON_ERROR));

    $passwordHash = password_hash('test-password-is-long-enough', PASSWORD_DEFAULT);
    $configPath = $testRoot . '/config.php';
    $configSource = '<?php return ' . var_export([
        'timezone' => 'Asia/Tokyo',
        'site_prefix' => '/example',
        'db_path' => $testRoot . '/data/analytics.sqlite',
        'session_path' => $testRoot . '/sessions',
        'login_lock_path' => $testRoot . '/locks/login.json',
        'import_state_path' => $testRoot . '/state/import.json',
        'import_lock_path' => $testRoot . '/locks/import.lock',
        'route_manifest' => $manifestPath,
        'access_log_directory' => $testRoot . '/logs',
        'access_log_prefix' => 'fixture.access_log_',
        'password_hash' => $passwordHash,
        'internal_hosts' => ['example.test', 'www.example.test'],
        'referrer_domains' => ['google.com', 'github.com'],
        'retention_days' => 90,
        'retention_months' => 24,
        'max_parse_error_ratio' => 0.01,
        'max_referrer_domains_per_day' => 2,
        'journal_mode' => 'DELETE',
    ], true) . ';';
    file_put_contents($configPath, $configSource);

    $dailyRoot = $testRoot . '/daily-wrapper';
    mkdir($dailyRoot . '/bin', 0700, true);
    mkdir($dailyRoot . '/src', 0700, true);
    mkdir($dailyRoot . '/logs', 0700, true);
    copy(dirname(__DIR__) . '/bin/daily-import.php', $dailyRoot . '/bin/daily-import.php');
    copy(dirname(__DIR__) . '/bin/import.php', $dailyRoot . '/bin/import.php');
    copy(dirname(__DIR__) . '/src/Analytics.php', $dailyRoot . '/src/Analytics.php');
    copy($manifestPath, $dailyRoot . '/routes.json');
    $yesterday = $today->sub(new DateInterval('P1D'))->format('Ymd');
    copy($logPath, $dailyRoot . '/logs/fixture.access_log_' . $yesterday . '.gz');
    file_put_contents($dailyRoot . '/config.php', '<?php return ' . var_export([
        'timezone' => 'Asia/Tokyo',
        'site_prefix' => '/example',
        'db_path' => $dailyRoot . '/data/analytics.sqlite',
        'session_path' => $dailyRoot . '/sessions',
        'login_lock_path' => $dailyRoot . '/locks/login.json',
        'import_state_path' => $dailyRoot . '/state/import.json',
        'import_lock_path' => $dailyRoot . '/locks/import.lock',
        'route_manifest' => $dailyRoot . '/routes.json',
        'access_log_directory' => $dailyRoot . '/logs',
        'access_log_prefix' => 'fixture.access_log_',
        'password_hash' => $passwordHash,
        'internal_hosts' => ['example.test', 'www.example.test'],
        'referrer_domains' => ['google.com', 'github.com'],
        'retention_days' => 90,
        'retention_months' => 24,
        'max_parse_error_ratio' => 0.01,
        'max_referrer_domains_per_day' => 2,
        'journal_mode' => 'DELETE',
    ], true) . ';');
    $dailyRun = runPhpScript($dailyRoot . '/bin/daily-import.php');
    expect($dailyRun['exit_code'] === 0, 'daily Cron entry point runs successfully: ' . trim($dailyRun['stderr'] . "\n" . $dailyRun['stdout']));
    $dailyOutput = json_decode(trim($dailyRun['stdout']), true, 512, JSON_THROW_ON_ERROR);
    expect(($dailyOutput['status'] ?? null) === 'ok' && ($dailyOutput['skipped'] ?? true) === false, 'daily Cron entry point imports the prepared log');

    $config = Config::load($configPath);
    $documentRoot = $testRoot . '/public_html';
    mkdir($documentRoot, 0700, true);
    $config->assertPrivatePathsOutside($documentRoot);
    expect(true, 'configured private paths are accepted outside DocumentRoot');
    try {
        $config->assertPrivatePathsOutside($testRoot);
        expect(false, 'private paths nested under DocumentRoot are rejected');
    } catch (RuntimeException $error) {
        expect(str_contains($error->getMessage(), 'outside DocumentRoot'), 'DocumentRoot containment failure is explicit');
    }
    $routes = RouteCatalog::load($manifestPath, '/example');
    $rootManifestPath = $testRoot . '/root-routes.json';
    file_put_contents($rootManifestPath, json_encode([
        'version' => 1,
        'base' => '/',
        'pages' => [['path' => '/about/', 'label' => 'About']],
    ], JSON_THROW_ON_ERROR));
    expect(RouteCatalog::load($rootManifestPath, '/')->contains('/about/'), 'root site prefix accepts routes beneath /');
    $database = new Database($config);
    $importer = new Importer($config, new LogParser(new DateTimeZone('Asia/Tokyo')), new Classifier($config->all(), $routes), $database);
    $result = $importer->import($logPath);

    expect(!$result->skipped, 'first gzip import runs');
    expect($result->parsedRows === 10, 'combined and vhost log records parse');
    expect($result->siteRows === 9, 'other site paths are excluded before aggregation');
    expect($result->pageviews === 3, 'only known non-bot GET pages with 2xx/3xx count as PV');

    $repo = new DashboardRepository($database->pdo());
    $day = $today->format('Y-m-d');
    $totals = $repo->totals($day, $day);
    expect($totals === ['requests' => 8, 'pageviews' => 3, 'not_found' => 2, 'bot_requests' => 1], 'metrics separate requests, PV, 404, and bots');
    $monthly = $repo->monthlySeries($today->format('Y-m'), $today->format('Y-m'));
    expect($monthly === [['period' => $today->format('Y-m'), 'pageviews' => 3, 'not_found' => 2, 'bot_requests' => 1]], 'monthly dashboard series uses the privacy-preserving monthly aggregate');

    $pages = $repo->topDimension('page', $day, $day);
    expect(count($pages) === 3, 'known pages are aggregated');
    try {
        $repo->topDimension("page' OR 1=1 --", $day, $day);
        expect(false, 'dashboard dimension SQL injection is rejected');
    } catch (RuntimeException $error) {
        expect(str_contains($error->getMessage(), 'Unsupported dashboard dimension'), 'dashboard dimensions are allowlisted');
    }
    $refs = $repo->topDimension('referrer', $day, $day);
    $refValues = array_column($refs, 'value');
    expect(in_array('external:google.com', $refValues, true), 'external referrer is reduced to a domain');
    expect(in_array('external:github.com', $refValues, true), 'a second external domain is retained');

    $allRows = '';
    foreach (['imports', 'daily_metrics', 'monthly_metrics', 'daily_dimensions', 'monthly_dimensions'] as $table) {
        $allRows .= json_encode($database->pdo()->query("SELECT * FROM {$table}")->fetchAll(), JSON_THROW_ON_ERROR);
    }
    foreach (['192.0.2.', 'secret@example.test', 'token=do-not-store', 'Sensitive-Agent', '/not-a-real-page/', 'missing-private-name.png', '/path?secret=', '/search?q=private'] as $forbidden) {
        expect(!str_contains($allRows, $forbidden), "database omits sensitive raw value {$forbidden}");
    }

    $repeat = $importer->import($logPath);
    expect($repeat->skipped, 'same file name and hash is idempotently skipped');
    expect($repo->totals($day, $day) === $totals, 'duplicate import does not change totals');

    $manyReferrers = $testRoot . '/many-referrers.log';
    file_put_contents($manyReferrers,
        "192.0.2.31 - - [{$dateToken}:01:00:00 +0900] \"GET /example/ HTTP/1.1\" 200 1 \"https://visitor-123.attacker.example/path\" \"Mozilla/5.0 (Windows NT 10.0) Chrome/140.0\"\n" .
        "192.0.2.32 - - [{$dateToken}:01:01:00 +0900] \"GET /example/ HTTP/1.1\" 200 1 \"https://second.example/path\" \"Mozilla/5.0 (Windows NT 10.0) Chrome/140.0\"\n" .
        "192.0.2.33 - - [{$dateToken}:01:02:00 +0900] \"GET /example/ HTTP/1.1\" 200 1 \"https://third.example/path\" \"Mozilla/5.0 (Windows NT 10.0) Chrome/140.0\"\n"
    );
    $importer->import($manyReferrers);
    $refValues = array_column($repo->topDimension('referrer', $day, $day), 'value');
    expect(in_array('external:other', $refValues, true), 'referrer-domain cap aggregates additional domains while importing');
    $refRows = json_encode($repo->topDimension('referrer', $day, $day), JSON_THROW_ON_ERROR);
    expect(!str_contains($refRows, 'visitor-123'), 'unapproved referrer subdomains cannot persist identifiers');

    $collisionDir = $testRoot . '/collision';
    mkdir($collisionDir);
    copy($logPath, $collisionDir . '/access.log.gz');
    file_put_contents($collisionDir . '/access.log.gz', 'different');
    try {
        $importer->import($collisionDir . '/access.log.gz');
        expect(false, 'changed content under imported name is rejected');
    } catch (RuntimeException $error) {
        expect(str_contains($error->getMessage(), 'different content'), 'file-name/hash collision is explicit');
    }

    $badLog = $testRoot . '/bad.log';
    file_put_contents($badLog, "malformed\n" . explode("\n", trim($fixture))[0] . "\n");
    try {
        $importer->import($badLog);
        expect(false, 'high parse-error ratio is rejected');
    } catch (RuntimeException $error) {
        expect(str_contains($error->getMessage(), 'parse-error threshold'), 'format mismatch fails closed');
    }

    $limiter = new LoginLimiter($testRoot . '/auth/login.json', 900, 3, 1800);
    expect($limiter->check()['allowed'], 'login is initially allowed');
    $limiter->recordFailure();
    $limiter->recordFailure();
    $limiter->recordFailure();
    expect(!$limiter->check()['allowed'], 'account-level login limiter locks after threshold');
    $limiter->reset();
    expect($limiter->check()['allowed'], 'successful login reset clears the lock');

    $oldDay = $today->sub(new DateInterval('P100D'))->format('d/M/Y');
    $ancientDay = $today->sub(new DateInterval('P800D'))->format('d/M/Y');
    $retentionLog = $testRoot . '/retention.log';
    file_put_contents($retentionLog,
        "192.0.2.20 - - [{$oldDay}:00:00:00 +0900] \"GET /example/ HTTP/1.1\" 200 1 \"-\" \"Mozilla/5.0 (Windows NT 10.0) Chrome/140.0\"\n" .
        "192.0.2.21 - - [{$ancientDay}:00:00:00 +0900] \"GET /example/ HTTP/1.1\" 200 1 \"-\" \"Mozilla/5.0 (Windows NT 10.0) Chrome/140.0\"\n"
    );
    $importer->import($retentionLog);
    expect((int) $database->pdo()->query("SELECT COUNT(*) FROM daily_metrics WHERE period < date('now', '-89 days')")->fetchColumn() === 0, 'daily aggregates older than 90 days are removed');
    $monthlyCutoff = $today->modify('first day of this month')->sub(new DateInterval('P23M'))->format('Y-m');
    $statement = $database->pdo()->prepare('SELECT COUNT(*) FROM monthly_metrics WHERE period < :cutoff');
    $statement->execute(['cutoff' => $monthlyCutoff]);
    expect((int) $statement->fetchColumn() === 0, 'monthly aggregates older than 24 months are removed');

    fwrite(STDOUT, "PASS: {$assertions} assertions\n");
} finally {
    unset($statement, $repo, $importer, $database, $config);
    gc_collect_cycles();
    removeTree($testRoot);
}
