<?php

declare(strict_types=1);

namespace PrivacyFirst\ServerLogAnalytics;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class Config
{
    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function load(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Analytics configuration is unavailable.');
        }

        $values = require $path;
        if (!is_array($values)) {
            throw new RuntimeException('Analytics configuration must return an array.');
        }

        foreach (['db_path', 'session_path', 'login_lock_path', 'import_state_path', 'import_lock_path', 'route_manifest', 'access_log_directory'] as $key) {
            $value = $values[$key] ?? null;
            if (!is_string($value) || !self::isAbsolutePath($value)) {
                throw new RuntimeException("Configuration value {$key} must be an absolute path.");
            }
            if (preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $value) === 1) {
                throw new RuntimeException("Configuration value {$key} must not contain parent traversal.");
            }
        }

        $hash = $values['password_hash'] ?? '';
        if (!is_string($hash) || password_get_info($hash)['algo'] === null) {
            throw new RuntimeException('A valid password_hash is required.');
        }

        $values += [
            'timezone' => 'Asia/Tokyo',
            'locale' => 'en',
            'site_prefix' => '/',
            'internal_hosts' => [],
            'admin_cookie_path' => '/admin/',
            'referrer_domains' => [],
            'retention_days' => 90,
            'retention_months' => 24,
            'stale_after_hours' => 36,
            'session_ttl_seconds' => 3600,
            'login_window_seconds' => 900,
            'login_max_failures' => 5,
            'login_lock_seconds' => 1800,
            'max_database_bytes' => 64 * 1024 * 1024,
            'max_parse_error_ratio' => 0.01,
            'max_log_lines' => 250000,
            'max_log_uncompressed_bytes' => 32 * 1024 * 1024,
            'max_import_seconds' => 120,
            'max_referrer_domains_per_day' => 100,
            'journal_mode' => 'WAL',
        ];

        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('PDO SQLite is not available.');
        }

        new DateTimeZone((string) $values['timezone']);

        if (!is_array($values['referrer_domains'])) {
            throw new RuntimeException('referrer_domains must be an array of approved domains.');
        }
        $approvedReferrers = [];
        foreach ($values['referrer_domains'] as $domain) {
            $domain = strtolower(rtrim((string) $domain, '.'));
            if (strlen($domain) > 253 || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain) !== 1) {
                throw new RuntimeException('referrer_domains contains an invalid domain.');
            }
            $approvedReferrers[$domain] = true;
        }
        $values['referrer_domains'] = array_keys($approvedReferrers);

        return new self($values);
    }

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            throw new RuntimeException("Missing configuration value: {$key}");
        }

        return $this->values[$key];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function assertPrivatePathsOutside(string $documentRoot): void
    {
        foreach (['db_path', 'session_path', 'login_lock_path', 'import_state_path', 'import_lock_path', 'route_manifest', 'access_log_directory'] as $key) {
            self::assertPathOutsideDocumentRoot((string) $this->values[$key], $documentRoot, "{$key} must be outside DocumentRoot.");
        }
    }

    public static function assertPathOutsideDocumentRoot(string $path, string $documentRoot, string $message = 'Path must be outside DocumentRoot.'): void
    {
        if (!self::isAbsolutePath($path) || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $path) === 1) {
            throw new RuntimeException($message);
        }
        $root = self::canonicalPath($documentRoot);
        $candidate = self::canonicalPath($path);
        if ($candidate === $root || str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException($message);
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function canonicalPath(string $path): string
    {
        $cursor = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        $tail = [];
        while (!file_exists($cursor) && !is_link($cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                throw new RuntimeException('Path has no existing canonical ancestor.');
            }
            $tail[] = basename($cursor);
            $cursor = $parent;
        }
        $resolved = realpath($cursor);
        if ($resolved === false) {
            throw new RuntimeException('Path canonicalization failed.');
        }
        foreach (array_reverse($tail) as $part) {
            $resolved .= DIRECTORY_SEPARATOR . $part;
        }
        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }
}

final class RouteCatalog
{
    /** @var array<string, string> */
    private array $labels;

    /** @param array<string, string> $labels */
    private function __construct(array $labels)
    {
        $this->labels = $labels;
    }

    public static function load(string $path, string $expectedBase): self
    {
        $json = @file_get_contents($path);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || ($data['base'] ?? null) !== $expectedBase) {
            throw new RuntimeException('Route manifest is missing or invalid.');
        }

        $labels = [];
        foreach ($data['pages'] ?? [] as $page) {
            if (!is_array($page) || !is_string($page['path'] ?? null) || !is_string($page['label'] ?? null)) {
                throw new RuntimeException('Route manifest contains an invalid page.');
            }
            $pathValue = self::normalizeRoute($page['path']);
            if (!self::isWithinPrefix($pathValue, $expectedBase)) {
                throw new RuntimeException('Route manifest contains a path outside the configured site prefix.');
            }
            if (strlen($page['label']) > 500) {
                throw new RuntimeException('Route manifest contains an overlong label.');
            }
            $labels[$pathValue] = $page['label'];
        }

        if ($labels === []) {
            throw new RuntimeException('Route manifest has no pages.');
        }

        return new self($labels);
    }

    public function contains(string $path): bool
    {
        return isset($this->labels[self::normalizeRoute($path)]);
    }

    public function label(string $path): string
    {
        return $this->labels[self::normalizeRoute($path)] ?? $path;
    }

    public static function normalizeRoute(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        if ($path !== '/' && !str_ends_with($path, '/') && pathinfo($path, PATHINFO_EXTENSION) === '') {
            $path .= '/';
        }
        return $path;
    }

    private static function isWithinPrefix(string $path, string $prefix): bool
    {
        if ($prefix === '/') {
            return str_starts_with($path, '/');
        }
        return $path === $prefix || $path === $prefix . '/' || str_starts_with($path, $prefix . '/');
    }
}

final class ParsedRequest
{
    public function __construct(
        public readonly string $day,
        public readonly string $method,
        public readonly string $path,
        public readonly int $status,
        public readonly string $referrer,
        public readonly string $userAgent,
    ) {
    }
}

final class LogParser
{
    public function __construct(private readonly DateTimeZone $timezone)
    {
    }

    public function parse(string $line): ?ParsedRequest
    {
        $pattern = '/^(?:\S+\s+)?\S+\s+\S+\s+\S+\s+\[([^\]]+)\]\s+"([A-Z]+)\s+([^"\s]+)(?:\s+HTTP\/[0-9.]+)?"\s+(\d{3})\s+(?:\d+|-)\s+"([^"]*)"\s+"([^"]*)"(?:\s+.*)?$/';
        if (preg_match($pattern, trim($line), $match) !== 1) {
            return null;
        }

        $timestamp = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $match[1]);
        if (!$timestamp) {
            return null;
        }

        $target = $match[3];
        $path = str_starts_with($target, 'http://') || str_starts_with($target, 'https://')
            ? (parse_url($target, PHP_URL_PATH) ?: '')
            : explode('?', $target, 2)[0];

        if ($path === '' || strlen($path) > 2048 || preg_match('/[\x00-\x1f\\\\]/', $path) === 1) {
            return null;
        }

        return new ParsedRequest(
            $timestamp->setTimezone($this->timezone)->format('Y-m-d'),
            $match[2],
            $path,
            (int) $match[4],
            $match[5],
            $match[6],
        );
    }
}

final class Classifier
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly RouteCatalog $routes,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function classify(ParsedRequest $request): ?array
    {
        $prefix = rtrim((string) $this->config['site_prefix'], '/');
        if ($request->path !== $prefix && $request->path !== $prefix . '/' && !str_starts_with($request->path, $prefix . '/')) {
            return null;
        }

        $isBot = $this->isBot($request->userAgent);
        $isAsset = $this->isAsset($request->path);
        $isKnownPage = !$isAsset && $this->routes->contains($request->path);
        $isPageView = !$isBot
            && $request->method === 'GET'
            && $request->status >= 200
            && $request->status < 400
            && $isKnownPage;

        $result = [
            'day' => $request->day,
            'bot' => $isBot,
            'asset' => $isAsset,
            'page_view' => $isPageView,
            'status' => $request->status,
            'not_found_category' => null,
            'page' => null,
            'referrer' => null,
            'browser' => null,
            'os' => null,
            'device' => null,
        ];

        if ($isBot) {
            return $result;
        }

        if ($request->status === 404) {
            $result['not_found_category'] = $isAsset ? 'static-asset' : 'page-or-endpoint';
        }

        if ($isPageView) {
            $result['page'] = RouteCatalog::normalizeRoute($request->path);
            $result['referrer'] = $this->classifyReferrer($request->referrer);
            [$result['browser'], $result['os'], $result['device']] = $this->classifyUserAgent($request->userAgent);
        }

        return $result;
    }

    private function isBot(string $userAgent): bool
    {
        if ($userAgent === '' || $userAgent === '-') {
            return true;
        }
        return preg_match('/bot|spider|crawler|slurp|bingpreview|headless|uptime|monitor|curl|wget|python-requests|facebookexternalhit|discordbot|telegrambot/i', $userAgent) === 1;
    }

    private function isAsset(string $path): bool
    {
        if (preg_match('#/(?:_astro|assets|images|story-assets|downloads)/#i', $path) === 1) {
            return true;
        }
        return preg_match('/\.(?:css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp3|ogg|wav|mp4|webm|pdf|zip|exe|msi)$/i', $path) === 1;
    }

    private function classifyReferrer(string $referrer): string
    {
        if ($referrer === '' || $referrer === '-') {
            return 'direct';
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || strlen($host) > 253) {
            return 'invalid';
        }
        $host = strtolower(rtrim($host, '.'));

        foreach ((array) $this->config['internal_hosts'] as $internal) {
            $internal = strtolower((string) $internal);
            if ($host === $internal || str_ends_with($host, '.' . $internal)) {
                return 'internal';
            }
        }

        if (strlen($host) > 253 || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1) {
            return 'external:other';
        }
        foreach ((array) $this->config['referrer_domains'] as $approved) {
            $approved = strtolower((string) $approved);
            if ($host === $approved || str_ends_with($host, '.' . $approved)) {
                return 'external:' . $approved;
            }
        }
        return 'external:other';
    }

    /** @return array{string, string, string} */
    private function classifyUserAgent(string $ua): array
    {
        $browser = match (true) {
            stripos($ua, 'Edg/') !== false => 'Edge',
            stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false => 'Opera',
            stripos($ua, 'SamsungBrowser/') !== false => 'Samsung Internet',
            stripos($ua, 'Firefox/') !== false || stripos($ua, 'FxiOS/') !== false => 'Firefox',
            stripos($ua, 'CriOS/') !== false || stripos($ua, 'Chrome/') !== false => 'Chrome',
            stripos($ua, 'Safari/') !== false => 'Safari',
            default => 'Other',
        };

        $os = match (true) {
            stripos($ua, 'Windows') !== false => 'Windows',
            stripos($ua, 'Android') !== false => 'Android',
            preg_match('/iPhone|iPad|iPod/i', $ua) === 1 => 'iOS',
            stripos($ua, 'CrOS') !== false => 'ChromeOS',
            stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS X') !== false => 'macOS',
            stripos($ua, 'Linux') !== false => 'Linux',
            default => 'Other',
        };

        $device = match (true) {
            preg_match('/iPad|Tablet|Nexus 7|Nexus 9|SM-T/i', $ua) === 1 => 'Tablet',
            preg_match('/Mobile|iPhone|iPod|Android.+Mobile/i', $ua) === 1 => 'Smartphone',
            preg_match('/Windows|Macintosh|CrOS|X11|Linux/i', $ua) === 1 => 'PC',
            default => 'Other',
        };

        return [$browser, $os, $device];
    }
}

final class Database
{
    private PDO $pdo;

    public function __construct(private readonly Config $config)
    {
        $dbPath = (string) $config->get('db_path');
        self::ensurePrivateDirectory(dirname($dbPath));
        $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $journalMode = strtoupper((string) $config->get('journal_mode'));
        if (!in_array($journalMode, ['WAL', 'DELETE'], true)) {
            throw new RuntimeException('Unsupported SQLite journal mode.');
        }
        $this->pdo->exec("PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA journal_mode = {$journalMode}; PRAGMA synchronous = FULL;");
        $this->migrate();
        @chmod($dbPath, 0600);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public static function ensurePrivateDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create a private analytics directory.');
        }
        if (!is_writable($path)) {
            throw new RuntimeException('A private analytics directory is not writable.');
        }
        @chmod($path, 0700);
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS imports (
    file_name TEXT PRIMARY KEY,
    sha256 TEXT NOT NULL,
    imported_at TEXT NOT NULL,
    parsed_rows INTEGER NOT NULL,
    site_rows INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS daily_metrics (
    period TEXT PRIMARY KEY,
    requests INTEGER NOT NULL DEFAULT 0,
    pageviews INTEGER NOT NULL DEFAULT 0,
    not_found INTEGER NOT NULL DEFAULT 0,
    bot_requests INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS monthly_metrics (
    period TEXT PRIMARY KEY,
    requests INTEGER NOT NULL DEFAULT 0,
    pageviews INTEGER NOT NULL DEFAULT 0,
    not_found INTEGER NOT NULL DEFAULT 0,
    bot_requests INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS daily_dimensions (
    period TEXT NOT NULL,
    dimension TEXT NOT NULL,
    value TEXT NOT NULL,
    count INTEGER NOT NULL,
    PRIMARY KEY (period, dimension, value)
);
CREATE TABLE IF NOT EXISTS monthly_dimensions (
    period TEXT NOT NULL,
    dimension TEXT NOT NULL,
    value TEXT NOT NULL,
    count INTEGER NOT NULL,
    PRIMARY KEY (period, dimension, value)
);
CREATE INDEX IF NOT EXISTS daily_dimensions_lookup ON daily_dimensions (dimension, period);
CREATE INDEX IF NOT EXISTS monthly_dimensions_lookup ON monthly_dimensions (dimension, period);
SQL);
    }
}

final class ImportResult
{
    public function __construct(
        public readonly bool $skipped,
        public readonly int $parsedRows,
        public readonly int $siteRows,
        public readonly int $pageviews,
    ) {
    }
}

final class Importer
{
    private readonly PDO $pdo;
    private readonly DateTimeZone $timezone;

    public function __construct(
        private readonly Config $config,
        private readonly LogParser $parser,
        private readonly Classifier $classifier,
        Database $database,
    ) {
        $this->pdo = $database->pdo();
        $this->timezone = new DateTimeZone((string) $config->get('timezone'));
    }

    public function import(string $inputPath): ImportResult
    {
        if (!is_file($inputPath) || !is_readable($inputPath)) {
            throw new RuntimeException('Input log is not readable.');
        }

        $fileName = basename($inputPath);
        $sha256 = hash_file('sha256', $inputPath);
        if (!is_string($sha256)) {
            throw new RuntimeException('Unable to hash input log.');
        }

        $existing = $this->pdo->prepare('SELECT sha256, parsed_rows, site_rows FROM imports WHERE file_name = :name');
        $existing->execute(['name' => $fileName]);
        $row = $existing->fetch();
        if (is_array($row)) {
            if (!hash_equals((string) $row['sha256'], $sha256)) {
                throw new RuntimeException('An imported file name was reused with different content.');
            }
            return new ImportResult(true, (int) $row['parsed_rows'], (int) $row['site_rows'], 0);
        }

        $handle = str_ends_with(strtolower($inputPath), '.gz') ? gzopen($inputPath, 'rb') : fopen($inputPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open input log.');
        }

        /** @var array<string, array{requests:int,pageviews:int,not_found:int,bot_requests:int}> $metrics */
        $metrics = [];
        /** @var array<string, array<string, array<string, int>>> $dimensions */
        $dimensions = [];
        $lines = $parsedRows = $siteRows = $parseErrors = $pageviews = $inputBytes = 0;
        $startedAt = microtime(true);

        try {
            while (($line = str_ends_with(strtolower($inputPath), '.gz') ? gzgets($handle) : fgets($handle)) !== false) {
                $lines++;
                $inputBytes += strlen($line);
                if ($lines > (int) $this->config->get('max_log_lines') || $inputBytes > (int) $this->config->get('max_log_uncompressed_bytes')) {
                    throw new RuntimeException('Input log exceeded the configured processing limit.');
                }
                if (microtime(true) - $startedAt > (int) $this->config->get('max_import_seconds')) {
                    throw new RuntimeException('Input log exceeded the configured processing time limit.');
                }
                $parsed = $this->parser->parse($line);
                if ($parsed === null) {
                    $parseErrors++;
                    continue;
                }
                $parsedRows++;
                $event = $this->classifier->classify($parsed);
                if ($event === null) {
                    continue;
                }
                $siteRows++;
                $day = (string) $event['day'];
                $metrics[$day] ??= ['requests' => 0, 'pageviews' => 0, 'not_found' => 0, 'bot_requests' => 0];

                if ($event['bot']) {
                    $metrics[$day]['bot_requests']++;
                    continue;
                }

                $metrics[$day]['requests']++;
                $this->increment($dimensions, $day, 'status', (string) $event['status']);
                if (is_string($event['not_found_category'])) {
                    $metrics[$day]['not_found']++;
                    $this->increment($dimensions, $day, 'not_found_category', $event['not_found_category']);
                }
                if ($event['page_view']) {
                    $metrics[$day]['pageviews']++;
                    $pageviews++;
                    foreach (['page', 'referrer', 'browser', 'os', 'device'] as $dimension) {
                        $this->increment($dimensions, $day, $dimension, (string) $event[$dimension]);
                    }
                }
            }
        } finally {
            str_ends_with(strtolower($inputPath), '.gz') ? gzclose($handle) : fclose($handle);
        }

        if ($lines === 0 || $parsedRows === 0) {
            throw new RuntimeException('Input log contained no parseable records.');
        }
        if (($parseErrors / $lines) > (float) $this->config->get('max_parse_error_ratio')) {
            throw new RuntimeException('Input log format did not pass the parse-error threshold.');
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($metrics as $day => $values) {
                $this->upsertMetrics('daily_metrics', $day, $values);
                $this->upsertMetrics('monthly_metrics', substr($day, 0, 7), $values);
            }
            foreach ($dimensions as $day => $dimensionValues) {
                foreach ($dimensionValues as $dimension => $values) {
                    foreach ($values as $value => $count) {
                        $this->upsertDimension('daily_dimensions', $day, $dimension, (string) $value, $count);
                        $this->upsertDimension('monthly_dimensions', substr($day, 0, 7), $dimension, (string) $value, $count);
                    }
                }
                $this->pruneReferrerDomains('daily_dimensions', $day);
                $this->pruneReferrerDomains('monthly_dimensions', substr($day, 0, 7));
            }

            $insert = $this->pdo->prepare('INSERT INTO imports (file_name, sha256, imported_at, parsed_rows, site_rows) VALUES (:name, :sha, :at, :parsed, :site)');
            $insert->execute([
                'name' => $fileName,
                'sha' => $sha256,
                'at' => (new DateTimeImmutable('now', $this->timezone))->format(DATE_ATOM),
                'parsed' => $parsedRows,
                'site' => $siteRows,
            ]);

            $this->applyRetention();
            $pageCount = (int) $this->pdo->query('PRAGMA page_count')->fetchColumn();
            $pageSize = (int) $this->pdo->query('PRAGMA page_size')->fetchColumn();
            if (($pageCount * $pageSize) > (int) $this->config->get('max_database_bytes')) {
                throw new RuntimeException('Analytics database size limit would be exceeded.');
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return new ImportResult(false, $parsedRows, $siteRows, $pageviews);
    }

    /** @param array<string, array<string, array<string, int>>> $dimensions */
    private function increment(array &$dimensions, string $day, string $dimension, string $value): void
    {
        if ($dimension === 'referrer' && str_starts_with($value, 'external:') && $value !== 'external:other') {
            $referrers = $dimensions[$day]['referrer'] ?? [];
            $knownExternal = 0;
            foreach ($referrers as $existing => $_count) {
                if (str_starts_with((string) $existing, 'external:') && $existing !== 'external:other') {
                    $knownExternal++;
                }
            }
            $limit = max(1, (int) $this->config->get('max_referrer_domains_per_day'));
            if (!array_key_exists($value, $referrers) && $knownExternal >= $limit) {
                $value = 'external:other';
            }
        }
        $dimensions[$day][$dimension][$value] = ($dimensions[$day][$dimension][$value] ?? 0) + 1;
    }

    /** @param array{requests:int,pageviews:int,not_found:int,bot_requests:int} $values */
    private function upsertMetrics(string $table, string $period, array $values): void
    {
        // This transaction-safe form also supports older SQLite versions.
        $insert = $this->pdo->prepare("INSERT OR IGNORE INTO {$table} (period, requests, pageviews, not_found, bot_requests) VALUES (:period, 0, 0, 0, 0)");
        $insert->execute(['period' => $period]);
        $statement = $this->pdo->prepare("UPDATE {$table} SET requests = requests + :requests, pageviews = pageviews + :pageviews, not_found = not_found + :not_found, bot_requests = bot_requests + :bots WHERE period = :period");
        $statement->execute([
            'period' => $period,
            'requests' => $values['requests'],
            'pageviews' => $values['pageviews'],
            'not_found' => $values['not_found'],
            'bots' => $values['bot_requests'],
        ]);
    }

    private function upsertDimension(string $table, string $period, string $dimension, string $value, int $count): void
    {
        $insert = $this->pdo->prepare("INSERT OR IGNORE INTO {$table} (period, dimension, value, count) VALUES (:period, :dimension, :value, 0)");
        $insert->execute(compact('period', 'dimension', 'value'));
        $statement = $this->pdo->prepare("UPDATE {$table} SET count = count + :count WHERE period = :period AND dimension = :dimension AND value = :value");
        $statement->execute(compact('period', 'dimension', 'value', 'count'));
    }

    private function applyRetention(): void
    {
        $today = new DateTimeImmutable('today', $this->timezone);
        $dailyCutoff = $today->sub(new DateInterval('P' . ((int) $this->config->get('retention_days') - 1) . 'D'))->format('Y-m-d');
        $month = new DateTimeImmutable($today->format('Y-m-01'), $this->timezone);
        $monthlyCutoff = $month->sub(new DateInterval('P' . ((int) $this->config->get('retention_months') - 1) . 'M'))->format('Y-m');

        foreach (['daily_metrics', 'daily_dimensions'] as $table) {
            $statement = $this->pdo->prepare("DELETE FROM {$table} WHERE period < :cutoff");
            $statement->execute(['cutoff' => $dailyCutoff]);
        }
        foreach (['monthly_metrics', 'monthly_dimensions'] as $table) {
            $statement = $this->pdo->prepare("DELETE FROM {$table} WHERE period < :cutoff");
            $statement->execute(['cutoff' => $monthlyCutoff]);
        }
    }

    private function pruneReferrerDomains(string $table, string $period): void
    {
        $limit = max(1, (int) $this->config->get('max_referrer_domains_per_day'));
        $statement = $this->pdo->prepare("SELECT value, count FROM {$table} WHERE period = :period AND dimension = 'referrer' AND value LIKE 'external:%' AND value <> 'external:other' ORDER BY count DESC, value ASC");
        $statement->execute(['period' => $period]);
        $rows = $statement->fetchAll();
        if (count($rows) <= $limit) {
            return;
        }

        $discarded = array_slice($rows, $limit);
        $discardedTotal = array_sum(array_map(fn (array $row): int => (int) $row['count'], $discarded));
        $delete = $this->pdo->prepare("DELETE FROM {$table} WHERE period = :period AND dimension = 'referrer' AND value = :value");
        foreach ($discarded as $row) {
            $delete->execute(['period' => $period, 'value' => $row['value']]);
        }
        if ($discardedTotal > 0) {
            $this->upsertDimension($table, $period, 'referrer', 'external:other', $discardedTotal);
        }
    }
}

final class ImportState
{
    public function __construct(private readonly string $path)
    {
    }

    /** @param array<string, scalar|null> $extra */
    public function write(string $status, array $extra = []): void
    {
        Database::ensurePrivateDirectory(dirname($this->path));
        $payload = ['status' => $status, 'updated_at' => gmdate(DATE_ATOM)] + $extra;
        $temporary = $this->path . '.tmp';
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false || !rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to update import state.');
        }
        @chmod($this->path, 0600);
    }

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        $json = @file_get_contents($this->path);
        $value = is_string($json) ? json_decode($json, true) : null;
        return is_array($value) ? $value : null;
    }
}

final class LoginLimiter
{
    public function __construct(
        private readonly string $path,
        private readonly int $windowSeconds,
        private readonly int $maxFailures,
        private readonly int $lockSeconds,
    ) {
    }

    /** @return array{allowed:bool,retry_after:int} */
    public function check(): array
    {
        return $this->withState(function (array $state, int $now): array {
            $lockedUntil = (int) ($state['locked_until'] ?? 0);
            return ['state' => $state, 'result' => [
                'allowed' => $lockedUntil <= $now,
                'retry_after' => max(0, $lockedUntil - $now),
            ]];
        });
    }

    public function recordFailure(): void
    {
        $this->withState(function (array $state, int $now): array {
            $failures = array_values(array_filter(
                array_map('intval', is_array($state['failures'] ?? null) ? $state['failures'] : []),
                fn (int $timestamp): bool => $timestamp >= $now - $this->windowSeconds,
            ));
            $failures[] = $now;
            if (count($failures) >= $this->maxFailures) {
                $state['locked_until'] = $now + $this->lockSeconds;
                $failures = [];
            }
            $state['failures'] = $failures;
            return ['state' => $state, 'result' => null];
        });
    }

    public function reset(): void
    {
        $this->withState(fn (array $state, int $now): array => ['state' => [], 'result' => null]);
    }

    /** @template T @param callable(array<string, mixed>, int):array{state:array<string,mixed>,result:T} $callback @return T */
    private function withState(callable $callback): mixed
    {
        Database::ensurePrivateDirectory(dirname($this->path));
        $handle = fopen($this->path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Unable to access the login lock.');
        }

        try {
            rewind($handle);
            $raw = stream_get_contents($handle);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $state = is_array($decoded) ? $decoded : [];
            $outcome = $callback($state, time());
            rewind($handle);
            ftruncate($handle, 0);
            $encoded = json_encode($outcome['state'], JSON_THROW_ON_ERROR);
            if (fwrite($handle, $encoded) === false) {
                throw new RuntimeException('Unable to update the login lock.');
            }
            fflush($handle);
            @chmod($this->path, 0600);
            return $outcome['result'];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

final class DashboardRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, int> */
    public function totals(string $start, string $end): array
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(SUM(requests), 0) requests, COALESCE(SUM(pageviews), 0) pageviews, COALESCE(SUM(not_found), 0) not_found, COALESCE(SUM(bot_requests), 0) bot_requests FROM daily_metrics WHERE period BETWEEN :start AND :end');
        $statement->execute(compact('start', 'end'));
        $row = $statement->fetch() ?: [];
        return [
            'requests' => (int) ($row['requests'] ?? 0),
            'pageviews' => (int) ($row['pageviews'] ?? 0),
            'not_found' => (int) ($row['not_found'] ?? 0),
            'bot_requests' => (int) ($row['bot_requests'] ?? 0),
        ];
    }

    /** @return list<array{period:string,pageviews:int,not_found:int,bot_requests:int}> */
    public function series(string $start, string $end): array
    {
        $statement = $this->pdo->prepare('SELECT period, pageviews, not_found, bot_requests FROM daily_metrics WHERE period BETWEEN :start AND :end ORDER BY period');
        $statement->execute(compact('start', 'end'));
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'period' => (string) $row['period'],
                'pageviews' => (int) $row['pageviews'],
                'not_found' => (int) $row['not_found'],
                'bot_requests' => (int) $row['bot_requests'],
            ];
        }
        return $rows;
    }

    /** @return list<array{period:string,pageviews:int,not_found:int,bot_requests:int}> */
    public function monthlySeries(string $start, string $end): array
    {
        $statement = $this->pdo->prepare('SELECT period, pageviews, not_found, bot_requests FROM monthly_metrics WHERE period BETWEEN :start AND :end ORDER BY period');
        $statement->execute(compact('start', 'end'));
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'period' => (string) $row['period'],
                'pageviews' => (int) $row['pageviews'],
                'not_found' => (int) $row['not_found'],
                'bot_requests' => (int) $row['bot_requests'],
            ];
        }
        return $rows;
    }

    /** @return list<array{value:string,count:int}> */
    public function topDimension(string $dimension, string $start, string $end, int $limit = 10): array
    {
        $allowed = ['page', 'referrer', 'browser', 'os', 'device', 'status', 'not_found_category'];
        if (!in_array($dimension, $allowed, true)) {
            throw new RuntimeException('Unsupported dashboard dimension.');
        }
        $limit = max(1, min(25, $limit));
        $statement = $this->pdo->prepare("SELECT value, SUM(count) count FROM daily_dimensions WHERE dimension = :dimension AND period BETWEEN :start AND :end GROUP BY value ORDER BY count DESC, value ASC LIMIT {$limit}");
        $statement->execute(compact('dimension', 'start', 'end'));
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = ['value' => (string) $row['value'], 'count' => (int) $row['count']];
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function latestImport(): ?array
    {
        $row = $this->pdo->query('SELECT file_name, imported_at, parsed_rows, site_rows FROM imports ORDER BY imported_at DESC LIMIT 1')->fetch();
        return is_array($row) ? $row : null;
    }
}
