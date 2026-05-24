<?php
declare(strict_types=1);

namespace vielhuber\extrablatt;

use PDO;
use SimpleXMLElement;

final readonly class FetchResult
{
    /**
     * @param array<string, string|int|null> $debug
     */
    private function __construct(
        public ?string $content,
        public ?string $errorMessage,
        public bool $isStale,
        public array $debug
    ) {
    }

    public static function fresh(string $content): self
    {
        return new self(content: $content, errorMessage: null, isStale: false, debug: []);
    }

    /**
     * @param array<string, string|int|null> $debug
     */
    public static function stale(string $content, string $errorMessage, array $debug): self
    {
        return new self(content: $content, errorMessage: $errorMessage, isStale: true, debug: $debug);
    }

    /**
     * @param array<string, string|int|null> $debug
     */
    public static function failed(string $errorMessage, array $debug): self
    {
        return new self(content: null, errorMessage: $errorMessage, isStale: false, debug: $debug);
    }
}

final readonly class FetchAttempt
{
    public function __construct(
        public ?string $body,
        public string $finalUrl,
        public string $status,
        public int $exitCode,
        public string $stderr,
        public int $durationMilliseconds
    ) {
    }

    /**
     * @return array<string, string|int|null>
     */
    public function debug(): array
    {
        return [
            'http_status' => $this->status,
            'curl_exit_code' => $this->exitCode,
            'duration_ms' => $this->durationMilliseconds,
            'final_url' => $this->finalUrl,
            'stderr' => $this->stderr === '' ? null : substr(string: $this->stderr, offset: 0, length: 400)
        ];
    }
}

final readonly class FeedItem
{
    public function __construct(
        public string $title,
        public string $link,
        public ?int $publishedAt,
        public ?string $imageUrl,
        public ?int $rating = null
    ) {
    }
}

final class Extrablatt
{
    // archive.ph and its mirrors — tried in order until one returns a real snapshot.
    private const ARCHIVE_TLDS = ['fo', 'li', 'md', 'ph', 'vn'];

    // Path-related state is initialised in the constructor against the
    // consumer-supplied rootDir, so the package can be installed via
    // `composer require` and the runtime files (.data/{cache,cookies,
    // config.json,.env,database.sqlite}, .bin/, .logs/) live in the
    // consumer's webroot rather than next to the library code in vendor/.
    private bool $debug = false;
    private string $cookieDir;
    // css/ and pwa/ live next to the library code so they ship as part of the
    // composer package — the consumer doesn't need to copy them into their
    // webroot. Served on-demand via the ?asset=... route below.
    private string $cssDir;
    private string $pwaDir;
    // Pinned to chrome123 — newer Chrome variants (124+) trip Reddit's bot
    // detection (TLS/header fingerprint check), returning 403 even with valid
    // cookies and from a non-blocked IP. chrome123 stays under Reddit's radar
    // while still working against archive.ph and the publisher HTML probes.
    private string $curlImpersonateBin;
    private string $dataDir;
    private string $logDir;
    private string $databaseFile;
    private string $configFile;
    private string $envFile;

    public function __construct(private readonly string $rootDir)
    {
        $this->cookieDir = $rootDir . '/.data/cookies';
        $this->cssDir = __DIR__ . '/../css';
        $this->pwaDir = __DIR__ . '/../pwa';
        $this->curlImpersonateBin = $rootDir . '/.bin/curl_chrome123';
        $this->dataDir = $rootDir . '/.data';
        $this->logDir = $rootDir . '/.logs';
        $this->databaseFile = $rootDir . '/.data/database.sqlite';
        $this->configFile = $rootDir . '/.data/config.json';
        $this->envFile = $rootDir . '/.data/.env';
    }

    /**
     * Stream a bundled static asset (css or pwa) through PHP. The library
     * ships its own css/* and pwa/* — the consumer needs no asset copy step.
     */
    private function serveAsset(string $relPath): void
    {
        if (
            preg_match(pattern: '~^(css|pwa)/[A-Za-z0-9._-]+$~', subject: $relPath) !== 1
            || str_contains(haystack: $relPath, needle: '..')
        ) {
            http_response_code(response_code: 404);
            return;
        }
        [$subdir, $file] = explode(separator: '/', string: $relPath, limit: 2);
        $absolute = ($subdir === 'css' ? $this->cssDir : $this->pwaDir) . '/' . $file;
        if (!is_file(filename: $absolute)) {
            http_response_code(response_code: 404);
            return;
        }
        $mime = match (true) {
            str_ends_with(haystack: $file, needle: '.css') => 'text/css',
            str_ends_with(haystack: $file, needle: '.js') => 'text/javascript',
            str_ends_with(haystack: $file, needle: '.json') => 'application/json',
            str_ends_with(haystack: $file, needle: '.svg') => 'image/svg+xml',
            str_ends_with(haystack: $file, needle: '.png') => 'image/png',
            default => 'application/octet-stream'
        };
        header(header: 'Content-Type: ' . $mime);
        // The service worker must not be cached aggressively, otherwise
        // updates would never reach installed clients.
        if (str_ends_with(haystack: $file, needle: 'sw.js')) {
            header(header: 'Cache-Control: no-cache');
            header(header: 'Service-Worker-Allowed: /');
        } else {
            header(header: 'Cache-Control: public, max-age=86400');
        }
        header(header: 'Content-Length: ' . filesize(filename: $absolute));
        readfile(filename: $absolute);
    }

    // Caches never expire automatically — only the manual Reset button (which
    // runs DELETE FROM articles plus a sweep of .data/cache/) drops them.
    // Subsequent scrapes upsert on top, so existing cached entries are
    // preserved unless the user explicitly resets.
    // Only enforced for the two social sources (reddit, x) — classical RSS
    // feeds and sitemaps are ingested without any cap.
    private const SOCIAL_FEED_MAX_ITEMS = 100;
    private const DASHBOARD_MAX_ITEMS = 10000;
    private const ARCHIVE_CHECK_CONCURRENCY = 8;
    private const THUMBNAIL_SIZE = 160;
    private const THUMBNAIL_JPEG_QUALITY = 78;
    private const THUMBNAIL_MAX_SOURCE_BYTES = 8_000_000;
    private const FETCH_CONNECT_TIMEOUT_SECONDS = 8;
    private const FETCH_MAX_TIME_SECONDS = 20;
    // Fixed HMAC key for signing the auth cookie. Anyone with read access to
    // this source file can forge cookies — which is identical to having the
    // password — so the security floor is the same as the password gate
    // itself. Changing the constant invalidates all existing sessions.
    private const AUTH_COOKIE_KEY = 'extrablatt-cookie-key-v1';

    /**
     * Fetch a page and extract the best representative image URL using
     * a layered fallback: og:image → twitter:image → first <img src> in
     * the body. Relative URLs are resolved against the page URL.
     */
    private function extractImageFromPage(string $pageUrl): ?string
    {
        $result = $this->fetchViaImpersonate(url: $pageUrl);
        if ($result->body === null || $result->body === '') {
            return null;
        }
        $body = substr(string: $result->body, offset: 0, length: 300000);

        $patterns = [
            '~(?:property|name)=["\']og:image(?::secure_url)?["\'][^>]{0,200}content=["\']([^"\']+)~i',
            '~(?:property|name)=["\']twitter:image(?::src)?["\'][^>]{0,200}content=["\']([^"\']+)~i',
            '~<img[^>]+src=["\']([^"\']+\.(?:jpe?g|png|webp|gif)(?:\?[^"\']*)?)["\']~i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match(pattern: $pattern, subject: $body, matches: $m) === 1) {
                $candidate = html_entity_decode(string: $m[1], flags: ENT_QUOTES);
                if ($candidate === '') {
                    continue;
                }
                return $this->resolveRelativeUrl(base: $pageUrl, ref: $candidate);
            }
        }
        return null;
    }

    /**
     * Resolve `$ref` against `$base` per RFC 3986 §5.3 (relevant subset).
     */
    private function resolveRelativeUrl(string $base, string $ref): string
    {
        $ref = trim(string: $ref);
        if ($ref === '') {
            return $base;
        }
        if (preg_match(pattern: '~^[a-z]+://~i', subject: $ref) === 1) {
            return $ref;
        }
        $parts = parse_url(url: $base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $origin = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with(haystack: $ref, needle: '//')) {
            return $scheme . ':' . $ref;
        }
        if (str_starts_with(haystack: $ref, needle: '/')) {
            return $origin . $ref;
        }
        $basePath = $parts['path'] ?? '/';
        $baseDir = substr(string: $basePath, offset: 0, length: (int) strrpos(haystack: $basePath, needle: '/') + 1);
        if ($baseDir === '') {
            $baseDir = '/';
        }
        return $origin . $baseDir . preg_replace(pattern: '~^\./~', replacement: '', subject: $ref);
    }

    /**
     * Check whether the request carries a valid auth cookie. The cookie
     * format is `<expiry>.<hmac>`, where hmac = HMAC-SHA256(expiry, secret).
     * This is stateless (no server-side session storage); the secret in
     * .env is the only thing tying valid cookies to this instance.
     */
    private function isAuthenticated(): bool
    {
        $env = $this->loadEnv();
        $password = (string) ($env['AUTH_PASSWORD'] ?? '');
        if ($password === '') {
            return true; // auth disabled
        }
        $cookie = (string) ($_COOKIE['extrablatt_auth'] ?? '');
        if ($cookie === '') {
            return false;
        }
        $parts = explode(separator: '.', string: $cookie, limit: 2);
        if (count(value: $parts) !== 2) {
            return false;
        }
        [$expiry, $sig] = $parts;
        if (!ctype_digit(text: $expiry) || (int) $expiry < time()) {
            return false;
        }
        $expected = hash_hmac(algo: 'sha256', data: $expiry, key: self::AUTH_COOKIE_KEY);
        return hash_equals(known_string: $expected, user_string: $sig);
    }

    private function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    private function setAuthCookie(int $expiry, string $signature): void
    {
        // Positional-only because PHP's named parameter for the options
        // array is `$expires_or_options`, not `$options`.
        setcookie('extrablatt_auth', $expiry . '.' . $signature, [
            'expires' => $expiry,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $this->isHttps()
        ]);
    }

    private function clearAuthCookie(): void
    {
        setcookie('extrablatt_auth', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $this->isHttps()
        ]);
    }

    private function handleLogin(): void
    {
        $env = $this->loadEnv();
        $expected = (string) ($env['AUTH_PASSWORD'] ?? '');
        $submitted = (string) ($_POST['auth_password'] ?? '');
        if ($expected === '') {
            $this->renderLoginPage(error: 'AUTH_PASSWORD in .env nicht gesetzt.');
            return;
        }
        if (!hash_equals(known_string: $expected, user_string: $submitted)) {
            // Sleep a tiny bit to slow brute force without DoS'ing ourselves.
            usleep(microseconds: 200_000);
            $this->renderLoginPage(error: 'Falsches Passwort.');
            return;
        }
        // 1-year session so the PWA stays logged in.
        $expiry = time() + 365 * 86400;
        $sig = hash_hmac(algo: 'sha256', data: (string) $expiry, key: self::AUTH_COOKIE_KEY);
        $this->setAuthCookie(expiry: $expiry, signature: $sig);
        header(header: 'Location: /');
    }

    private function handleLogout(): void
    {
        $this->clearAuthCookie();
        header(header: 'Location: /');
    }

    private function renderLoginPage(?string $error = null): void
    {
        header(header: 'Content-Type: text/html; charset=utf-8');
        $pwa = $this->pwaHeadTags();
        $errorHtml = $error !== null
            ? '<p class="err">' . htmlspecialchars(string: $error, flags: ENT_QUOTES) . '</p>'
            : '';
        echo <<<HTML
        <!doctype html>
        <html lang="de">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1,viewport-fit=cover">
            <title>extrablatt — Login</title>
            {$pwa}
            <style>
                :root { color-scheme: light; }
                * { box-sizing: border-box; }
                html, body { margin: 0; height: 100%; }
                body { font-family: system-ui, -apple-system, sans-serif; background: #f4f4f5; color: #111; display: flex; align-items: center; justify-content: center; padding: 1rem; }
                form { background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; padding: 32px 28px; box-shadow: 0 4px 24px rgba(0,0,0,.06); width: 100%; max-width: 360px; display: flex; flex-direction: column; gap: 14px; }
                h1 { margin: 0 0 4px; font-size: 22px; letter-spacing: -0.02em; }
                input { font: 600 16px/1 system-ui, sans-serif; padding: 12px 14px; border: 1px solid #d4d4d8; border-radius: 8px; width: 100%; }
                input:focus { outline: none; border-color: #18181b; }
                button { font: 700 14px/1 system-ui, sans-serif; padding: 12px 14px; background: #18181b; color: #fff; border: 0; border-radius: 8px; cursor: pointer; }
                button:hover { background: #3f3f46; }
                .err { color: #b91c1c; font: 500 13px/1.4 system-ui, sans-serif; margin: 0; }
            </style>
        </head>
        <body>
            <form method="post" action="/">
                <h1>📰 extrablatt</h1>
                {$errorHtml}
                <input type="password" name="auth_password" autofocus required placeholder="Passwort">
                <button type="submit">Login</button>
            </form>
        </body>
        </html>
        HTML;
    }

    public function run(): void
    {
        // Static-asset passthrough — serves the library's bundled css/* and
        // pwa/*. No auth needed; assets are public by design.
        if (isset($_GET['asset'])) {
            $this->serveAsset(relPath: (string) $_GET['asset']);
            return;
        }

        // Auth gate. Three trusted entry points bypass the login page:
        //   (1) POST with auth_password (the login form itself)
        //   (2) ?scrape=1&key=<AUTH_SCRAPE_KEY>   (cron from the outside)
        //   (3) authenticated cookie session
        // Anything else with auth enabled gets the login page.
        if (isset($_POST['auth_password'])) {
            $this->handleLogin();
            return;
        }
        if (isset($_GET['scrape'])) {
            // The cron-scrape endpoint reuses AUTH_PASSWORD as its key —
            // one secret to manage. Authenticated browser sessions skip
            // the key check entirely.
            $env = $this->loadEnv();
            $expectedKey = (string) ($env['AUTH_PASSWORD'] ?? '');
            $providedKey = (string) ($_GET['key'] ?? '');
            $keyOk = $expectedKey !== '' && hash_equals(known_string: $expectedKey, user_string: $providedKey);
            if ($keyOk || $this->isAuthenticated()) {
                // ?debug=1 re-enables the aihelper file log for Phase 8 only,
                // so we can capture the raw provider response when clustering
                // fails. Off by default to keep .logs/ small.
                $this->debug = ((string) ($_GET['debug'] ?? '')) === '1';
                // ?phase=<n> jumps straight to a single phase that can run
                // standalone (currently only Phase 8 — clustering reads from
                // the DB and doesn't depend on upstream scrape state).
                $phase = (int) ($_GET['phase'] ?? 0);
                if ($phase > 0) {
                    $this->runSinglePhase(phase: $phase);
                } else {
                    $this->runScrape();
                }
                return;
            }
            http_response_code(response_code: 403);
            header(header: 'Content-Type: text/plain; charset=utf-8');
            echo "forbidden\n";
            return;
        }
        if (!$this->isAuthenticated()) {
            $this->renderLoginPage();
            return;
        }
        if (isset($_GET['logout'])) {
            $this->handleLogout();
            return;
        }

        $customUrl = (string) ($_GET['url'] ?? '');
        $paperFilter = (string) ($_GET['paper'] ?? '');
        $statusFilter = (string) ($_GET['status'] ?? '');
        $paywallFilter = (string) ($_GET['paywall'] ?? '');
        $categoryFilter = (string) ($_GET['category'] ?? '');
        $readFilter = (string) ($_GET['read'] ?? '');
        $sortFilter = (string) ($_GET['sort'] ?? '');
        $magicFilter = (string) ($_GET['magic'] ?? '');
        $thumbFilter = (string) ($_GET['thumb'] ?? '');

        if (isset($_POST['reset']) && $_POST['reset'] === '1') {
            $db = $this->openDatabase();
            $db->exec(statement: 'DELETE FROM articles');
            $this->cacheClear();
            header(header: 'Location: /');
            return;
        }

        // Bulk mark-all-read: stamps read_at on every currently unread row.
        if (isset($_POST['mark_all_read']) && $_POST['mark_all_read'] === '1') {
            $db = $this->openDatabase();
            $stmt = $db->prepare(query: 'UPDATE articles SET read_at = :ts WHERE read_at IS NULL');
            $stmt->execute(params: [':ts' => time()]);
            header(header: 'Location: /');
            return;
        }

        // Read-mark beacon: fire-and-forget POST from the dashboard link click.
        // Persists read state in the DB so it survives across browsers and devices.
        if (isset($_POST['mark_read'])) {
            $url = trim(string: (string) $_POST['mark_read']);
            if ($url !== '') {
                $db = $this->openDatabase();
                $stmt = $db->prepare(query: 'UPDATE articles SET read_at = :ts WHERE url = :url AND read_at IS NULL');
                $stmt->execute(params: [':ts' => time(), ':url' => $url]);
            }
            http_response_code(response_code: 204);
            return;
        }

        // Vote beacon: adjusts the curator vote by ±1, clamped to [-3, +3].
        if (isset($_POST['vote'])) {
            $url = trim(string: (string) $_POST['vote']);
            $delta = (int) ($_POST['delta'] ?? 0);
            if ($url !== '' && ($delta === 1 || $delta === -1)) {
                $db = $this->openDatabase();
                $stmt = $db->prepare(query: 'UPDATE articles SET vote = MAX(-3, MIN(3, vote + :delta)) WHERE url = :url');
                $stmt->execute(params: [':delta' => $delta, ':url' => $url]);
            }
            http_response_code(response_code: 204);
            return;
        }

        if ($customUrl === '') {
            if ($paperFilter !== '' && !array_key_exists(key: $paperFilter, array: $this->papers())) {
                $paperFilter = '';
            }
            if (!in_array(needle: $statusFilter, haystack: ['', 'original', 'archive'], strict: true)) {
                $statusFilter = '';
            }
            if (!in_array(needle: $paywallFilter, haystack: ['', 'plus', 'free'], strict: true)) {
                $paywallFilter = '';
            }
            if ($categoryFilter !== '' && !in_array(needle: $categoryFilter, haystack: $this->selectableCategoryValues(), strict: true)) {
                $categoryFilter = '';
            }
            if (!in_array(needle: $readFilter, haystack: ['', 'read', 'unread'], strict: true)) {
                $readFilter = '';
            }
            if (!in_array(needle: $sortFilter, haystack: array_keys(array: $this->sortOptions()), strict: true)) {
                $sortFilter = '';
            }
            if (!in_array(needle: $magicFilter, haystack: ['', 'all'], strict: true)) {
                $magicFilter = '';
            }
            if (!in_array(needle: $thumbFilter, haystack: ['', 'yes', 'no'], strict: true)) {
                $thumbFilter = '';
            }
            header(header: 'Content-Type: text/html; charset=utf-8');
            echo $this->renderDashboard(
                paperFilter: $paperFilter,
                statusFilter: $statusFilter,
                paywallFilter: $paywallFilter,
                categoryFilter: $categoryFilter,
                readFilter: $readFilter,
                sortFilter: $sortFilter,
                magicFilter: $magicFilter,
                thumbFilter: $thumbFilter
            );
            return;
        }

        $sourceUrl = $this->normalizeProxyUrl(url: $customUrl);

        if ($sourceUrl === null) {
            http_response_code(response_code: 400);
            header(header: 'Content-Type: text/html; charset=utf-8');
            echo $this->renderErrorPage(
                url: $customUrl,
                errorMessage: 'Diese Unterseite kann nicht geproxied werden.',
                debug: ['url' => $customUrl]
            );
            return;
        }

        $stylePaper = $this->inferPaperFromUrl(originalUrl: $sourceUrl);
        $fetchResult = $this->fetchWithCache(originalUrl: $sourceUrl);

        if ($fetchResult->content === null) {
            http_response_code(response_code: 502);
            header(header: 'Content-Type: text/html; charset=utf-8');
            echo $this->renderErrorPage(url: $sourceUrl, errorMessage: $fetchResult->errorMessage, debug: $fetchResult->debug);
            return;
        }

        $content = $fetchResult->content;

        if ($this->isArchiveCaptcha(content: $content)) {
            header(header: 'Content-Type: text/html; charset=utf-8');
            echo $this->renderCaptchaPage(url: $sourceUrl);
            return;
        }

        $content = $this->replaceArchiveLinks(content: $content);
        $content = $this->stripBlankTargets(content: $content);
        $content = $this->injectStyles(content: $content, paper: $stylePaper);

        if ($fetchResult->isStale) {
            $content = $this->injectErrorBanner(content: $content, errorMessage: $fetchResult->errorMessage);
        }

        header(header: 'Content-Type: text/html; charset=utf-8');
        echo $content;
    }

    /**
     * Unwrap a snapshot blob. New snapshots are gzipped (zlib magic bytes
     * 0x78 0x9c / 0x78 0xda); pre-gzip cached entries stay readable as
     * plain HTML so we don't have to flush the cache to migrate.
     */
    private function decompressSnapshot(string $blob): string
    {
        if (strlen(string: $blob) < 2) {
            return $blob;
        }
        $magic = ord($blob[0]);
        if ($magic !== 0x78) {
            return $blob;
        }
        $decompressed = @gzuncompress(data: $blob);
        return $decompressed !== false ? $decompressed : $blob;
    }

    private function fetchWithCache(string $originalUrl, bool $forceRefresh = false): FetchResult
    {
        // Cache key is the original URL — independent of which mirror serves it.
        $cacheKey = md5(string: $originalUrl);
        $snapKey = 'snapshot:' . $cacheKey;
        $metaKey = 'snapshotmeta:' . $cacheKey;

        $cachedBody = $forceRefresh ? null : $this->cacheGet(key: $snapKey);
        if ($cachedBody !== null && $cachedBody !== '' && $this->isCachedSnapshotValid(metaKey: $metaKey)) {
            return FetchResult::fresh(content: $this->decompressSnapshot(blob: $cachedBody));
        }

        $attemptLog = [];
        $lastResult = null;

        foreach (self::ARCHIVE_TLDS as $tld) {
            $mirrorUrl = $this->archiveUrl(tld: $tld, originalUrl: $originalUrl);
            $result = $this->fetchViaImpersonate(url: $mirrorUrl);
            $lastResult = $result;

            $isLoadingPage = $result->body !== null && $this->isArchiveLoadingPage(body: $result->body, finalUrl: $result->finalUrl);
            $isCaptcha = $result->body !== null && $this->isArchiveCaptcha(content: $result->body);
            $isUsable = $result->body !== null && !$isCaptcha && !$isLoadingPage;

            $outcome = $isUsable ? 'OK' : ($isCaptcha ? 'captcha' : ($isLoadingPage ? 'loading-page' : ('http=' . $result->status)));
            $attemptLog[] = sprintf('archive.%s → %s (%d ms)', $tld, $outcome, $result->durationMilliseconds);

            if ($isUsable) {
                $compressed = gzcompress(data: $result->body, level: 6);
                $this->cacheSet(key: $snapKey, value: $compressed !== false ? $compressed : $result->body);
                $this->cacheSet(
                    key: $metaKey,
                    value: (string) json_encode(
                        value: [
                            'original_url' => $originalUrl,
                            'mirror_tld' => $tld,
                            'final_url' => $result->finalUrl,
                            'archived_at' => $this->extractArchivedAt(finalUrl: $result->finalUrl),
                            'fetched_at' => time()
                        ]
                    )
                );
                return FetchResult::fresh(content: $result->body);
            }
        }

        $errorMessage = 'Kein archive.<tld> Spiegel hat einen brauchbaren Snapshot geliefert.';
        $debug = $lastResult?->debug() ?? [];
        $debug['mirror_attempts'] = implode(separator: "\n", array: $attemptLog);
        $debug['original_url'] = $originalUrl;

        $stale = $this->cacheGet(key: $snapKey);
        if ($stale !== null && $stale !== '' && $this->isCachedSnapshotValid(metaKey: $metaKey)) {
            return FetchResult::stale(content: $this->decompressSnapshot(blob: $stale), errorMessage: $errorMessage, debug: $debug);
        }

        return FetchResult::failed(errorMessage: $errorMessage, debug: $debug);
    }

    private function isCachedSnapshotValid(string $metaKey): bool
    {
        $v = $this->cacheGet(key: $metaKey);
        if ($v === null) {
            return false;
        }
        $meta = json_decode(json: $v, associative: true);
        return is_array(value: $meta) && !empty($meta['archived_at']);
    }

    private function isArchiveLoadingPage(string $body, string $finalUrl): bool
    {
        // archive.ph returns a tiny placeholder page (no <head>, just a <table>
        // with a base64 spinner GIF) when a snapshot isn't ready or it's soft-
        // throttling. The strongest signal is the absence of a snapshot
        // timestamp in the final URL — real snapshots always redirect to
        // /YYYYMMDDhhmmss/.
        if ($this->extractArchivedAt(finalUrl: $finalUrl) !== null) {
            return false;
        }
        return strlen(string: $body) < 50000 && !str_contains(haystack: $body, needle: '<title');
    }

    private function fetchViaImpersonate(string $url): FetchAttempt
    {
        $cookieHeader = $this->buildCookieHeader(targetUrl: $url);
        $bodyFile = tempnam(directory: sys_get_temp_dir(), prefix: 'archive-body-');
        $stderrFile = tempnam(directory: sys_get_temp_dir(), prefix: 'archive-stderr-');

        if ($bodyFile === false || $stderrFile === false) {
            return new FetchAttempt(
                body: null,
                finalUrl: '',
                status: '',
                exitCode: 1,
                stderr: 'Could not create temporary files.',
                durationMilliseconds: 0
            );
        }

        $args = [
            $this->curlImpersonateBin,
            '-s',
            '-L',
            '--max-redirs',
            '10',
            '--compressed',
            '--connect-timeout',
            (string) self::FETCH_CONNECT_TIMEOUT_SECONDS,
            '--max-time',
            (string) self::FETCH_MAX_TIME_SECONDS,
            '-o',
            $bodyFile,
            '-w',
            '%{http_code}|%{url_effective}'
        ];

        if ($cookieHeader !== '') {
            $args[] = '-H';
            $args[] = 'Cookie: ' . $cookieHeader;
        }

        $args[] = $url;

        $cmd = implode(separator: ' ', array: array_map(callback: 'escapeshellarg', array: $args));
        $startedAt = microtime(as_float: true);
        exec(command: $cmd . ' 2>' . escapeshellarg(arg: $stderrFile), output: $output, result_code: $exitCode);
        $durationMilliseconds = (int) round(num: (microtime(as_float: true) - $startedAt) * 1000);

        $body = (string) file_get_contents(filename: $bodyFile);
        $stderr = (string) file_get_contents(filename: $stderrFile);

        if (file_exists(filename: $bodyFile)) {
            unlink(filename: $bodyFile);
        }
        if (file_exists(filename: $stderrFile)) {
            unlink(filename: $stderrFile);
        }

        $info = implode(separator: "\n", array: $output);
        [$status, $finalUrl] = array_pad(array: explode(separator: '|', string: $info, limit: 2), length: 2, value: '');

        if ($status !== '200' || $body === '') {
            return new FetchAttempt(
                body: null,
                finalUrl: $finalUrl,
                status: $status,
                exitCode: $exitCode,
                stderr: $stderr,
                durationMilliseconds: $durationMilliseconds
            );
        }

        return new FetchAttempt(
            body: $body,
            finalUrl: $finalUrl,
            status: $status,
            exitCode: $exitCode,
            stderr: $stderr,
            durationMilliseconds: $durationMilliseconds
        );
    }

    private function extractArchivedAt(string $finalUrl): ?int
    {
        // archive.<tld> snapshot URLs look like https://archive.<tld>/20260508085056/<url>
        $tldPattern = implode(separator: '|', array: self::ARCHIVE_TLDS);
        if (!preg_match(pattern: '#archive\.(?:' . $tldPattern . ')/(\d{14})/#', subject: $finalUrl, matches: $m)) {
            return null;
        }
        $ts = $m[1];
        $time = mktime(
            hour: (int) substr(string: $ts, offset: 8, length: 2),
            minute: (int) substr(string: $ts, offset: 10, length: 2),
            second: (int) substr(string: $ts, offset: 12, length: 2),
            month: (int) substr(string: $ts, offset: 4, length: 2),
            day: (int) substr(string: $ts, offset: 6, length: 2),
            year: (int) substr(string: $ts, offset: 0, length: 4)
        );
        return $time === false ? null : $time;
    }

    private function buildCookieHeader(string $targetUrl): string
    {
        $targetHost = (string) parse_url(url: $targetUrl, component: PHP_URL_HOST);
        $pairs = [];

        $files = is_dir(filename: $this->cookieDir) ? glob(pattern: $this->cookieDir . '/*.json') : [];

        if ($files === false) {
            $files = [];
        }

        foreach ($files as $file) {
            $raw = json_decode(json: (string) file_get_contents(filename: $file), associative: true);

            if (!is_array(value: $raw)) {
                continue;
            }

            foreach ($raw as $cookie) {
                $domain = ltrim(string: (string) ($cookie['domain'] ?? ''), characters: '.');

                if ($domain === '' || !str_ends_with(haystack: $targetHost, needle: $domain)) {
                    continue;
                }

                $pairs[] = $cookie['name'] . '=' . $cookie['value'];
            }
        }

        return implode(separator: '; ', array: $pairs);
    }

    private function normalizeProxyUrl(string $url): ?string
    {
        $url = trim(string: $url);

        $tldPattern = implode(separator: '|', array: self::ARCHIVE_TLDS);
        if (preg_match(pattern: '~^https?://archive\.(?:' . $tldPattern . ')/newest/(https?://.+)$~i', subject: $url, matches: $m)) {
            $url = $m[1];
        }

        if (!preg_match(pattern: '~^https?://~i', subject: $url)) {
            return null;
        }

        $host = parse_url(url: $url, component: PHP_URL_HOST);
        if (!is_string(value: $host) || $host === '') {
            return null;
        }

        $bareInput = preg_replace(pattern: '~^(?:www|m)\.~', replacement: '', subject: $host);
        foreach ($this->papers() as $info) {
            $paperHost = parse_url(url: $info['url'], component: PHP_URL_HOST);
            if (!is_string(value: $paperHost)) {
                continue;
            }
            $barePaper = preg_replace(pattern: '~^(?:www|m)\.~', replacement: '', subject: $paperHost);
            if ($barePaper !== '' && $bareInput === $barePaper) {
                return $url;
            }
        }

        return null;
    }

    private function inferPaperFromUrl(string $originalUrl): string
    {
        $host = parse_url(url: $originalUrl, component: PHP_URL_HOST);
        if (!is_string(value: $host) || $host === '') {
            return '';
        }

        $bareInput = preg_replace(pattern: '~^(?:www|m)\.~', replacement: '', subject: $host);
        foreach ($this->papers() as $key => $info) {
            $paperHost = parse_url(url: $info['url'], component: PHP_URL_HOST);
            if (!is_string(value: $paperHost)) {
                continue;
            }
            $barePaper = preg_replace(pattern: '~^(?:www|m)\.~', replacement: '', subject: $paperHost);
            if ($barePaper !== '' && $bareInput === $barePaper) {
                return (string) $key;
            }
        }

        return '';
    }

    private function archiveUrl(string $tld, string $originalUrl): string
    {
        return 'https://archive.' . $tld . '/newest/' . $originalUrl;
    }

    private function replaceArchiveLinks(string $content): string
    {
        $tldPattern = implode(separator: '|', array: self::ARCHIVE_TLDS);
        $patterns = [
            '~https?://archive\.(?:' . $tldPattern . ')/o/[^"\'/]+/(https?://[^"\'\s<>]+)~',
            '~https?://archive\.(?:' . $tldPattern . ')/newest/(https?://[^"\'\s<>]+)~',
            '~https?://archive\.(?:' . $tldPattern . ')/\d{14}/(https?://[^"\'\s<>]+)~'
        ];

        foreach ($patterns as $pattern) {
            $content = preg_replace_callback(
                pattern: $pattern,
                callback: fn(array $matches): string => $this->proxyUrl(
                    originalUrl: html_entity_decode(string: $matches[1], flags: ENT_QUOTES)
                ),
                subject: $content
            ) ?? $content;
        }

        return $content;
    }

    private function proxyUrl(string $originalUrl): string
    {
        return $this->currentOrigin() . '/?url=' . rawurlencode(string: $originalUrl);
    }

    private function currentOrigin(): string
    {
        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        return $scheme . '://' . $host;
    }

    private function stripBlankTargets(string $content): string
    {
        return preg_replace(pattern: '~\s+target=(["\'])_blank\1~i', replacement: '', subject: $content) ?? $content;
    }

    private function injectStyles(string $content, string $paper): string
    {
        // Reference the CSS files via <link rel="stylesheet"> instead of inlining
        // the bytes — the browser can cache them separately and they no longer
        // bloat every proxied response.
        //
        // archive.ph injects <base href="https://archive.ph/...">, so a relative
        // /css/foo.css would resolve against archive.ph. We force the absolute URL.
        $origin = $this->currentOrigin();
        $links = '';
        foreach ($this->cssAssetsForPaper(paper: $paper) as $relativePath => $absolutePath) {
            $version = file_exists(filename: $absolutePath) ? (int) filemtime(filename: $absolutePath) : 0;
            $qsep = str_starts_with(haystack: $relativePath, needle: '?') ? '&' : '?';
            $links .= '<link rel="stylesheet" href="' . $origin . '/' . $relativePath . $qsep . 'v=' . $version . '">';
        }

        $viewport = '<meta name="viewport" content="width=device-width, initial-scale=1,viewport-fit=cover">';
        $backLink = $this->renderBackLink();
        $injection = $viewport . $this->pwaHeadTags() . $this->consentBlockerStyles() . $links;

        if (stripos(haystack: $content, needle: '</head>') !== false) {
            $content = (string) preg_replace(
                pattern: '~</head>~i',
                replacement: $injection . '</head>',
                subject: $content,
                limit: 1
            );
        } else {
            $content = $injection . $content;
        }

        if (stripos(haystack: $content, needle: '</body>') !== false) {
            $content = (string) preg_replace(
                pattern: '~</body>~i',
                replacement: $backLink . '</body>',
                subject: $content,
                limit: 1
            );
        } else {
            $content .= $backLink;
        }

        return $content;
    }

    /**
     * @return array<string, string> map of webroot-relative path => absolute filesystem path
     */
    private function cssAssetsForPaper(string $paper): array
    {
        // Keys are the URL paths (served via ?asset=css/<file>), values are
        // the on-disk absolute paths for filemtime() cache-busting.
        $assets = ['?asset=css/common.css' => $this->cssDir . '/common.css'];
        if ($paper !== '') {
            // Obfuscate the per-paper stylesheet filename so no paper name
            // leaks via the network log. The hash is deterministic so the
            // browser cache stays warm across requests.
            $hash = substr(string: md5(string: $paper), offset: 0, length: 12);
            $assets['?asset=css/' . $hash . '.css'] = $this->cssDir . '/' . $hash . '.css';
        }
        return $assets;
    }

    private function consentBlockerStyles(): string
    {
        // Strip the most common consent / CMP / paywall overlays so the proxy
        // shows the actual content right away.
        return '<style id="extrablatt-no-consent">' .
            '[id^="sp_message_container"],' .
            '[id^="sp_message_iframe"],' .
            'iframe[id^="sp_message_iframe"],' .
            '.sp-message-container,.message-overlay,' .
            '#didomi-host,#didomi-popup,.didomi-popup-container,.didomi-notice,.didomi-popup-open,' .
            '#onetrust-banner-sdk,#onetrust-consent-sdk,#onetrust-pc-sdk,.onetrust-pc-dark-filter,' .
            '#cmpbox,#cmpbox2,#cmpwrapper,.cmpbox,.cmpwrapper,' .
            '.cmp-tcf2-overlay,' .
            '#qc-cmp2-container,#qc-cmp2-ui,' .
            '.fc-consent-root,.fc-dialog-overlay,#consent_blackbar,' .
            '.cmp-root-container,.cmp-container,.cmp-modal,.cmp-modal-wrapper,#cmp-stub,' .
            '#usercentrics-root,#uc-banner-modal-container,.uc-banner-content,' .
            '[id^="cleverpush"],[class*="cleverpush-confirm"],[class*="cleverpush-widget"],' .
            '.cleverpush-backdrop,' .
            '[id^="onesignal"],.onesignal-slidedown-dialog,' .
            '[aria-modal="true"][aria-label*="Consent" i],' .
            '[aria-modal="true"][aria-label*="Datenschutz" i],' .
            '[aria-modal="true"][aria-label*="Zustimmung" i],' .
            '[aria-modal="true"][aria-label*="Cookie" i]' .
            '{display:none!important;visibility:hidden!important;}' .
            'html,body{overflow:auto!important;height:auto!important;}' .
            'body.didomi-popup-open,body.cmp-modal-open,body.modal-open,body.no-scroll{overflow:auto!important;}' .
            '</style>';
    }

    private function pwaHeadTags(): string
    {
        return '<link rel="manifest" href="/?asset=pwa/manifest.json">' .
            '<meta name="theme-color" content="#111111">' .
            '<link rel="icon" type="image/svg+xml" href="/?asset=pwa/icon.svg">' .
            '<link rel="icon" type="image/png" sizes="192x192" href="/?asset=pwa/icon-192.png">' .
            '<link rel="apple-touch-icon" href="/?asset=pwa/apple-touch-icon.png">' .
            '<meta name="apple-mobile-web-app-capable" content="yes">' .
            '<meta name="mobile-web-app-capable" content="yes">' .
            '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' .
            '<meta name="apple-mobile-web-app-title" content="Extrablatt">' .
            '<script>' .
            'if("serviceWorker" in navigator){' .
            'window.addEventListener("load",()=>{' .
            'navigator.serviceWorker.register("/?asset=pwa/sw.js",{scope:"/"});' .
            '});' .
            '}' .
            '</script>';
    }

    private function renderBackLink(): string
    {
        // Absolute URL is required — archive.ph snapshots inject a <base href="https://archive.ph/...">
        // which would otherwise rewrite any relative href against archive.ph.
        $home = htmlspecialchars(string: $this->currentOrigin() . '/', flags: ENT_QUOTES);

        return '<a href="' .
            $home .
            '" style="position:fixed;top:8px;left:8px;z-index:2147483647;' .
            'background:rgba(0,0,0,.7);color:#fff;text-decoration:none;font:600 11px/1 system-ui,sans-serif;' .
            'padding:6px 9px;border-radius:5px;box-shadow:0 2px 6px rgba(0,0,0,.25);' .
            'backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)">' .
            '← Übersicht</a>';
    }

    private function isArchiveCaptcha(string $content): bool
    {
        if (str_contains(haystack: $content, needle: 'id="g-recaptcha"')) {
            return true;
        }

        return str_contains(haystack: $content, needle: 'Why do I have to complete a CAPTCHA?');
    }

    private function renderCaptchaPage(string $url): string
    {
        $direct = str_starts_with(haystack: $url, needle: 'https://archive.') ? $url : $this->archiveUrl(tld: 'ph', originalUrl: $url);
        $escapedUrl = htmlspecialchars(string: $direct, flags: ENT_QUOTES, encoding: 'UTF-8');

        return <<<HTML
        <!doctype html>
        <html lang="de">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Captcha erforderlich</title>
        </head>
        <body style="font-family:system-ui,sans-serif;max-width:600px;margin:2rem auto;padding:1rem">
            <h1>Captcha erforderlich</h1>
            <p>archive.ph verlangt fuer diesen Abruf eine manuelle Sicherheitspruefung.</p>
            <p><a href="{$escapedUrl}" rel="noreferrer">Direkt bei archive.ph oeffnen</a></p>
            <p><a href="/">← Zurück zur Übersicht</a></p>
        </body>
        </html>
        HTML;
    }

    /**
     * @param array<string, string|int|null> $debug
     */
    private function renderErrorPage(string $url, ?string $errorMessage, array $debug): string
    {
        $direct = str_starts_with(haystack: $url, needle: 'https://archive.') ? $url : $this->archiveUrl(tld: 'ph', originalUrl: $url);
        $escapedUrl = htmlspecialchars(string: $direct, flags: ENT_QUOTES, encoding: 'UTF-8');
        $escapedError = htmlspecialchars(
            string: $errorMessage ?? 'Die Nachrichtenseite konnte nicht geladen werden.',
            flags: ENT_QUOTES,
            encoding: 'UTF-8'
        );
        $debugRows = '';

        foreach ($debug as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $debugRows .=
                '<dt>' .
                htmlspecialchars(string: $label, flags: ENT_QUOTES, encoding: 'UTF-8') .
                '</dt><dd>' .
                htmlspecialchars(string: (string) $value, flags: ENT_QUOTES, encoding: 'UTF-8') .
                '</dd>';
        }

        return <<<HTML
        <!doctype html>
        <html lang="de">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Ladefehler</title>
            <style>
                :root { color-scheme: light; }
                body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #f4f4f5; color: #111; }
                main { max-width: 680px; margin: 0 auto; padding: 32px 16px; }
                h1 { margin: 0 0 12px; font-size: 28px; line-height: 1.15; }
                p { font-size: 16px; line-height: 1.5; }
                a { color: #111; font-weight: 700; }
                .box { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 16px; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
                dl { display: grid; grid-template-columns: minmax(110px, max-content) 1fr; gap: 8px 12px; margin: 16px 0 0; font: 13px/1.35 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
                dt { font-weight: 800; }
                dd { margin: 0; overflow-wrap: anywhere; }
            </style>
        </head>
        <body>
            <main>
                <div class="box">
                    <h1>Ladefehler</h1>
                    <p>{$escapedError}</p>
                    <p><a href="{$escapedUrl}" rel="noreferrer">Direkt bei archive.ph oeffnen</a></p>
                    <p><a href="/">← Zurück zur Übersicht</a></p>
                    <dl>{$debugRows}</dl>
                </div>
            </main>
        </body>
        </html>
        HTML;
    }

    private function injectErrorBanner(string $content, ?string $errorMessage): string
    {
        $escapedError = htmlspecialchars(
            string: $errorMessage ?? 'Aktualisierung fehlgeschlagen. Es wird eine ältere Cache-Version angezeigt.',
            flags: ENT_QUOTES,
            encoding: 'UTF-8'
        );
        $banner =
            '<div style="position:fixed;left:12px;right:12px;bottom:12px;z-index:2147483646;' .
            'background:#7f1d1d;color:#fff;padding:12px 14px;border-radius:8px;' .
            'box-shadow:0 4px 16px rgba(0,0,0,.28);font:600 13px/1.35 system-ui,sans-serif">' .
            'Aktualisierung fehlgeschlagen. Alte Cache-Version wird angezeigt.<br>' .
            '<span style="font-weight:400">' .
            $escapedError .
            '</span></div>';

        if (stripos(haystack: $content, needle: '</body>') !== false) {
            return (string) preg_replace(pattern: '~</body>~i', replacement: $banner . '</body>', subject: $content, limit: 1);
        }

        return $content . $banner;
    }

    /**
     * @return array<int, FeedItem>
     */
    private function fetchFeedItems(string $paper): array
    {
        $papers = $this->papers();
        if (!isset($papers[$paper]['rss'])) {
            return [];
        }
        $feedUrl = $papers[$paper]['rss'];

        // Non-RSS feed sources (X, Reddit) — these need authenticated JSON
        // scraping rather than XML parsing. Each handler caches its own raw
        // response and returns a FeedItem[] directly.
        if (str_starts_with(haystack: $feedUrl, needle: 'x://')) {
            return $this->fetchXTimelineItems(paper: $paper);
        }
        if (str_starts_with(haystack: $feedUrl, needle: 'reddit://')) {
            return $this->fetchRedditHomeItems(paper: $paper);
        }

        $body = $this->cacheGet(key: 'feed:' . $paper);
        if ($body === null || $body === '') {
            $result = $this->fetchViaImpersonate(url: $feedUrl);
            if ($result->body === null) {
                return [];
            }
            $body = $result->body;
            $this->cacheSet(key: 'feed:' . $paper, value: $body);
        }

        return $this->parseFeedBody(body: $body, paper: $paper);
    }

    /**
     * Scrape the authenticated X home timeline via the legacy v2 JSON endpoint.
     * Needs auth_token + ct0 cookies in .cookies/x.json. The bearer token below
     * is the public web-client bearer (constant across all sessions).
     *
     * @return array<int, FeedItem>
     */
    private function fetchXTimelineItems(string $paper): array
    {
        $body = $this->cacheGet(key: 'feed:' . $paper);
        if ($body === null || $body === '') {
            $cookieHeader = $this->buildCookieHeader(targetUrl: 'https://x.com/');
            $ct0 = $this->extractCookieValue(cookieHeader: $cookieHeader, name: 'ct0');
            if ($ct0 === '' || $cookieHeader === '') {
                return [];
            }
            $bearer = 'AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs%3D1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA';
            $result = $this->fetchWithHeaders(
                url: 'https://x.com/i/api/2/timeline/home.json?count=' . self::SOCIAL_FEED_MAX_ITEMS,
                headers: [
                    'Cookie: ' . $cookieHeader,
                    'Authorization: Bearer ' . $bearer,
                    'X-Csrf-Token: ' . $ct0,
                    'X-Twitter-Auth-Type: OAuth2Session',
                    'X-Twitter-Active-User: yes',
                    'Accept: application/json'
                ]
            );
            if ($result === null) {
                return [];
            }
            $body = $result;
            $this->cacheSet(key: 'feed:' . $paper, value: $body);
        }
        return $this->parseXTimeline(json: $body);
    }

    /**
     * @return array<int, FeedItem>
     */
    private function parseXTimeline(string $json): array
    {
        $data = json_decode(json: $json, associative: true);
        $tweets = $data['globalObjects']['tweets'] ?? null;
        $users = $data['globalObjects']['users'] ?? null;
        if (!is_array(value: $tweets) || !is_array(value: $users)) {
            return [];
        }
        $items = [];
        foreach ($tweets as $tweet) {
            if (!is_array(value: $tweet)) {
                continue;
            }
            if (isset($tweet['retweeted_status_id_str'])) {
                continue;
            }
            $userId = (string) ($tweet['user_id_str'] ?? '');
            $user = $users[$userId] ?? null;
            if (!is_array(value: $user)) {
                continue;
            }
            $screen = (string) ($user['screen_name'] ?? '');
            $idStr = (string) ($tweet['id_str'] ?? '');
            $text = trim(string: (string) ($tweet['full_text'] ?? $tweet['text'] ?? ''));
            if ($screen === '' || $idStr === '' || $text === '') {
                continue;
            }
            $titleText = trim(string: (string) preg_replace(
                pattern: '~\s*https?://t\.co/\S+\s*$~',
                replacement: '',
                subject: $text
            ));
            // Collapse embedded newlines/tabs into single spaces — otherwise
            // multi-line tweets break the per-item log formatting and look
            // like subprocess output leaking into stdout.
            $titleText = (string) preg_replace(pattern: '~\s+~u', replacement: ' ', subject: $titleText);
            $title = '@' . $screen . ': ' . mb_substr(string: $titleText !== '' ? $titleText : $text, start: 0, length: 220);
            $link = 'https://x.com/' . $screen . '/status/' . $idStr;
            $imageUrl = null;
            $media = $tweet['entities']['media'][0]['media_url_https']
                ?? $tweet['extended_entities']['media'][0]['media_url_https']
                ?? null;
            if (is_string(value: $media) && $media !== '') {
                $imageUrl = $media;
            } else {
                $avatar = (string) ($user['profile_image_url_https'] ?? '');
                if ($avatar !== '') {
                    $imageUrl = str_replace(search: '_normal.', replace: '_400x400.', subject: $avatar);
                }
            }
            $rating = ((int) ($tweet['favorite_count'] ?? 0))
                + ((int) ($tweet['retweet_count'] ?? 0))
                + ((int) ($tweet['reply_count'] ?? 0))
                + ((int) ($tweet['quote_count'] ?? 0));
            $items[] = new FeedItem(
                title: $title,
                link: $link,
                publishedAt: $this->parseDate(input: (string) ($tweet['created_at'] ?? '')),
                imageUrl: $imageUrl,
                rating: $rating > 0 ? $rating : null
            );
            if (count(value: $items) >= self::SOCIAL_FEED_MAX_ITEMS) {
                break;
            }
        }
        return $items;
    }

    /**
     * Scrape Reddit's authenticated home feed via /.json.
     *
     * @return array<int, FeedItem>
     */
    private function fetchRedditHomeItems(string $paper): array
    {
        $body = $this->cacheGet(key: 'feed:' . $paper);
        if ($body === null || $body === '') {
            $result = $this->fetchViaImpersonate(url: 'https://www.reddit.com/.json?limit=' . self::SOCIAL_FEED_MAX_ITEMS);
            if ($result->body === null) {
                return [];
            }
            $body = $result->body;
            $this->cacheSet(key: 'feed:' . $paper, value: $body);
        }
        return $this->parseRedditHome(json: $body);
    }

    /**
     * @return array<int, FeedItem>
     */
    private function parseRedditHome(string $json): array
    {
        $data = json_decode(json: $json, associative: true);
        $children = $data['data']['children'] ?? null;
        if (!is_array(value: $children)) {
            return [];
        }
        $items = [];
        foreach ($children as $child) {
            $post = $child['data'] ?? null;
            if (!is_array(value: $post)) {
                continue;
            }
            $title = trim(string: (string) ($post['title'] ?? ''));
            $permalink = (string) ($post['permalink'] ?? '');
            if ($title === '' || $permalink === '') {
                continue;
            }
            $link = 'https://www.reddit.com' . $permalink;
            $created = $post['created_utc'] ?? null;
            // Reddit's image CDN is locked down — we use the resolver in Phase 5
            // to derive a usable thumbnail. Leave imageUrl null so the og:image
            // resolver kicks in.
            $items[] = new FeedItem(
                title: 'r/' . ($post['subreddit'] ?? '') . ': ' . $title,
                link: $link,
                publishedAt: is_numeric(value: $created) ? (int) $created : null,
                imageUrl: null,
                rating: isset($post['score']) ? (int) $post['score'] : null
            );
            if (count(value: $items) >= self::SOCIAL_FEED_MAX_ITEMS) {
                break;
            }
        }
        return $items;
    }

    private function extractCookieValue(string $cookieHeader, string $name): string
    {
        foreach (explode(separator: ';', string: $cookieHeader) as $pair) {
            $pair = trim(string: $pair);
            if (str_starts_with(haystack: $pair, needle: $name . '=')) {
                return substr(string: $pair, offset: strlen(string: $name) + 1);
            }
        }
        return '';
    }

    /**
     * Tiny helper for API calls that need custom headers (auth, etc.).
     * Returns body on success, null on non-200.
     *
     * @param array<int, string> $headers
     */
    private function fetchWithHeaders(string $url, array $headers): ?string
    {
        $bodyFile = tempnam(directory: sys_get_temp_dir(), prefix: 'api-body-');
        if ($bodyFile === false) {
            return null;
        }
        $args = [$this->curlImpersonateBin, '-s', '--max-time', (string) self::FETCH_MAX_TIME_SECONDS, '-o', $bodyFile, '-w', '%{http_code}'];
        foreach ($headers as $h) {
            $args[] = '-H';
            $args[] = $h;
        }
        $args[] = $url;
        $cmd = implode(separator: ' ', array: array_map(callback: 'escapeshellarg', array: $args));
        exec(command: $cmd . ' 2>/dev/null', output: $out, result_code: $rc);
        $status = trim(string: implode(separator: '', array: $out));
        $body = (string) file_get_contents(filename: $bodyFile);
        @unlink(filename: $bodyFile);
        if ($status !== '200' || $body === '') {
            return null;
        }
        return $body;
    }

    /**
     * @return array<int, FeedItem>
     */
    private function parseFeedBody(string $body, string $paper): array
    {
        libxml_use_internal_errors(use_errors: true);
        $xml = simplexml_load_string(data: $body);
        if ($xml === false) {
            return [];
        }

        $items = [];

        // Sitemap fallback — image is in <image:image><image:loc>.
        if (isset($xml->url)) {
            $namespaces = $xml->getNamespaces(recursive: true);
            $imageNs = $namespaces['image'] ?? 'http://www.google.com/schemas/sitemap-image/1.1';
            foreach ($xml->url as $entry) {
                $loc = trim(string: (string) $entry->loc);
                if ($loc === '') {
                    continue;
                }
                $imageUrl = null;
                $imageNode = $entry->children(namespaceOrPrefix: $imageNs)->image ?? null;
                if ($imageNode !== null) {
                    $imageUrl = trim(string: (string) $imageNode->loc) ?: null;
                }
                $items[] = new FeedItem(
                    title: $this->deriveTitleFromUrl(url: $loc),
                    link: $loc,
                    publishedAt: $this->parseDate(input: (string) $entry->lastmod),
                    imageUrl: $imageUrl
                );
            }
            return $items;
        }

        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $entry) {
                $link = trim(string: (string) $entry->link);
                $title = trim(string: (string) $entry->title);
                if ($link === '' || $title === '') {
                    continue;
                }
                $rating = null;
                $description = (string) $entry->description;
                // Hacker News embeds "Points: 234" in each item's description.
                if ($description !== '' && preg_match(pattern: '~Points:\s*(\d+)~', subject: $description, matches: $pm) === 1) {
                    $rating = (int) $pm[1];
                }
                // For Hacker News we want the discussion thread instead of
                // the external article URL — the comments are the actual
                // content from the user's perspective.
                if ($paper === 'hackernews') {
                    $commentsUrl = trim(string: (string) $entry->comments);
                    if ($commentsUrl !== '') {
                        $link = $commentsUrl;
                    }
                }
                $items[] = new FeedItem(
                    title: $title,
                    link: $link,
                    publishedAt: $this->parseDate(input: $this->extractEntryDate(entry: $entry)),
                    imageUrl: $this->extractImageFromRssItem(entry: $entry),
                    rating: $rating
                );
            }
            return $items;
        }

        // Atom.
        if (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                // Article URL = link without rel or rel="alternate". Skip
                // rel="enclosure"/"self"/etc. which would otherwise be picked
                // up when they appear before the alternate link.
                $href = '';
                foreach ($entry->link as $linkNode) {
                    $rel = (string) $linkNode['rel'];
                    if ($rel !== '' && $rel !== 'alternate') {
                        continue;
                    }
                    $candidate = (string) $linkNode['href'];
                    if ($candidate !== '') {
                        $href = $candidate;
                        break;
                    }
                }
                $title = trim(string: (string) $entry->title);
                if ($href === '' || $title === '') {
                    continue;
                }
                $items[] = new FeedItem(
                    title: $title,
                    link: $href,
                    publishedAt: $this->parseDate(input: (string) ($entry->updated ?? $entry->published)),
                    imageUrl: $this->extractImageFromRssItem(entry: $entry)
                );
            }
        }

        return $items;
    }

    private function extractEntryDate(SimpleXMLElement $entry): string
    {
        $pubDate = trim(string: (string) $entry->pubDate);
        if ($pubDate !== '') {
            return $pubDate;
        }
        $namespaces = $entry->getNamespaces(recursive: true);
        $dcNs = $namespaces['dc'] ?? 'http://purl.org/dc/elements/1.1/';
        $dcDate = trim(string: (string) ($entry->children(namespaceOrPrefix: $dcNs)->date ?? ''));
        return $dcDate;
    }

    private function parseDate(string $input): ?int
    {
        $input = trim(string: $input);
        if ($input === '') {
            return null;
        }
        $ts = strtotime(datetime: $input);
        return $ts === false ? null : $ts;
    }

    private function extractImageFromRssItem(SimpleXMLElement $entry): ?string
    {
        $namespaces = $entry->getNamespaces(recursive: true);
        $mediaNs = $namespaces['media'] ?? 'http://search.yahoo.com/mrss/';
        $contentNs = $namespaces['content'] ?? 'http://purl.org/rss/1.0/modules/content/';

        // NOTE: nodes obtained via children(NS) carry the namespace context, so
        // bracket-attribute access ($node['url']) looks up the attribute in the
        // same namespace and returns nothing. attributes() resets to the
        // default (no-namespace) attribute group, which is where url/type
        // actually live.
        $mediaContent = $entry->children(namespaceOrPrefix: $mediaNs)->content ?? null;
        if ($mediaContent !== null) {
            foreach ($mediaContent as $node) {
                $url = trim(string: (string) $node->attributes()->url);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        $mediaThumb = $entry->children(namespaceOrPrefix: $mediaNs)->thumbnail ?? null;
        if ($mediaThumb !== null) {
            foreach ($mediaThumb as $node) {
                $url = trim(string: (string) $node->attributes()->url);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        if (isset($entry->enclosure)) {
            foreach ($entry->enclosure as $enclosure) {
                $type = (string) $enclosure['type'];
                $url = trim(string: (string) $enclosure['url']);
                if ($url !== '' && ($type === '' || str_starts_with(haystack: $type, needle: 'image/'))) {
                    return $url;
                }
            }
        }

        // Atom-style enclosure: <link rel="enclosure" type="image/..." href="...">.
        // DW and others embed the article image this way instead of via
        // <media:content> or <enclosure>.
        if (isset($entry->link)) {
            foreach ($entry->link as $linkNode) {
                $rel = (string) $linkNode['rel'];
                if ($rel !== 'enclosure') {
                    continue;
                }
                $type = (string) $linkNode['type'];
                if ($type !== '' && !str_starts_with(haystack: $type, needle: 'image/')) {
                    continue;
                }
                $url = trim(string: (string) $linkNode['href']);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        $contentEncoded = (string) ($entry->children(namespaceOrPrefix: $contentNs)->encoded ?? '');
        $description = (string) $entry->description;
        // Atom: <content type="html"> / <summary type="html"> with CDATA
        // wrapping an <img> tag (heise et al.). Falls back to RSS content/desc.
        $atomContent = (string) ($entry->content ?? '');
        $atomSummary = (string) ($entry->summary ?? '');
        foreach ([$contentEncoded, $atomContent, $description, $atomSummary] as $haystack) {
            if ($haystack === '') {
                continue;
            }
            if (preg_match(pattern: '~<img[^>]+src=["\']([^"\']+)~i', subject: $haystack, matches: $m)) {
                $candidate = trim(string: $m[1]);
                if ($candidate !== '' && !str_ends_with(haystack: $candidate, needle: '/')) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Whitelist of sort options. Maps the user-facing dropdown value to a
     * label and an ORDER BY clause. The default (empty key) is newest first.
     *
     * @return array<string, array{label: string, orderBy: string}>
     */
    private function sortOptions(): array
    {
        // Tie-break by id DESC so the natural insertion order surfaces last,
        // keeping the list stable when the primary key has many ties.
        return [
            '' => ['label' => 'Datum ↓', 'orderBy' => 'published_at DESC, id DESC'],
            'published_asc' => ['label' => 'Datum ↑', 'orderBy' => 'published_at ASC, id ASC'],
            'rating_desc' => ['label' => 'Rating ↓', 'orderBy' => 'rating IS NULL, rating DESC, published_at DESC'],
            'rating_asc' => ['label' => 'Rating ↑', 'orderBy' => 'rating IS NULL, rating ASC, published_at DESC'],
            'vote_desc' => ['label' => 'Vote ↓', 'orderBy' => 'vote DESC, published_at DESC'],
            'vote_asc' => ['label' => 'Vote ↑', 'orderBy' => 'vote ASC, published_at DESC'],
            'read_desc' => ['label' => 'Gelesen ↓', 'orderBy' => 'read_at IS NULL, read_at DESC, published_at DESC'],
            'read_asc' => ['label' => 'Gelesen ↑', 'orderBy' => 'read_at IS NULL, read_at ASC, published_at DESC'],
            'title_desc' => ['label' => 'Titel ↓', 'orderBy' => 'title COLLATE NOCASE DESC'],
            'title_asc' => ['label' => 'Titel ↑', 'orderBy' => 'title COLLATE NOCASE ASC'],
            'paper_desc' => ['label' => 'Quelle ↓', 'orderBy' => 'paper COLLATE NOCASE DESC, published_at DESC'],
            'paper_asc' => ['label' => 'Quelle ↑', 'orderBy' => 'paper COLLATE NOCASE ASC, published_at DESC'],
            'category_desc' => ['label' => 'Kategorie ↓', 'orderBy' => 'category IS NULL, category COLLATE NOCASE DESC, published_at DESC'],
            'category_asc' => ['label' => 'Kategorie ↑', 'orderBy' => 'category IS NULL, category COLLATE NOCASE ASC, published_at DESC'],
        ];
    }

    private function formatRating(int $value): string
    {
        // Compact display: 1234 → "↑ 1.2k", 234 → "↑ 234", 1.2M → "↑ 1.2M"
        if ($value >= 1_000_000) {
            return '↑ ' . number_format(num: $value / 1_000_000, decimals: 1) . 'M';
        }
        if ($value >= 1000) {
            return '↑ ' . number_format(num: $value / 1000, decimals: 1) . 'k';
        }
        return '↑ ' . (string) $value;
    }

    private function normalizeTitle(string $title): string
    {
        $t = trim(string: $title);
        $t = (string) preg_replace(pattern: '/\s+/u', replacement: ' ', subject: $t);
        return mb_strtolower(string: $t);
    }

    private function deriveTitleFromUrl(string $url): string
    {
        $path = (string) parse_url(url: $url, component: PHP_URL_PATH);
        $slug = basename(path: $path);
        $slug = preg_replace(pattern: '~-\d+$~', replacement: '', subject: $slug) ?? $slug;
        $title = str_replace(search: '-', replace: ' ', subject: $slug);
        $title = trim(string: $title);
        if ($title === '') {
            return $url;
        }
        return mb_convert_case(string: $title, mode: MB_CASE_TITLE, encoding: 'UTF-8');
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, bool>
     */
    private function checkArchiveAvailability(array $urls): array
    {
        $result = [];
        $toProbe = [];

        foreach ($urls as $url) {
            $cached = $this->readArchiveCheckCache(url: $url);
            if ($cached !== null) {
                $result[$url] = $cached;
                continue;
            }
            $toProbe[] = $url;
        }

        if (empty($toProbe)) {
            return $result;
        }

        $cookieHeader = $this->buildCookieHeader(targetUrl: 'https://archive.ph/');

        $tmpIn = tempnam(directory: sys_get_temp_dir(), prefix: 'archcheck-in-');
        if ($tmpIn === false) {
            foreach ($toProbe as $url) {
                $result[$url] = false;
            }
            return $result;
        }
        file_put_contents(filename: $tmpIn, data: implode(separator: "\n", array: $toProbe));

        $innerCmd =
            escapeshellarg(arg: $this->curlImpersonateBin) .
            ' -sI -H "$1" --max-time 8 -o /dev/null ' .
            '-w "STATUS:%{http_code}|LOCATION:%header{location}|TARGET:$2\n" ' .
            '"https://archive.ph/newest/$2" 2>/dev/null';

        $cmd = $this->buildParallelPipeline(
            tmpIn: $tmpIn,
            innerCmd: $innerCmd,
            concurrency: self::ARCHIVE_CHECK_CONCURRENCY,
            extraArg: 'Cookie: ' . $cookieHeader
        );

        $output = (string) shell_exec(command: $cmd);
        unlink(filename: $tmpIn);

        $seen = [];
        foreach (explode(separator: "\n", string: $output) as $line) {
            $line = trim(string: $line);
            if ($line === '') {
                continue;
            }
            if (!preg_match(pattern: '~^STATUS:(\d+)\|LOCATION:(.*?)\|TARGET:(.+)$~', subject: $line, matches: $m)) {
                continue;
            }
            $status = (int) $m[1];
            $location = $m[2];
            $url = $m[3];
            $available =
                $status === 302
                && preg_match(pattern: '~archive\.[a-z]+/\d{14}/~', subject: $location) === 1;
            $result[$url] = $available;
            $seen[$url] = true;
            $this->writeArchiveCheckCache(url: $url, available: $available);
        }

        foreach ($toProbe as $url) {
            if (!isset($seen[$url])) {
                $result[$url] = false;
            }
        }

        return $result;
    }

    private function readArchiveCheckCache(string $url): ?bool
    {
        $v = $this->cacheGet(key: 'archcheck:' . md5(string: $url));
        if ($v === null) {
            return null;
        }
        $data = json_decode(json: $v, associative: true);
        return is_array(value: $data) && isset($data['available']) ? (bool) $data['available'] : null;
    }

    private function writeArchiveCheckCache(string $url, bool $available): void
    {
        $this->cacheSet(
            key: 'archcheck:' . md5(string: $url),
            value: (string) json_encode(value: ['available' => $available])
        );
    }

    private function openDatabase(): PDO
    {
        static $db = null;
        if ($db !== null) {
            return $db;
        }
        if (!is_dir(filename: $this->dataDir)) {
            mkdir(directory: $this->dataDir, permissions: 0755, recursive: true);
        }
        $db = new PDO(dsn: 'sqlite:' . $this->databaseFile);
        $db->setAttribute(attribute: PDO::ATTR_ERRMODE, value: PDO::ERRMODE_EXCEPTION);
        $db->exec(
            statement:
            'CREATE TABLE IF NOT EXISTS articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                url TEXT UNIQUE NOT NULL,
                paper TEXT NOT NULL,
                title TEXT NOT NULL,
                published_at INTEGER,
                status TEXT NOT NULL CHECK(status IN ("original","archive")),
                paywall INTEGER DEFAULT NULL,
                image_url TEXT DEFAULT NULL,
                thumbnail TEXT DEFAULT NULL,
                category TEXT DEFAULT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            );'
        );
        $db->exec(statement: 'CREATE INDEX IF NOT EXISTS idx_articles_published ON articles(published_at DESC);');
        $db->exec(statement: 'CREATE INDEX IF NOT EXISTS idx_articles_paper ON articles(paper);');
        $db->exec(statement: 'CREATE INDEX IF NOT EXISTS idx_articles_status ON articles(status);');
        $db->exec(
            statement:
            'CREATE TABLE IF NOT EXISTS cache (
                key TEXT PRIMARY KEY NOT NULL,
                value BLOB,
                updated_at INTEGER NOT NULL
            );'
        );
        $this->runMigrations(db: $db);
        $db->exec(statement: 'CREATE INDEX IF NOT EXISTS idx_articles_category ON articles(category);');
        return $db;
    }

    private function cacheGet(string $key): ?string
    {
        $stmt = $this->openDatabase()->prepare(query: 'SELECT value FROM cache WHERE key = :k');
        $stmt->execute(params: [':k' => $key]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private function cacheHas(string $key): bool
    {
        $stmt = $this->openDatabase()->prepare(query: 'SELECT 1 FROM cache WHERE key = :k');
        $stmt->execute(params: [':k' => $key]);
        return $stmt->fetchColumn() !== false;
    }

    private function cacheSet(string $key, string $value): void
    {
        $stmt = $this->openDatabase()->prepare(
            query: 'INSERT INTO cache (key, value, updated_at) VALUES (:k, :v, :t)
                    ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $stmt->execute(params: [':k' => $key, ':v' => $value, ':t' => time()]);
    }

    private function cacheClear(string $prefix = ''): void
    {
        if ($prefix === '') {
            $this->openDatabase()->exec(statement: 'DELETE FROM cache');
            return;
        }
        $stmt = $this->openDatabase()->prepare(query: 'DELETE FROM cache WHERE key LIKE :p');
        $stmt->execute(params: [':p' => $prefix . '%']);
    }

    private function runMigrations(PDO $db): void
    {
        $columns = array_column(
            array: $db->query(query: 'PRAGMA table_info(articles)')->fetchAll(mode: PDO::FETCH_ASSOC),
            column_key: 'name'
        );
        if (!in_array(needle: 'paywall', haystack: $columns, strict: true)) {
            $db->exec(statement: 'ALTER TABLE articles ADD COLUMN paywall INTEGER DEFAULT NULL');
        }
        if (!in_array(needle: 'image_url', haystack: $columns, strict: true)) {
            $db->exec(statement: 'ALTER TABLE articles ADD COLUMN image_url TEXT DEFAULT NULL');
        }
        if (!in_array(needle: 'thumbnail', haystack: $columns, strict: true)) {
            $db->exec(statement: 'ALTER TABLE articles ADD COLUMN thumbnail TEXT DEFAULT NULL');
        }
        if (!in_array(needle: 'category', haystack: $columns, strict: true)) {
            $db->exec(statement: 'ALTER TABLE articles ADD COLUMN category TEXT DEFAULT NULL');
        }
        if (!in_array(needle: 'rating', haystack: $columns, strict: true)) {
            $db->exec(statement: 'ALTER TABLE articles ADD COLUMN rating INTEGER DEFAULT NULL');
        }
        if (!in_array(needle: 'read_at', haystack: $columns, strict: true)) {
            $db->exec(statement: 'ALTER TABLE articles ADD COLUMN read_at INTEGER DEFAULT NULL');
        }
        if (!in_array(needle: 'vote', haystack: $columns, strict: true)) {
            $db->exec(statement: 'ALTER TABLE articles ADD COLUMN vote INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array(needle: 'magic_rank', haystack: $columns, strict: true)) {
            $db->exec(statement: 'ALTER TABLE articles ADD COLUMN magic_rank INTEGER DEFAULT NULL');
        }
        if (!in_array(needle: 'duplicate_of', haystack: $columns, strict: true)) {
            $db->exec(statement: 'ALTER TABLE articles ADD COLUMN duplicate_of TEXT DEFAULT NULL');
        }
        if (!in_array(needle: 'dedup_checked_at', haystack: $columns, strict: true)) {
            $db->exec(statement: 'ALTER TABLE articles ADD COLUMN dedup_checked_at INTEGER DEFAULT NULL');
        }
        if (!in_array(needle: 'embedding', haystack: $columns, strict: true)) {
            $db->exec(statement: 'ALTER TABLE articles ADD COLUMN embedding BLOB DEFAULT NULL');
        }
        if (!in_array(needle: 'thumbnail_fail_count', haystack: $columns, strict: true)) {
            $db->exec(statement: 'ALTER TABLE articles ADD COLUMN thumbnail_fail_count INTEGER NOT NULL DEFAULT 0');
        }
        // 768-dim float32 vector = 3072 bytes. Anything bigger (early
        // 3072-dim runs) is incompatible with the current similarity loop
        // and gets reset so the next run re-embeds with the right size.
        $db->exec(statement: 'UPDATE articles SET embedding = NULL WHERE LENGTH(embedding) > 3072');
    }

    /**
     * @return array{papers: array<string, array{url: string, label: string, rss: string}>}
     */
    private function loadConfig(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $raw = @file_get_contents(filename: $this->configFile);
        $parsed = $raw !== false ? json_decode(json: $raw, associative: true) : null;
        $cached = [
            'papers' => is_array(value: $parsed['papers'] ?? null) ? $parsed['papers'] : []
        ];
        return $cached;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function categories(): array
    {
        return [
            'Politik' => ['Innenpolitik', 'Außenpolitik', 'Ukraine-Krieg', 'Nahost-Konflikt', 'Justiz & Verfassung'],
            'Wirtschaft & Finanzen' => ['Konjunktur', 'Unternehmen', 'Börse & Märkte', 'Krypto', 'Arbeitsmarkt'],
            'Sport' => ['Fußball', 'Motorsport', 'Tennis', 'Wintersport', 'Sportbusiness'],
            'Kultur & Medien' => ['Musik', 'Film & TV', 'Kunst & Ausstellungen', 'Reality-TV', 'Kulturbetrieb'],
            'Wissen & Technik' => [
                'AI', 'CSS', 'Programmierung', 'Open Source', 'Web & Internet', 'DevOps & Cloud',
                'Hardware', 'Mobilgeräte', 'Wearables', 'GPUs & Chips', 'Gaming', 'Cybersecurity',
                'Datenschutz', 'Wissenschaft', 'Klima & Umwelt', 'Tiere & Natur', 'Weltraum',
                'Energie', 'Robotik', 'Militärtechnik'
            ],
            'Gesundheit' => ['Ernährung', 'Mentale Gesundheit', 'Krankheiten & Epidemien', 'Medizin & Pharma', 'Fitness & Longevity'],
            'Gesellschaft & Panorama' => ['Kriminalität', 'Religion & Kirche', 'Brauchtum & Tradition', 'Familie & Beziehungen', 'Bildung'],
            'Lokal & Regional' => ['Bayern', 'Berlin', 'Norddeutschland', 'NRW & Westdeutschland', 'Verkehr & Infrastruktur'],
            'Reise & Lifestyle' => ['Kulinarik', 'Reiseziele', 'Wein & Getränke', 'Auto-Lifestyle', 'Haushaltstipps'],
            'Sonstiges' => ['Auto & Verkehr', 'Garten & Pflanzen', 'Wetter & Natur', 'Unfälle', 'Verbraucher']
        ];
    }

    /**
     * @return array<string, array{url: string, label: string, rss: string}>
     */
    private function papers(): array
    {
        return $this->loadConfig()['papers'];
    }

    /**
     * Flat list of all values that can be stored in articles.category — i.e.
     * leaf categories. Parents with children never get stored; childless
     * parents are themselves leaves.
     *
     * @return array<int, string>
     */
    private function leafCategories(): array
    {
        $leaves = [];
        foreach ($this->categories() as $parent => $children) {
            if (empty($children)) {
                $leaves[] = (string) $parent;
            } else {
                foreach ($children as $c) {
                    $leaves[] = (string) $c;
                }
            }
        }
        return $leaves;
    }

    /**
     * Every value selectable in the filter dropdown — parents AND children.
     *
     * @return array<int, string>
     */
    private function selectableCategoryValues(): array
    {
        $values = [];
        foreach ($this->categories() as $parent => $children) {
            $values[] = (string) $parent;
            foreach ($children as $c) {
                $values[] = (string) $c;
            }
        }
        return $values;
    }

    /**
     * @return array<int, string>
     */
    private function expandCategoryFilter(string $selected): array
    {
        $tree = $this->categories();
        if (isset($tree[$selected]) && !empty($tree[$selected])) {
            return array_map(callback: 'strval', array: $tree[$selected]);
        }
        return [$selected];
    }

    /**
     * @return array<string, string>
     */
    private function loadEnv(): array
    {
        static $env = null;
        if ($env !== null) {
            return $env;
        }
        $env = [];
        if (!file_exists(filename: $this->envFile)) {
            return $env;
        }
        foreach (file(filename: $this->envFile, flags: FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim(string: $line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = explode(separator: '=', string: $line, limit: 2);
            if (count(value: $parts) !== 2) {
                continue;
            }
            $env[trim(string: $parts[0])] = trim(string: $parts[1], characters: " \t\n\r\0\x0B\"'");
        }
        return $env;
    }

    /**
     * Builds the streaming `emit` closure used by every long-running phase.
     * Truncates the persistent scrape.log at start, sets up output buffering
     * defaults and returns a closure that echoes + flushes + tees to disk.
     */
    private function makeEmit(): callable
    {
        $this->setupStreamingOutput();
        $padding = str_repeat(string: ' ', times: 8192);
        if (!is_dir(filename: $this->logDir)) {
            mkdir(directory: $this->logDir, permissions: 0755, recursive: true);
        }
        $logFile = $this->logDir . '/scrape.log';
        @file_put_contents(filename: $logFile, data: '');
        return function (string $line) use ($padding, $logFile): void {
            echo $line . $padding . "\n";
            @ob_flush();
            @flush();
            @file_put_contents(filename: $logFile, data: $line . "\n", flags: FILE_APPEND);
        };
    }

    /**
     * Run Phase 8 (duplicate clustering) standalone — useful for debugging
     * with ?phase=8 since it operates entirely on DB state and needs none
     * of the upstream scrape phases to have run in this request.
     */
    private function runSinglePhase(int $phase): void
    {
        $emit = $this->makeEmit();
        $startedAt = microtime(as_float: true);
        $emit('=== extrablatt single-phase run — Phase ' . $phase . ' — ' . date(format: 'Y-m-d H:i:s') . ' ===');
        $emit('');
        $db = $this->openDatabase();

        $env = $this->loadEnv();
        $aiProvider = (string) ($env['AI_PROVIDER'] ?? '');
        $aiModel = (string) ($env['AI_MODEL'] ?? '');
        $apiKey = (string) ($env['AI_API_KEY'] ?? '');
        $aiConfig = [] + ['provider' => $aiProvider, 'model' => $aiModel];

        if ($phase === 8) {
            $emit('Phase 8/9: Duplikat-Erkennung per Embedding-Vergleich');
            if ($aiProvider === '' || $aiModel === '') {
                $emit('  ⚠️  AI nicht konfiguriert, Phase übersprungen');
            } else {
                $this->clusterDuplicates(db: $db, aiConfig: $aiConfig, apiKey: $apiKey, emit: $emit);
            }
        } else {
            $emit('⚠️  Phase ' . $phase . ' kann nicht standalone laufen (braucht Upstream-State).');
        }

        $totalMs = (int) round(num: (microtime(as_float: true) - $startedAt) * 1000);
        $emit('');
        $emit(sprintf('=== Phase fertig in %d.%03ds ===', intdiv($totalMs, 1000), $totalMs % 1000));
    }

    private function runScrape(): void
    {
        // Synchronous, streaming. Phases: feeds → paywall → archive → fulltext
        // → thumbnails → AI categories → upsert.
        $emit = $this->makeEmit();

        $startedAt = microtime(as_float: true);
        $emit('=== extrablatt scrape — ' . date(format: 'Y-m-d H:i:s') . ' ===');
        $emit('');

        $db = $this->openDatabase();

        // A manual scrape always re-fetches every feed. Drop cached feed bodies.
        // Article-level caches stay intact (tied to article URLs, not feed state).
        $this->cacheClear(prefix: 'feed:');

        // Phase 1: feeds.
        $emit('Phase 1/9: RSS-Feeds einlesen');
        $allItems = [];
        foreach (array_keys(array: $this->papers()) as $paper) {
            $paperKey = (string) $paper;
            $phaseStart = microtime(as_float: true);
            $items = $this->fetchFeedItems(paper: $paperKey);
            $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
            $withImg = count(value: array_filter(array: $items, callback: fn(FeedItem $i): bool => $i->imageUrl !== null));
            $emit(sprintf('  %-14s %3d items (%d mit Bild, %d ms)', $paperKey, count(value: $items), $withImg, $ms));
            foreach ($items as $item) {
                $allItems[] = ['paper' => $paperKey, 'item' => $item];
            }
        }
        $emit(sprintf('  → %d Artikel insgesamt', count(value: $allItems)));
        $emit('');

        if (empty($allItems)) {
            $emit('Keine Artikel im Feed — Abbruch.');
            return;
        }

        $urls = array_map(callback: fn(array $entry): string => $entry['item']->link, array: $allItems);

        // Phase 2: paywall.
        $emit('Phase 2/9: Paywall-Status prüfen (HTML-Probe der Originalseiten)');
        $knownPaywall = $this->readKnownPaywallStatus(db: $db, urls: $urls);
        $toProbe = array_values(array: array_filter(
            array: $allItems,
            callback: fn(array $entry): bool =>
                !array_key_exists(key: $entry['item']->link, array: $knownPaywall)
                || !$this->ogImageCacheExists(url: $entry['item']->link)
        ));
        $emit(sprintf('  %d bereits vollständig bekannt, %d neu/og-fehlt', count(value: $knownPaywall), count(value: $toProbe)));
        $phaseStart = microtime(as_float: true);
        $freshPaywall = empty($toProbe) ? [] : $this->checkPaywallStatusStreaming(items: $toProbe, emit: $emit);
        $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
        $paywallStatus = $knownPaywall + $freshPaywall;
        $plus = count(value: array_filter(array: $paywallStatus, callback: fn(?bool $v): bool => $v === true));
        $free = count(value: array_filter(array: $paywallStatus, callback: fn(?bool $v): bool => $v === false));
        $emit(sprintf('  → %d PLUS, %d free (%d ms)', $plus, $free, $ms));
        $emit('');

        // Phase 3: archive availability — only for PLUS articles.
        $emit('Phase 3/9: Archive-Verfügbarkeit prüfen (nur PLUS, parallel)');
        $plusUrls = array_values(array: array_filter(
            array: $urls,
            callback: fn(string $u): bool => ($paywallStatus[$u] ?? null) === true
        ));
        $emit(sprintf('  %d von %d Artikeln sind PLUS und werden geprüft', count(value: $plusUrls), count(value: $urls)));
        $phaseStart = microtime(as_float: true);
        $availability = empty($plusUrls) ? [] : $this->checkArchiveAvailability(urls: $plusUrls);
        $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
        $archived = count(value: array_filter(array: $availability));
        $emit(sprintf('  → %d archive verfügbar, %d nicht (%d ms)', $archived, count(value: $plusUrls) - $archived, $ms));
        $emit('');

        // Phase 4: archive-fulltext check.
        $emit('Phase 4/9: Volltext-Check der archivierten PLUS-Artikel');
        $knownFulltext = $this->readKnownArchiveFulltext(urls: $urls);
        $fulltextCandidates = array_values(array: array_filter(
            array: $allItems,
            callback: function (array $entry) use ($availability, $paywallStatus, $knownFulltext): bool {
                $url = $entry['item']->link;
                if (($availability[$url] ?? false) !== true) {
                    return false;
                }
                if (($paywallStatus[$url] ?? null) !== true) {
                    return false;
                }
                return !array_key_exists(key: $url, array: $knownFulltext);
            }
        ));
        $emit(sprintf('  %d Kandidaten (PLUS+Archive ohne Volltext-Flag)', count(value: $fulltextCandidates)));
        $phaseStart = microtime(as_float: true);
        $freshFulltext = empty($fulltextCandidates)
            ? []
            : $this->checkArchiveFulltextStreaming(items: $fulltextCandidates, emit: $emit);
        $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
        $archiveFull = $knownFulltext + $freshFulltext;
        $fullOk = count(value: array_filter(array: $archiveFull, callback: fn(?bool $v): bool => $v === true));
        $fullCut = count(value: array_filter(array: $archiveFull, callback: fn(?bool $v): bool => $v === false));
        $emit(sprintf('  → %d Volltext, %d gekürzt (%d ms)', $fullOk, $fullCut, $ms));
        $emit('');

        // Phase 5: thumbnails.
        $emit('Phase 5/9: Thumbnails herunterladen + skalieren');

        $redditItems = array_values(array_filter(
            array: $allItems,
            callback: fn(array $entry): bool => $entry['paper'] === 'reddit'
        ));
        // Resolve any reddit post whose og-cache is either missing OR empty —
        // an empty value (left behind by an earlier code path) used to block
        // the dedicated resolver and force the generic subreddit-icon
        // fallback for everything.
        $redditPending = array_values(array_filter(
            array: $redditItems,
            callback: fn(array $entry): bool => $this->readOgImageCache(url: $entry['item']->link) === null
        ));
        if (!empty($redditPending)) {
            $emit(sprintf('  Reddit-Auflösung: %d Posts (JSON + og:image bzw. Community-Icon)', count(value: $redditPending)));
            $phaseStart = microtime(as_float: true);
            $this->resolveRedditImagesStreaming(items: $redditPending, emit: $emit);
            $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
            $emit(sprintf('  → Reddit-Auflösung fertig (%d ms)', $ms));
        }

        // Reddit backfill: pick up to 50 older reddit DB entries that still
        // have no thumbnail and run them through the resolver + downloader
        // pipeline. Drains the historical backlog over a couple of scrapes
        // without dominating the wall time of any single run.
        $bfRows = (array) $db->query(query: "
            SELECT a.url AS url, a.title AS title, a.published_at AS published_at
            FROM articles a
            WHERE a.paper = 'reddit' AND a.thumbnail IS NULL
            ORDER BY a.published_at DESC
            LIMIT 50
        ")->fetchAll(mode: PDO::FETCH_ASSOC);
        $pendingFresh = array_flip(array_map(
            callback: fn(array $e): string => $e['item']->link,
            array: $redditPending
        ));
        $backfillItems = [];
        foreach ($bfRows as $row) {
            $url = (string) $row['url'];
            if (isset($pendingFresh[$url])) {
                continue;
            }
            if ($this->readOgImageCache(url: $url) !== null) {
                continue;
            }
            $backfillItems[] = [
                'paper' => 'reddit',
                'item' => new FeedItem(
                    title: (string) $row['title'],
                    link: $url,
                    publishedAt: $row['published_at'] !== null ? (int) $row['published_at'] : null,
                    imageUrl: null
                )
            ];
        }
        if (!empty($backfillItems)) {
            $emit(sprintf('  Reddit-Backfill: %d ältere Posts ohne Thumbnail', count(value: $backfillItems)));
            $phaseStart = microtime(as_float: true);
            $this->resolveRedditImagesStreaming(items: $backfillItems, emit: $emit);
            $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
            $emit(sprintf('  → Reddit-Backfill-Resolver fertig (%d ms)', $ms));
        }

        $knownThumbs = $this->readArticlesWithThumbnail(db: $db, urls: $urls);
        $papersConfig = $this->papers();
        $effectiveImages = [];
        foreach ($allItems as $entry) {
            $url = $entry['item']->link;
            $img = $entry['item']->imageUrl;
            if ($img === null || $img === '') {
                $img = $this->readOgImageCache(url: $url);
            }
            if ($img === null || $img === '') {
                $img = (string) ($papersConfig[$entry['paper']]['default_image'] ?? '');
            }
            if ($img === '') {
                // Last-resort fallback: Google favicon service for the paper
                // domain. Always reachable, returns a sane 128px icon for
                // every site we configure — turns "no thumbnail" into "at
                // least the source logo".
                $paperUrl = (string) ($papersConfig[$entry['paper']]['url'] ?? '');
                $host = (string) parse_url(url: $paperUrl, component: PHP_URL_HOST);
                if ($host !== '') {
                    $img = 'https://www.google.com/s2/favicons?domain=' . rawurlencode(string: $host) . '&sz=128';
                }
            }
            if ($img !== null && $img !== '') {
                $effectiveImages[$url] = $img;
            }
        }
        // Skip items Phase 7 would drop (PLUS without usable archive).
        // Title-duplicate filter intentionally absent: Phase 8 dedups
        // semantically via embeddings and keeps both rows.
        $thumbCandidates = array_values(array: array_filter(
            array: $allItems,
            callback: function (array $entry) use ($effectiveImages, $knownThumbs, $paywallStatus, $availability, $archiveFull): bool {
                $url = $entry['item']->link;
                if (!isset($effectiveImages[$url])) {
                    return false;
                }
                if (in_array(needle: $url, haystack: $knownThumbs, strict: true)) {
                    return false;
                }
                $pw = $paywallStatus[$url] ?? null;
                if ($pw === true) {
                    $usableArchive = ($availability[$url] ?? false) === true
                        && ($archiveFull[$url] ?? null) === true;
                    if (!$usableArchive) {
                        return false;
                    }
                }
                return true;
            }
        ));
        $emit(sprintf(
            '  %d bereits gecached, %d neu, %d ohne Bild-URL',
            count(value: $knownThumbs),
            count(value: $thumbCandidates),
            count(value: $allItems) - count(value: $knownThumbs) - count(value: $thumbCandidates)
        ));
        $phaseStart = microtime(as_float: true);
        $thumbnails = $this->downloadThumbnailsStreaming(items: $thumbCandidates, imageUrls: $effectiveImages, emit: $emit);

        // Second pass for fresh feed items: failed downloads that weren't
        // already using the favicon get one retry with the Google Favicon
        // URL. Without this, fresh items have to wait a full scrape cycle
        // before Generic-Backfill picks them up.
        $byUrl = [];
        foreach ($thumbCandidates as $entry) {
            $byUrl[$entry['item']->link] = $entry;
        }
        $retryCandidates = [];
        $retryImages = [];
        foreach ($thumbnails as $url => $thumb) {
            if ($thumb !== null) {
                continue;
            }
            $primary = (string) ($effectiveImages[$url] ?? '');
            if (str_contains(haystack: $primary, needle: 'google.com/s2/favicons')) {
                continue;
            }
            $entry = $byUrl[$url] ?? null;
            if ($entry === null) {
                continue;
            }
            $host = (string) parse_url(
                url: (string) ($papersConfig[$entry['paper']]['url'] ?? ''),
                component: PHP_URL_HOST
            );
            if ($host === '') {
                continue;
            }
            $retryImages[$url] = 'https://www.google.com/s2/favicons?domain=' . rawurlencode(string: $host) . '&sz=128';
            $retryCandidates[] = $entry;
        }
        if ($retryCandidates !== []) {
            $emit(sprintf('  Phase-5 Retry: %d Items mit Favicon-Fallback', count(value: $retryCandidates)));
            $retryThumbs = $this->downloadThumbnailsStreaming(items: $retryCandidates, imageUrls: $retryImages, emit: $emit);
            foreach ($retryThumbs as $url => $thumb) {
                $thumbnails[$url] = $thumb;
            }
        }

        $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
        $okThumbs = count(value: array_filter(array: $thumbnails));
        $emit(sprintf('  → %d Thumbnails generiert (%d ms)', $okThumbs, $ms));

        // Backfill thumbnail download — for items not in the current feed.
        // These bypass Phase 7's upsert path because their URL isn't in
        // $allItems, so we UPDATE the thumbnail column directly.
        if (!empty($backfillItems)) {
            $bfImages = [];
            $bfCandidates = [];
            $bfFallback = (string) ($papersConfig['reddit']['default_image'] ?? '');
            foreach ($backfillItems as $entry) {
                $img = $this->readOgImageCache(url: $entry['item']->link);
                if ($img === null || $img === '') {
                    $img = $bfFallback;
                }
                if ($img === '') {
                    continue;
                }
                $bfImages[$entry['item']->link] = $img;
                $bfCandidates[] = $entry;
            }
            if (!empty($bfCandidates)) {
                $bfStart = microtime(as_float: true);
                $bfThumbs = $this->downloadThumbnailsStreaming(items: $bfCandidates, imageUrls: $bfImages, emit: $emit);
                $bfUpdate = $db->prepare(query: 'UPDATE articles SET thumbnail = :thumb WHERE url = :url');
                $bfWritten = 0;
                foreach ($bfThumbs as $url => $thumb) {
                    if ($thumb !== null) {
                        $bfUpdate->execute(params: [':thumb' => $thumb, ':url' => $url]);
                        $bfWritten++;
                    }
                }
                $bfMs = (int) round(num: (microtime(as_float: true) - $bfStart) * 1000);
                $emit(sprintf('  → Backfill: %d Thumbnails in DB geschrieben (%d ms)', $bfWritten, $bfMs));
            }
        }

        // HN backfill: deeper body-image probe (first inline <img>) for HN
        // articles whose Phase-2 og:image grep came up empty. Capped at 30
        // per scrape so the wall time stays bounded; the body-img cache
        // remembers exhausted probes so they aren't retried forever.
        $hnRows = (array) $db->query(query: "
            SELECT url, title FROM articles
            WHERE paper='hackernews' AND thumbnail IS NULL
            ORDER BY published_at DESC LIMIT 30
        ")->fetchAll(mode: PDO::FETCH_ASSOC);
        $hnImages = [];
        $hnCandidates = [];
        $hnProbed = 0;
        foreach ($hnRows as $row) {
            $url = (string) $row['url'];
            if ($this->bodyImgCacheExists(url: $url)) {
                $img = $this->readBodyImgCache(url: $url);
            } else {
                $img = $this->extractImageFromPage(pageUrl: $url);
                $this->writeBodyImgCache(url: $url, imageUrl: $img ?? '');
                $hnProbed++;
            }
            if ($img === null || $img === '') {
                continue;
            }
            $hnImages[$url] = $img;
            $hnCandidates[] = [
                'paper' => 'hackernews',
                'item' => new FeedItem(
                    title: (string) $row['title'],
                    link: $url,
                    publishedAt: null,
                    imageUrl: null
                )
            ];
        }
        if (!empty($hnCandidates) || $hnProbed > 0) {
            $emit(sprintf('  HN-Backfill: %d Kandidaten (%d frisch geprüft)', count(value: $hnCandidates), $hnProbed));
            if (!empty($hnCandidates)) {
                $hnStart = microtime(as_float: true);
                $hnThumbs = $this->downloadThumbnailsStreaming(items: $hnCandidates, imageUrls: $hnImages, emit: $emit);
                $hnUpdate = $db->prepare(query: 'UPDATE articles SET thumbnail = :thumb WHERE url = :url');
                $hnWritten = 0;
                foreach ($hnThumbs as $url => $thumb) {
                    if ($thumb !== null) {
                        $hnUpdate->execute(params: [':thumb' => $thumb, ':url' => (string) $url]);
                        $hnWritten++;
                    }
                }
                $hnMs = (int) round(num: (microtime(as_float: true) - $hnStart) * 1000);
                $emit(sprintf('  → HN-Backfill: %d Thumbnails in DB geschrieben (%d ms)', $hnWritten, $hnMs));
            }
        }

        // Generic backfill: any paper article in DB with image_url set but
        // thumbnail missing — covers items that slipped through earlier
        // because Phase 5 was broken (xargs denied, image host blip, etc.).
        // Capped at 30 per scrape so wall-time stays bounded; the backlog
        // drains over a few runs.
        $genBfRows = (array) $db->query(query: "
            SELECT url, paper, title, image_url
            FROM articles
            WHERE thumbnail IS NULL
              AND COALESCE(thumbnail_fail_count, 0) < 3
              AND (COALESCE(paywall, 0) != 1 OR status = 'archive')
            ORDER BY published_at ASC
            LIMIT 500
        ")->fetchAll(mode: PDO::FETCH_ASSOC);
        if ($genBfRows !== []) {
            $genBfImages = [];
            $genBfCandidates = [];
            foreach ($genBfRows as $row) {
                $url = (string) $row['url'];
                $img = (string) ($row['image_url'] ?? '');
                if ($img === '') {
                    $img = (string) ($papersConfig[$row['paper']]['default_image'] ?? '');
                }
                if ($img === '') {
                    $host = (string) parse_url(
                        url: (string) ($papersConfig[$row['paper']]['url'] ?? ''),
                        component: PHP_URL_HOST
                    );
                    if ($host !== '') {
                        $img = 'https://www.google.com/s2/favicons?domain=' . rawurlencode(string: $host) . '&sz=128';
                    }
                }
                if ($img === '') {
                    continue;
                }
                $genBfImages[$url] = $img;
                $genBfCandidates[] = [
                    'paper' => (string) $row['paper'],
                    'item' => new FeedItem(
                        title: (string) $row['title'],
                        link: $url,
                        publishedAt: null,
                        imageUrl: $img
                    )
                ];
            }
            $emit(sprintf('  Generic-Backfill: %d ältere Artikel ohne Thumbnail', count(value: $genBfCandidates)));
            $genStart = microtime(as_float: true);
            $genThumbs = $this->downloadThumbnailsStreaming(items: $genBfCandidates, imageUrls: $genBfImages, emit: $emit);

            // Second pass: items that failed and weren't already using the
            // favicon get one retry with the Google Favicon URL — a broken
            // image_url shouldn't leave an article thumb-less when we can
            // always fall back to the source logo.
            $byUrl = [];
            foreach ($genBfCandidates as $entry) {
                $byUrl[$entry['item']->link] = $entry;
            }
            $retryCandidates = [];
            $retryImages = [];
            foreach ($genThumbs as $url => $thumb) {
                if ($thumb !== null) {
                    continue;
                }
                $primary = (string) ($genBfImages[$url] ?? '');
                if (str_contains(haystack: $primary, needle: 'google.com/s2/favicons')) {
                    continue;
                }
                $entry = $byUrl[$url] ?? null;
                if ($entry === null) {
                    continue;
                }
                $host = (string) parse_url(
                    url: (string) ($papersConfig[$entry['paper']]['url'] ?? ''),
                    component: PHP_URL_HOST
                );
                if ($host === '') {
                    continue;
                }
                $retryImages[$url] = 'https://www.google.com/s2/favicons?domain=' . rawurlencode(string: $host) . '&sz=128';
                $retryCandidates[] = $entry;
            }
            if ($retryCandidates !== []) {
                $emit(sprintf('  Generic-Backfill Retry: %d Items mit Favicon-Fallback', count(value: $retryCandidates)));
                $retryThumbs = $this->downloadThumbnailsStreaming(items: $retryCandidates, imageUrls: $retryImages, emit: $emit);
                foreach ($retryThumbs as $url => $thumb) {
                    $genThumbs[$url] = $thumb;
                }
            }

            $genUpdate = $db->prepare(query: 'UPDATE articles SET thumbnail = :thumb, thumbnail_fail_count = 0 WHERE url = :url');
            $failBump = $db->prepare(query: 'UPDATE articles SET thumbnail_fail_count = COALESCE(thumbnail_fail_count, 0) + 1 WHERE url = :url');
            $genWritten = 0;
            $genFailed = 0;
            // Iterate over every candidate the pipeline was asked about, not
            // over the returned results — silent pipeline drops (no output
            // line at all) would otherwise leave the counter untouched and
            // the URL would re-enter the backfill on every run.
            foreach ($byUrl as $url => $_entry) {
                $thumb = $genThumbs[$url] ?? null;
                if ($thumb !== null) {
                    $genUpdate->execute(params: [':thumb' => $thumb, ':url' => (string) $url]);
                    $genWritten++;
                } else {
                    $failBump->execute(params: [':url' => (string) $url]);
                    $genFailed++;
                }
            }
            $genMs = (int) round(num: (microtime(as_float: true) - $genStart) * 1000);
            $emit(sprintf('  → Generic-Backfill: %d geschrieben, %d Fail-Counter erhöht (%d ms)', $genWritten, $genFailed, $genMs));
        }
        $emit('');

        // Phase 6: AI categorisation.
        $emit('Phase 6/9: AI-Kategorisierung');
        $env = $this->loadEnv();
        $aiProvider = $env['AI_PROVIDER'] ?? '';
        $aiModel = $env['AI_MODEL'] ?? '';
        $apiKey = $env['AI_API_KEY'] ?? '';
        $knownCategories = $this->readKnownCategories(db: $db, urls: $urls);
        // Spiegele DB-Kategorien einmalig in den Titel-Cache, damit auch
        // andere Artikel mit identischem Titel den AI-Call überspringen.
        foreach ($allItems as $entry) {
            $linkUrl = $entry['item']->link;
            if (!array_key_exists(key: $linkUrl, array: $knownCategories)) {
                continue;
            }
            $title = $entry['item']->title;
            if (!$this->categoryCacheExists(title: $title)) {
                $this->writeCategoryCache(title: $title, category: $knownCategories[$linkUrl]);
            }
        }
        // Skip items Phase 7 would drop (PLUS without usable archive).
        // Title-duplicate filter intentionally absent: Phase 8 dedups
        // semantically via embeddings and keeps both rows.
        $toCategorize = array_values(array: array_filter(
            array: $allItems,
            callback: function (array $entry) use ($knownCategories, $paywallStatus, $availability, $archiveFull): bool {
                $url = $entry['item']->link;
                if (array_key_exists(key: $url, array: $knownCategories)) {
                    return false;
                }
                $pw = $paywallStatus[$url] ?? null;
                if ($pw === true) {
                    $usableArchive = ($availability[$url] ?? false) === true
                        && ($archiveFull[$url] ?? null) === true;
                    if (!$usableArchive) {
                        return false;
                    }
                }
                return true;
            }
        ));
        $emit(sprintf('  %d bereits kategorisiert, %d zu kategorisieren', count(value: $knownCategories), count(value: $toCategorize)));
        $freshCategories = [];
        if (empty($toCategorize)) {
            $emit('  → keine neuen Artikel');
        } elseif ($aiProvider === '' || $aiModel === '') {
            $emit('  ⚠️  AI_PROVIDER / AI_MODEL in .env nicht gesetzt — Phase übersprungen');
        } else {
            $phaseStart = microtime(as_float: true);
            $freshCategories = $this->categorizeArticlesStreaming(
                items: $toCategorize,
                categories: $this->leafCategories(),
                aiConfig: ['provider' => $aiProvider, 'model' => $aiModel],
                apiKey: $apiKey,
                emit: $emit
            );
            $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
            $emit(sprintf('  → %d kategorisiert (%d ms)', count(value: array_filter($freshCategories)), $ms));
        }
        $emit('');

        // Phase 7: upsert.
        $emit('Phase 7/9: In Datenbank schreiben');
        $stmt = $db->prepare(
            query:
            'INSERT INTO articles
                (url, paper, title, published_at, status, paywall, image_url, thumbnail, category, rating, created_at, updated_at)
             VALUES
                (:url, :paper, :title, :published_at, :status, :paywall, :image_url, :thumbnail, :category, :rating, :now, :now)
             ON CONFLICT(url) DO UPDATE SET
                status = excluded.status,
                paywall = COALESCE(excluded.paywall, articles.paywall),
                image_url = COALESCE(excluded.image_url, articles.image_url),
                thumbnail = COALESCE(excluded.thumbnail, articles.thumbnail),
                category = COALESCE(excluded.category, articles.category),
                rating = COALESCE(excluded.rating, articles.rating),
                title = excluded.title,
                published_at = COALESCE(excluded.published_at, articles.published_at),
                updated_at = :now;'
        );

        $existingUrls = [];
        foreach ($db->query(query: 'SELECT url FROM articles')->fetchAll(mode: PDO::FETCH_ASSOC) ?: [] as $row) {
            $existingUrls[(string) $row['url']] = true;
        }

        $now = time();
        $inserted = 0;
        $updated = 0;
        $skippedPaywalled = 0;
        foreach ($allItems as $entry) {
            $item = $entry['item'];
            $wasExisting = isset($existingUrls[$item->link]);
            $isArchived = ($availability[$item->link] ?? false) === true;
            $pw = $paywallStatus[$item->link] ?? null;
            $full = $archiveFull[$item->link] ?? null;
            // Archive only ever surfaced for PLUS articles whose snapshot is
            // demonstrably full. PLUS articles without a usable archive
            // snapshot are dropped entirely.
            $usableArchive = $pw === true && $isArchived && $full === true;
            if ($pw === true && !$usableArchive) {
                $skippedPaywalled++;
                continue;
            }
            $status = $usableArchive ? 'archive' : 'original';
            $thumb = $thumbnails[$item->link] ?? null;
            $cat = $freshCategories[$item->link] ?? null;
            $stmt->execute(params: [
                ':url' => $item->link,
                ':paper' => $entry['paper'],
                ':title' => $item->title,
                ':published_at' => $item->publishedAt,
                ':status' => $status,
                ':paywall' => $pw === null ? null : ($pw ? 1 : 0),
                ':image_url' => $item->imageUrl,
                ':thumbnail' => $thumb,
                ':category' => $cat,
                ':rating' => $item->rating,
                ':now' => $now
            ]);
            if ($wasExisting) {
                $updated++;
            } else {
                $inserted++;
                $existingUrls[$item->link] = true;
            }
        }
        $emit(sprintf(
            '  → %d neu, %d aktualisiert, %d PLUS ohne Volltext-Archive übersprungen, %d in DB',
            $inserted,
            $updated,
            $skippedPaywalled,
            $this->totalArticleCount(db: $db)
        ));
        $emit('');

        // Phase 8: AI duplicate clustering. Same story across multiple
        // sources gets collapsed to one canonical entry — must run BEFORE
        // magic bucket so the bucket doesn't pick duplicates.
        $emit('Phase 8/9: Duplikat-Erkennung per Embedding-Vergleich');
        $phaseStart = microtime(as_float: true);
        $envDup = $this->loadEnv();
        $aiProviderDup = (string) ($envDup['AI_PROVIDER'] ?? '');
        $aiModelDup = (string) ($envDup['AI_MODEL'] ?? '');
        $apiKeyDup = (string) ($envDup['AI_API_KEY'] ?? '');
        $aiConfigDup = [] + ['provider' => $aiProviderDup, 'model' => $aiModelDup];
        if ($aiProviderDup === '' || $aiModelDup === '') {
            $emit('  ⚠️  AI nicht konfiguriert, Phase übersprungen');
        } else {
            $this->clusterDuplicates(db: $db, aiConfig: $aiConfigDup, apiKey: $apiKeyDup, emit: $emit);
        }
        $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
        $emit(sprintf('  → Phase 8 fertig (%d ms)', $ms));
        $emit('');

        // Phase 9: magic bucket. Two-stage pipeline:
        //   (a) score every unread article via the rule-based magicScore()
        //       — affinity from user votes/reads transferred to paper +
        //       category, z-normalised external rating, recency decay
        //   (b) hand the top 30 to the configured LLM for a final rerank
        //       into the top 10, with the user's liked / disliked papers
        //       and categories as context
        // The bucket is frozen — readers drain it, no slide-in. Only the
        // next scrape repopulates.
        $emit('Phase 9/9: Magic-Bucket berechnen (Affinität + AI-Rerank)');
        $phaseStart = microtime(as_float: true);
        $db->exec(statement: 'UPDATE articles SET magic_rank = NULL');

        $aff = $this->magicComputeAffinity(db: $db);
        // Per-paper recency cutoff: only show articles published after the
        // user's last read action FOR THAT SOURCE. A global cutoff would
        // hide low-frequency sources (e.g. weekly css-tricks) once any
        // higher-frequency news article gets clicked.
        $readPerPaper = [];
        $rows = (array) $db->query(query: '
            SELECT paper, MAX(read_at) FROM articles
            WHERE read_at IS NOT NULL GROUP BY paper
        ')->fetchAll(mode: PDO::FETCH_NUM);
        foreach ($rows as $row) {
            $readPerPaper[(string) $row[0]] = (int) $row[1];
        }
        $unread = (array) $db->query(query: '
            SELECT url, paper, title, published_at, rating, vote, category
            FROM articles WHERE read_at IS NULL AND duplicate_of IS NULL
        ')->fetchAll(mode: PDO::FETCH_ASSOC);
        $unread = array_values(array: array_filter(
            array: $unread,
            callback: function (array $row) use ($readPerPaper): bool {
                $cutoff = $readPerPaper[(string) ($row['paper'] ?? '')] ?? 0;
                if ($cutoff === 0) {
                    return true;
                }
                return ((int) ($row['published_at'] ?? 0)) > $cutoff;
            }
        ));

        if (empty($unread)) {
            $emit('  → keine ungelesenen Artikel im Topf');
        } else {
            foreach ($unread as &$row) {
                $row['_score'] = $this->magicScore(row: $row, aff: $aff);
            }
            unset($row);
            usort(array: $unread, callback: fn(array $a, array $b): int => $b['_score'] <=> $a['_score']);
            $candidates = [];
            $perPaper = [];
            foreach ($unread as $row) {
                $paper = (string) ($row['paper'] ?? '');
                $perPaper[$paper] = $perPaper[$paper] ?? 0;
                if ($perPaper[$paper] < 1) {
                    $candidates[] = $row;
                    $perPaper[$paper]++;
                }
            }
            $emit(sprintf('  → %d ungelesene gescort, %d Kandidaten (Top 1/Quelle)', count(value: $unread), count(value: $candidates)));

            $env = $this->loadEnv();
            $aiProvider = (string) ($env['AI_PROVIDER'] ?? '');
            $aiModel = (string) ($env['AI_MODEL'] ?? '');
            $apiKey = (string) ($env['AI_API_KEY'] ?? '');
            $aiConfig = [] + ['provider' => $aiProvider, 'model' => $aiModel];

            $final = null;
            if ($aiProvider !== '' && $aiModel !== '') {
                $final = $this->magicAiRerank(
                    candidates: $candidates,
                    aff: $aff,
                    aiConfig: $aiConfig,
                    apiKey: $apiKey,
                    emit: $emit
                );
                if ($final !== null) {
                    $emit(sprintf('  → AI rerank ergab %d Artikel', count(value: $final)));
                }
            }
            if ($final === null) {
                $final = $candidates;
                $emit('  → Fallback: Reihenfolge nach Regel-Score');
            }

            // Topic-level dedup within the bucket: Phase 8 catches obvious
            // duplicates at threshold 0.85 across all 7 days; here we apply
            // a softer 0.70 just to the magic candidates so two near-twin
            // takes on the same story (different sources, different
            // framing) don't both surface. Greedy by current rerank order.
            $final = $this->dedupMagicBucket(rows: $final, db: $db, emit: $emit);

            $rankStmt = $db->prepare(query: 'UPDATE articles SET magic_rank = :rank WHERE url = :url');
            foreach ($final as $i => $row) {
                $rankStmt->execute(params: [':rank' => $i + 1, ':url' => (string) $row['url']]);
            }
            $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
            $emit(sprintf('  → %d Artikel im Bucket (%d ms)', count(value: $final), $ms));
        }
        $emit('');

        $emit('Cache-Cleanup');
        $this->cleanupCache(db: $db, emit: $emit);
        $emit('');

        $totalMs = (int) round(num: (microtime(as_float: true) - $startedAt) * 1000);
        $emit(sprintf('=== Scrape beendet in %d.%03ds ===', intdiv($totalMs, 1000), $totalMs % 1000));
    }

    /**
     * Trim the persistent cache table. Two big wins:
     *  - `snapshot:*` / `snapshotmeta:*`: drop entries whose article either
     *    isn't in the DB anymore OR is older than 14 days. ~1 MB per
     *    archived PLUS page, so this is where the bulk of the DB size
     *    comes from.
     *  - `feed:*`: transient RSS bodies, only useful within the current
     *    scrape — drop after every run.
     * VACUUM at the end so SQLite actually shrinks the file.
     */
    private function cleanupCache(PDO $db, callable $emit): void
    {
        $cutoff = time() - 7 * 86400;
        $validKeys = [];
        $stmt = $db->prepare(query: '
            SELECT url FROM articles
            WHERE status = "archive" AND published_at > :cutoff
        ');
        $stmt->execute(params: [':cutoff' => $cutoff]);
        while ($row = $stmt->fetch(mode: PDO::FETCH_NUM)) {
            $md5 = md5(string: (string) $row[0]);
            $validKeys['snapshot:' . $md5] = true;
            $validKeys['snapshotmeta:' . $md5] = true;
        }
        $deleteStmt = $db->prepare(query: 'DELETE FROM cache WHERE key = :key');
        $scan = $db->query(query: "
            SELECT key, LENGTH(value) FROM cache
            WHERE key LIKE 'snapshot:%' OR key LIKE 'snapshotmeta:%'
        ");
        $deletedSnap = 0;
        $bytesSnap = 0;
        while ($row = $scan->fetch(mode: PDO::FETCH_NUM)) {
            if (!isset($validKeys[(string) $row[0]])) {
                $deleteStmt->execute(params: [':key' => (string) $row[0]]);
                $deletedSnap++;
                $bytesSnap += (int) $row[1];
            }
        }
        $emit(sprintf('  → %d Snapshots gelöscht (%d MB frei)', $deletedSnap, intdiv($bytesSnap, 1048576)));

        // One-time migration: re-compress any snapshot still stored in plain
        // form (no zlib magic byte at position 0). Brings the cache table
        // from ~1.3 MB/entry to ~120 KB/entry.
        $updateStmt = $db->prepare(query: 'UPDATE cache SET value = :v WHERE key = :k');
        $scan = $db->query(query: "SELECT key, value FROM cache WHERE key LIKE 'snapshot:%'");
        $recompressed = 0;
        $bytesSaved = 0;
        while ($row = $scan->fetch(mode: PDO::FETCH_ASSOC)) {
            $blob = (string) $row['value'];
            if (strlen(string: $blob) < 2 || ord($blob[0]) === 0x78) {
                continue;
            }
            $compressed = gzcompress(data: $blob, level: 6);
            if ($compressed === false) {
                continue;
            }
            $bytesSaved += strlen(string: $blob) - strlen(string: $compressed);
            $updateStmt->execute(params: [':v' => $compressed, ':k' => (string) $row['key']]);
            $recompressed++;
        }
        if ($recompressed > 0) {
            $emit(sprintf('  → %d Snapshots gzip-komprimiert (%d MB frei)', $recompressed, intdiv($bytesSaved, 1048576)));
        }

        $feedBytes = (int) $db->query(query: "SELECT COALESCE(SUM(LENGTH(value)),0) FROM cache WHERE key LIKE 'feed:%'")->fetchColumn();
        $feedDeleted = $db->exec(statement: "DELETE FROM cache WHERE key LIKE 'feed:%'");
        $emit(sprintf('  → %d Feed-Cache-Einträge gelöscht (%d MB frei)', (int) $feedDeleted, intdiv($feedBytes, 1048576)));

        $db->exec(statement: 'VACUUM');
        $emit('  → VACUUM fertig');
    }

    private function setupStreamingOutput(): void
    {
        // Disable every layer of output buffering so progress lines reach the
        // browser as they happen. Apache + mod_php especially likes to buffer
        // unless gzip is off and zlib compression is disabled.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header(header: 'Content-Type: text/plain; charset=utf-8');
        header(header: 'Cache-Control: no-cache, no-store, must-revalidate');
        header(header: 'X-Accel-Buffering: no');
        if (function_exists(function: 'apache_setenv')) {
            @apache_setenv(variable: 'no-gzip', value: '1');
        }
        ini_set(option: 'zlib.output_compression', value: '0');
        ini_set(option: 'output_buffering', value: '0');
        ini_set(option: 'implicit_flush', value: '1');
        ob_implicit_flush(enable: true);
        set_time_limit(seconds: 0);
        // Survive a closed browser tab — otherwise the process dies at the
        // next flush() after the client goes away, before Phase 7 (DB upsert)
        // runs.
        ignore_user_abort(enable: true);
        // Capture fatal errors (out-of-memory, etc.) that PHP otherwise kills
        // the process for silently, so we can see them in scrape.log.
        $logFile = $this->logDir . '/scrape.log';
        register_shutdown_function(callback: static function () use ($logFile): void {
            $err = error_get_last();
            if ($err === null) {
                return;
            }
            $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
            if (($err['type'] & $fatalTypes) === 0) {
                return;
            }
            $msg = sprintf(
                "\n💥 FATAL: %s in %s:%d\n",
                $err['message'] ?? 'unknown',
                $err['file'] ?? '?',
                $err['line'] ?? 0
            );
            @file_put_contents(filename: $logFile, data: $msg, flags: FILE_APPEND);
            echo $msg;
            @flush();
        });
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, bool>
     */
    private function readKnownArchiveFulltext(array $urls): array
    {
        $result = [];
        foreach ($urls as $url) {
            $v = $this->cacheGet(key: 'archfull:' . md5(string: $url));
            if ($v === null) {
                continue;
            }
            $data = json_decode(json: $v, associative: true);
            if (is_array(value: $data) && isset($data['full'])) {
                $result[$url] = (bool) $data['full'];
            }
        }
        return $result;
    }

    private function storeArchiveSnapshot(string $originalUrl, string $body, string $finalUrl): void
    {
        if ($body === '') {
            return;
        }
        $cacheKey = md5(string: $originalUrl);
        // gzip the snapshot — typical archive.ph HTML compresses ~10×, so
        // the cache table drops from ~1.3 MB to ~120 KB per article.
        $compressed = gzcompress(data: $body, level: 6);
        $this->cacheSet(
            key: 'snapshot:' . $cacheKey,
            value: $compressed !== false ? $compressed : $body
        );

        $tld = null;
        if (preg_match(pattern: '#archive\.([a-z]+)/\d{14}/#', subject: $finalUrl, matches: $m) === 1) {
            $tld = $m[1];
        }
        $this->cacheSet(
            key: 'snapshotmeta:' . $cacheKey,
            value: (string) json_encode(
                value: [
                    'original_url' => $originalUrl,
                    'mirror_tld' => $tld,
                    'final_url' => $finalUrl,
                    'archived_at' => $this->extractArchivedAt(finalUrl: $finalUrl),
                    'fetched_at' => time()
                ]
            )
        );
    }

    /**
     * @param array<int, array{paper: string, item: FeedItem}> $items
     */
    private function resolveRedditImagesStreaming(array $items, callable $emit): void
    {
        $total = count(value: $items);
        $checked = 0;
        $ok = 0;
        foreach ($items as $entry) {
            $url = $entry['item']->link;
            $checked++;
            $resolved = $this->resolveRedditImageUrl(postUrl: $url);
            $this->writeOgImageCache(url: $url, imageUrl: $resolved ?? '');
            if ($resolved !== null) {
                $ok++;
            }
            $titleShort = mb_substr(string: $entry['item']->title, start: 0, length: 60);
            $emit(sprintf(
                '    [%3d/%d] %-5s %s',
                $checked,
                $total,
                $resolved !== null ? 'OK' : '----',
                $titleShort
            ));
        }
        $emit(sprintf('    Reddit: %d von %d aufgelöst', $ok, $total));
    }

    private function resolveRedditImageUrl(string $postUrl): ?string
    {
        $jsonUrl = rtrim(string: $postUrl, characters: '/') . '/.json';
        $result = $this->fetchViaImpersonate(url: $jsonUrl);
        if ($result->body === null || $result->body === '') {
            return null;
        }
        $data = json_decode(json: $result->body, associative: true);
        if (!is_array(value: $data)) {
            return null;
        }
        $post = $data[0]['data']['children'][0]['data'] ?? null;
        if (!is_array(value: $post)) {
            return null;
        }

        // Reddit (2026) redirects every i.redd.it / preview.redd.it /
        // external-preview.redd.it request to the /media HTML viewer when
        // hot-linked — hot-linking the actual image bytes is impossible
        // regardless of headers or cookies. So we explicitly REFUSE all
        // reddit-CDN URLs and only return things that another host can
        // serve directly: external image URLs, og:image of an external
        // article, or the subreddit branding image (on redditmedia.com,
        // which is a different CDN and still works).
        $isRedditCdn = static fn(string $u): bool => preg_match(
            pattern: '~^https?://(?:i|v|preview|external-preview)\.redd\.it/~i',
            subject: $u
        ) === 1;

        $destUrl = (string) ($post['url_overridden_by_dest'] ?? '');

        // (A) External direct-image link (e.g. https://i.imgur.com/x.jpg).
        if (
            $destUrl !== ''
            && !$isRedditCdn($destUrl)
            && preg_match(pattern: '~^https?://~i', subject: $destUrl) === 1
            && preg_match(pattern: '~\.(?:jpe?g|png|webp|gif)(?:\?|$)~i', subject: $destUrl) === 1
        ) {
            return $destUrl;
        }

        // (B) External link target → og:image of the destination page.
        $isInternal = $destUrl === ''
            || $isRedditCdn($destUrl)
            || preg_match(pattern: '~^https?://(?:www|old|new)\.reddit\.com/~i', subject: $destUrl) === 1;
        if (!$isInternal) {
            $og = $this->extractOgImageFromUrl(url: $destUrl);
            if ($og !== null && !$isRedditCdn($og)) {
                return $og;
            }
        }

        // (C) Plain `thumbnail` field — only when it's NOT a reddit CDN URL.
        $thumb = (string) ($post['thumbnail'] ?? '');
        if (
            $thumb !== ''
            && preg_match(pattern: '~^https?://~i', subject: $thumb) === 1
            && !$isRedditCdn($thumb)
        ) {
            return $thumb;
        }

        // (D) Subreddit branding (redditmedia.com — separate CDN, still
        //     serves bytes directly). One image per subreddit, generic but
        //     visually distinctive.
        $subreddit = trim(string: (string) ($post['subreddit'] ?? ''));
        if ($subreddit !== '') {
            $icon = $this->resolveSubredditIcon(subreddit: $subreddit);
            if ($icon !== null) {
                return $icon;
            }
        }

        return null;
    }

    private function resolveSubredditIcon(string $subreddit): ?string
    {
        static $cache = [];
        if (array_key_exists(key: $subreddit, array: $cache)) {
            return $cache[$subreddit];
        }
        $url = 'https://www.reddit.com/r/' . rawurlencode(string: $subreddit) . '/about.json';
        $result = $this->fetchViaImpersonate(url: $url);
        if ($result->body === null) {
            return $cache[$subreddit] = null;
        }
        $data = json_decode(json: $result->body, associative: true);
        $about = $data['data'] ?? null;
        if (!is_array(value: $about)) {
            return $cache[$subreddit] = null;
        }
        foreach (['icon_img', 'community_icon', 'header_img'] as $key) {
            $candidate = trim(string: (string) ($about[$key] ?? ''));
            if ($candidate === '') {
                continue;
            }
            $candidate = html_entity_decode(string: $candidate, flags: ENT_QUOTES);
            $stripped = strtok(string: $candidate, token: '?');
            return $cache[$subreddit] = ($stripped !== false ? $stripped : $candidate);
        }
        return $cache[$subreddit] = null;
    }

    private function extractOgImageFromUrl(string $url): ?string
    {
        $result = $this->fetchViaImpersonate(url: $url);
        if ($result->body === null || $result->body === '') {
            return null;
        }
        $body = substr(string: $result->body, offset: 0, length: 300000);
        // Try og:image first, then twitter:image as a fallback. Both
        // patterns accept `property=` and `name=` and the common variants
        // (`og:image:secure_url`, `twitter:image:src`).
        $patterns = [
            '~(?:property|name)=["\']og:image(?::secure_url)?["\'][^>]{0,200}content=["\']([^"\']+)~i',
            '~(?:property|name)=["\']twitter:image(?::src)?["\'][^>]{0,200}content=["\']([^"\']+)~i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match(pattern: $pattern, subject: $body, matches: $m) === 1) {
                $candidate = html_entity_decode(string: $m[1], flags: ENT_QUOTES);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }
        return null;
    }

    private function readOgImageCache(string $url): ?string
    {
        $v = $this->cacheGet(key: 'og:' . md5(string: $url));
        if ($v === null) {
            return null;
        }
        $data = json_decode(json: $v, associative: true);
        if (!is_array(value: $data) || !isset($data['url'])) {
            return null;
        }
        $u = (string) $data['url'];
        return $u === '' ? null : $u;
    }

    private function ogImageCacheExists(string $url): bool
    {
        return $this->cacheHas(key: 'og:' . md5(string: $url));
    }

    private function writeOgImageCache(string $url, string $imageUrl): void
    {
        if ($imageUrl !== '') {
            $imageUrl = html_entity_decode(string: $imageUrl, flags: ENT_QUOTES);
        }
        $this->cacheSet(
            key: 'og:' . md5(string: $url),
            value: (string) json_encode(value: ['url' => $imageUrl])
        );
    }

    /**
     * Body-image probe cache: separate from og-image cache so the deeper
     * "first <img> in the body" probe can record its result independently
     * of Phase 2's quick og:image/twitter:image grep. Once an article has
     * been probed (success OR exhaustion), it stays in this cache forever
     * so we don't keep re-fetching the same hopeless pages.
     */
    private function bodyImgCacheExists(string $url): bool
    {
        return $this->cacheHas(key: 'bodyimg:' . md5(string: $url));
    }

    private function readBodyImgCache(string $url): ?string
    {
        $v = $this->cacheGet(key: 'bodyimg:' . md5(string: $url));
        if ($v === null) {
            return null;
        }
        $data = json_decode(json: $v, associative: true);
        if (!is_array(value: $data) || !isset($data['url'])) {
            return null;
        }
        $u = (string) $data['url'];
        return $u === '' ? null : $u;
    }

    private function writeBodyImgCache(string $url, string $imageUrl): void
    {
        if ($imageUrl !== '') {
            $imageUrl = html_entity_decode(string: $imageUrl, flags: ENT_QUOTES);
        }
        $this->cacheSet(
            key: 'bodyimg:' . md5(string: $url),
            value: (string) json_encode(value: ['url' => $imageUrl])
        );
    }

    private function writeArchiveFulltextCache(string $url, bool $full): void
    {
        $this->cacheSet(
            key: 'archfull:' . md5(string: $url),
            value: (string) json_encode(value: ['full' => $full])
        );
    }

    /**
     * @param array<int, array{paper: string, item: FeedItem}> $items
     * @return array<string, bool>
     */
    private function checkArchiveFulltextStreaming(array $items, callable $emit): array
    {
        if (empty($items)) {
            return [];
        }

        $minChars = 8000;
        $papersConfig = $this->papers();

        $byUrl = [];
        foreach ($items as $entry) {
            $byUrl[$entry['item']->link] = $entry;
        }

        $tmpDir = sys_get_temp_dir() . '/extrablatt-arch-' . getmypid();
        if (!is_dir(filename: $tmpDir)) {
            mkdir(directory: $tmpDir, permissions: 0700, recursive: true);
        }

        $tmpIn = tempnam(directory: sys_get_temp_dir(), prefix: 'arch-full-in-');
        $lines = [];
        foreach ($items as $entry) {
            $key = md5(string: $entry['item']->link);
            $lines[] = $entry['item']->link . "\t" . $tmpDir . '/' . $key . '.html';
        }
        file_put_contents(filename: $tmpIn, data: implode(separator: "\n", array: $lines));

        $cookieHeader = $this->buildCookieHeader(targetUrl: 'https://archive.ph/');

        $innerCmd =
            'src=$(echo "$2" | cut -f1); ' .
            'dst=$(echo "$2" | cut -f2); ' .
            'final=$(' . escapeshellarg(arg: $this->curlImpersonateBin) .
            ' -sL -H "$1" --max-time 30 --max-redirs 5 ' .
            '-w "%{url_effective}" -o "$dst" "https://archive.ph/newest/$src" 2>/dev/null); ' .
            'sz=$(stat -c%s "$dst" 2>/dev/null || echo 0); ' .
            'echo "SIZE:$sz|FINAL:$final|FILE:$dst|URL:$src"';

        $cmd = $this->buildParallelPipeline(
            tmpIn: $tmpIn,
            innerCmd: $innerCmd,
            concurrency: self::ARCHIVE_CHECK_CONCURRENCY,
            extraArg: 'Cookie: ' . $cookieHeader
        );

        $pipe = popen(command: $cmd . ' 2>/dev/null', mode: 'r');
        if ($pipe === false) {
            unlink(filename: $tmpIn);
            return [];
        }

        $result = [];
        $total = count(value: $items);
        $checked = 0;

        while (($line = fgets(stream: $pipe)) !== false) {
            $line = trim(string: $line);
            if ($line === '') {
                continue;
            }
            if (!preg_match(pattern: '~^SIZE:(\d+)\|FINAL:(.*?)\|FILE:(.+?)\|URL:(.+)$~', subject: $line, matches: $m)) {
                continue;
            }
            $size = (int) $m[1];
            $finalUrl = $m[2];
            $file = $m[3];
            $url = $m[4];
            $entry = $byUrl[$url] ?? null;
            $paper = $entry['paper'] ?? '';
            $checked++;

            if ($size === 0 || !file_exists(filename: $file)) {
                $emit(sprintf('  [%3d/%d] %-14s skip  (kein HTML)', $checked, $total, $paper));
                @unlink(filename: $file);
                continue;
            }

            $html = (string) file_get_contents(filename: $file);

            $markers = is_array(value: $papersConfig[$paper]['stub_markers'] ?? null)
                ? $papersConfig[$paper]['stub_markers']
                : [];
            $isFull = $this->analyzeArchiveFulltext(
                html: $html,
                paper: $paper,
                minChars: $minChars,
                markers: $markers
            );
            $result[$url] = $isFull;
            $this->writeArchiveFulltextCache(url: $url, full: $isFull);

            if ($isFull && $this->extractArchivedAt(finalUrl: $finalUrl) !== null) {
                $this->storeArchiveSnapshot(originalUrl: $url, body: $html, finalUrl: $finalUrl);
            }
            @unlink(filename: $file);

            $titleShort = $entry !== null ? mb_substr(string: $entry['item']->title, start: 0, length: 60) : '';
            $emit(sprintf(
                '  [%3d/%d] %-14s %-9s %s',
                $checked,
                $total,
                $paper,
                $isFull ? 'VOLLTEXT' : 'gekürzt',
                $titleShort
            ));
        }

        pclose(handle: $pipe);
        unlink(filename: $tmpIn);
        @rmdir(directory: $tmpDir);

        return $result;
    }

    /**
     * @param array<int, string> $markers
     */
    private function analyzeArchiveFulltext(string $html, string $paper, int $minChars, array $markers): bool
    {
        foreach ($markers as $marker) {
            if ($marker !== '' && stripos(haystack: $html, needle: (string) $marker) !== false) {
                return false;
            }
        }
        $clean = preg_replace(pattern: '~<script\b[^>]*>.*?</script>~is', replacement: '', subject: $html) ?? $html;
        $clean = preg_replace(pattern: '~<style\b[^>]*>.*?</style>~is', replacement: '', subject: $clean) ?? $clean;
        $text = trim(string: (string) preg_replace(
            pattern: '/\s+/',
            replacement: ' ',
            subject: strip_tags(string: $clean)
        ));
        return strlen(string: $text) >= $minChars;
    }

    /**
     * @param array<int, string> $urls
     * @return array<int, string>
     */
    private function readArticlesWithThumbnail(PDO $db, array $urls): array
    {
        if (empty($urls)) {
            return [];
        }
        $placeholders = implode(separator: ',', array: array_fill(start_index: 0, count: count(value: $urls), value: '?'));
        $stmt = $db->prepare(
            query: 'SELECT url FROM articles WHERE url IN (' . $placeholders . ') AND thumbnail IS NOT NULL'
        );
        $stmt->execute(params: $urls);
        return array_map(callback: 'strval', array: $stmt->fetchAll(mode: PDO::FETCH_COLUMN, column: 0) ?: []);
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, string>
     */
    private function readKnownCategories(PDO $db, array $urls): array
    {
        if (empty($urls)) {
            return [];
        }
        $placeholders = implode(separator: ',', array: array_fill(start_index: 0, count: count(value: $urls), value: '?'));
        $stmt = $db->prepare(
            query: 'SELECT url, category FROM articles WHERE url IN (' . $placeholders . ') AND category IS NOT NULL'
        );
        $stmt->execute(params: $urls);
        $result = [];
        while ($row = $stmt->fetch(mode: PDO::FETCH_ASSOC)) {
            $result[(string) $row['url']] = (string) $row['category'];
        }
        return $result;
    }

    /**
     * @param array<int, array{paper: string, item: FeedItem}> $items
     * @param array<string, string> $imageUrls
     * @return array<string, ?string>
     */
    /**
     * Build a shell pipeline that runs $innerCmd for every line in $tmpIn
     * with bounded concurrency, without depending on xargs. Some shared
     * hosts deny exec on /usr/bin/xargs but allow popen/shell_exec on plain
     * sh — this helper keeps the same `sh -c ... _ [extra] $line` calling
     * convention so existing inner-command bodies need no change.
     */
    private function buildParallelPipeline(string $tmpIn, string $innerCmd, int $concurrency, ?string $extraArg = null): string
    {
        $extra = $extraArg !== null ? escapeshellarg(arg: $extraArg) . ' ' : '';
        return sprintf(
            'n=0; while IFS= read -r line; do sh -c %s _ %s"$line" & n=$((n+1)); [ $((n %% %d)) -eq 0 ] && wait; done < %s; wait',
            escapeshellarg(arg: $innerCmd),
            $extra,
            $concurrency,
            escapeshellarg(arg: $tmpIn)
        );
    }

    private function downloadThumbnailsStreaming(array $items, array $imageUrls, callable $emit): array
    {
        if (empty($items)) {
            return [];
        }

        $byUrl = [];
        foreach ($items as $entry) {
            $byUrl[$entry['item']->link] = $entry;
        }
        $tmpDir = sys_get_temp_dir() . '/extrablatt-thumb-' . getmypid();
        if (!is_dir(filename: $tmpDir)) {
            mkdir(directory: $tmpDir, permissions: 0700, recursive: true);
        }

        $tmpIn = tempnam(directory: sys_get_temp_dir(), prefix: 'thumb-in-');
        $lines = [];
        foreach ($items as $entry) {
            $articleUrl = $entry['item']->link;
            $imageUrl = (string) ($imageUrls[$articleUrl] ?? '');
            if ($imageUrl === '') {
                continue;
            }
            $key = md5(string: $articleUrl);
            $lines[] = $imageUrl . "\t" . $tmpDir . '/' . $key . '.bin' . "\t" . $articleUrl;
        }
        if ($lines === []) {
            $emit('  ⚠️  keine Bild-URL für die Kandidaten verfügbar');
            @unlink(filename: $tmpIn);
            return [];
        }
        file_put_contents(filename: $tmpIn, data: implode(separator: "\n", array: $lines));

        $innerCmd =
            'src=$(echo "$1" | cut -f1); ' .
            'dst=$(echo "$1" | cut -f2); ' .
            'lnk=$(echo "$1" | cut -f3); ' .
            escapeshellarg(arg: $this->curlImpersonateBin) .
            ' -sL --max-redirs 5 --max-time 15 --max-filesize ' . self::THUMBNAIL_MAX_SOURCE_BYTES .
            ' "$src" -o "$dst" 2>/dev/null; ' .
            'sz=$(stat -c%s "$dst" 2>/dev/null || echo 0); ' .
            'echo "SIZE:$sz|DST:$dst|URL:$lnk"';

        $cmd = $this->buildParallelPipeline(
            tmpIn: $tmpIn,
            innerCmd: $innerCmd,
            concurrency: self::ARCHIVE_CHECK_CONCURRENCY
        );

        // Capture stderr too — silent failures used to swallow the whole phase.
        $pipe = popen(command: $cmd . ' 2>&1', mode: 'r');
        if ($pipe === false) {
            $emit('  ⚠️  popen für Thumbnail-Pipeline fehlgeschlagen');
            @unlink(filename: $tmpIn);
            return [];
        }

        $result = [];
        $total = count(value: $items);
        $checked = 0;

        while (($line = fgets(stream: $pipe)) !== false) {
            $line = trim(string: $line);
            if ($line === '') {
                continue;
            }
            if (!preg_match(pattern: '~^SIZE:(\d+)\|DST:(.+?)\|URL:(.+)$~', subject: $line, matches: $m)) {
                // Non-SIZE output = stderr from xargs/sh/curl. Surface it.
                $emit('  ⚠️  Pipeline-stderr: ' . mb_substr(string: $line, start: 0, length: 200));
                continue;
            }
            $size = (int) $m[1];
            $dst = $m[2];
            $url = $m[3];
            $checked++;

            $dataUri = $size > 0 ? $this->resizeImageToDataUri(sourcePath: $dst) : null;
            $result[$url] = $dataUri;
            @unlink(filename: $dst);

            $entry = $byUrl[$url] ?? null;
            $paper = $entry['paper'] ?? '?';
            $titleShort = $entry !== null ? mb_substr(string: $entry['item']->title, start: 0, length: 60) : '';
            $emit(sprintf(
                '  [%3d/%d] %-14s %s %s',
                $checked,
                $total,
                $paper,
                $dataUri !== null ? 'OK   ' : 'fail ',
                $titleShort
            ));
        }

        pclose(handle: $pipe);
        unlink(filename: $tmpIn);
        @rmdir(directory: $tmpDir);

        return $result;
    }

    private function resizeImageToDataUri(string $sourcePath): ?string
    {
        if (!file_exists(filename: $sourcePath) || filesize(filename: $sourcePath) === 0) {
            return null;
        }
        $raw = (string) @file_get_contents(filename: $sourcePath);
        if ($raw === '') {
            return null;
        }
        $source = @imagecreatefromstring(data: $raw);
        if ($source === false) {
            return null;
        }
        $srcW = imagesx(image: $source);
        $srcH = imagesy(image: $source);
        if ($srcW <= 0 || $srcH <= 0) {
            return null;
        }

        $side = min($srcW, $srcH);
        $cropX = (int) (($srcW - $side) / 2);
        $cropY = (int) (($srcH - $side) / 2);

        $dst = imagecreatetruecolor(width: self::THUMBNAIL_SIZE, height: self::THUMBNAIL_SIZE);
        if ($dst === false) {
            return null;
        }
        imagecopyresampled(
            dst_image: $dst,
            src_image: $source,
            dst_x: 0,
            dst_y: 0,
            src_x: $cropX,
            src_y: $cropY,
            dst_width: self::THUMBNAIL_SIZE,
            dst_height: self::THUMBNAIL_SIZE,
            src_width: $side,
            src_height: $side
        );

        ob_start();
        imagejpeg(image: $dst, file: null, quality: self::THUMBNAIL_JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();

        if ($jpeg === '') {
            return null;
        }
        return 'data:image/jpeg;base64,' . base64_encode(string: $jpeg);
    }

    /**
     * @param array<int, array{paper: string, item: FeedItem}> $items
     * @param array<int, string> $categories
     * @param array<string, mixed> $aiConfig
     * @return array<string, string>
     */
    private function categorizeArticlesStreaming(array $items, array $categories, array $aiConfig, string $apiKey, callable $emit): array
    {
        if (!class_exists(class: 'vielhuber\\aihelper\\aihelper')) {
            $emit('  ⚠️  vielhuber\\aihelper\\aihelper-Klasse nicht verfügbar — `composer install` ausführen');
            return [];
        }

        $aiClass = 'vielhuber\\aihelper\\aihelper';
        $ai = $aiClass::create(
            provider: (string) ($aiConfig['provider'] ?? 'anthropic'),
            model: (string) ($aiConfig['model'] ?? 'claude-haiku-4-5-20251001'),
            temperature: (float) ($aiConfig['temperature'] ?? 0.0),
            api_key: $apiKey,
            max_tries: (int) ($aiConfig['max_tries'] ?? 2),
            timeout: (int) ($aiConfig['timeout'] ?? 60)
        );

        $categoryList = implode(separator: ', ', array: $categories);
        $result = [];
        $total = count(value: $items);
        $checked = 0;
        $fromCache = 0;
        $fromAi = 0;

        foreach ($items as $entry) {
            $item = $entry['item'];
            $checked++;

            if ($this->categoryCacheExists(title: $item->title)) {
                $category = $this->readCategoryCache(title: $item->title);
                $fromCache++;
                if ($category !== null) {
                    $result[$item->link] = $category;
                }
                $titleShort = mb_substr(string: $item->title, start: 0, length: 50);
                $emit(sprintf(
                    '  [%3d/%d] %-14s %-22s %s',
                    $checked,
                    $total,
                    $entry['paper'],
                    ($category ?? '?unkategorisiert') . ' (cache)',
                    $titleShort
                ));
                continue;
            }

            $prompt =
                "Ordne den folgenden Nachrichtenartikel-Titel **einer** der genannten Kategorien zu.\n\n" .
                "Verfügbare Kategorien: " . $categoryList . "\n\n" .
                "Zeitung: " . $entry['paper'] . "\n" .
                "Titel: " . $item->title . "\n\n" .
                "Antworte ausschließlich mit dem exakten Kategorie-Namen aus der Liste, ohne weiteren Text.";

            try {
                $response = $ai->ask(prompt: $prompt);
                $raw = trim(string: (string) ($response['response'] ?? ''));
                $category = $this->matchCategory(raw: $raw, categories: $categories);
            } catch (\Throwable $e) {
                $category = null;
            }

            $this->writeCategoryCache(title: $item->title, category: $category);
            $fromAi++;

            if ($category !== null) {
                $result[$item->link] = $category;
            }

            $titleShort = mb_substr(string: $item->title, start: 0, length: 50);
            $emit(sprintf(
                '  [%3d/%d] %-14s %-22s %s',
                $checked,
                $total,
                $entry['paper'],
                $category ?? '?unkategorisiert',
                $titleShort
            ));
        }

        $emit(sprintf('  → AI-Calls: %d, Cache-Hits: %d', $fromAi, $fromCache));
        return $result;
    }

    private function readCategoryCache(string $title): ?string
    {
        $v = $this->cacheGet(key: 'category:' . md5(string: $this->normalizeTitle(title: $title)));
        if ($v === null) {
            return null;
        }
        $data = json_decode(json: $v, associative: true);
        if (!is_array(value: $data) || !array_key_exists(key: 'category', array: $data)) {
            return null;
        }
        $category = $data['category'];
        return $category === null ? null : (string) $category;
    }

    private function categoryCacheExists(string $title): bool
    {
        return $this->cacheHas(key: 'category:' . md5(string: $this->normalizeTitle(title: $title)));
    }

    private function writeCategoryCache(string $title, ?string $category): void
    {
        $this->cacheSet(
            key: 'category:' . md5(string: $this->normalizeTitle(title: $title)),
            value: (string) json_encode(value: ['category' => $category])
        );
    }

    /**
     * @param array<int, string> $categories
     */
    private function matchCategory(string $raw, array $categories): ?string
    {
        if ($raw === '') {
            return null;
        }
        $needle = mb_strtolower(string: $raw);
        foreach ($categories as $category) {
            if ($needle === mb_strtolower(string: $category)) {
                return $category;
            }
        }
        foreach ($categories as $category) {
            if (str_contains(haystack: $needle, needle: mb_strtolower(string: $category))) {
                return $category;
            }
        }
        return null;
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, bool>
     */
    private function readKnownPaywallStatus(PDO $db, array $urls): array
    {
        if (empty($urls)) {
            return [];
        }
        $placeholders = implode(separator: ',', array: array_fill(start_index: 0, count: count(value: $urls), value: '?'));
        $stmt = $db->prepare(
            query: 'SELECT url, paywall FROM articles WHERE url IN (' . $placeholders . ') AND paywall IS NOT NULL'
        );
        $stmt->execute(params: $urls);
        $result = [];
        while ($row = $stmt->fetch(mode: PDO::FETCH_ASSOC)) {
            $result[(string) $row['url']] = ((int) $row['paywall']) === 1;
        }
        return $result;
    }

    /**
     * @param array<int, array{paper: string, item: FeedItem}> $items
     * @return array<string, bool>
     */
    private function checkPaywallStatusStreaming(array $items, callable $emit): array
    {
        if (empty($items)) {
            return [];
        }

        $byUrl = [];
        foreach ($items as $entry) {
            $byUrl[$entry['item']->link] = $entry;
        }
        $urls = array_keys(array: $byUrl);

        $tmpIn = tempnam(directory: sys_get_temp_dir(), prefix: 'pw-in-');
        if ($tmpIn === false) {
            return [];
        }
        file_put_contents(filename: $tmpIn, data: implode(separator: "\n", array: $urls));

        $paywallPattern = '"isAccessibleForFree"[[:space:]:,]+"?[fF]alse|article:content_tier"[[:space:]]+content="locked';
        // og:image first, twitter:image as fallback (Mozilla blogs and some
        // Substack templates only emit twitter:image). The patterns also
        // tolerate `name=` vs `property=` since some sites use the wrong
        // attribute for these meta tags.
        $ogPattern = '(?:property|name)=["\']og:image(?::secure_url)?["\'][^>]{0,200}content=["\']\K[^"\']+';
        $twPattern = '(?:property|name)=["\']twitter:image(?::src)?["\'][^>]{0,200}content=["\']\K[^"\']+';
        $innerCmd =
            'body=$(' . escapeshellarg(arg: $this->curlImpersonateBin) .
            ' -sL --max-redirs 5 --max-time 12 "$1" 2>/dev/null | head -c 300000); ' .
            'pw=$(printf "%s" "$body" | grep -ciE ' . escapeshellarg(arg: $paywallPattern) . ' || true); ' .
            'og=$(printf "%s" "$body" | grep -oP ' . escapeshellarg(arg: $ogPattern) . ' 2>/dev/null | head -1); ' .
            'if [ -z "$og" ]; then og=$(printf "%s" "$body" | grep -oP ' . escapeshellarg(arg: $twPattern) . ' 2>/dev/null | head -1); fi; ' .
            'echo "PAYWALL:${pw:-0}|OGIMG:${og}|URL:$1"';

        $cmd = $this->buildParallelPipeline(
            tmpIn: $tmpIn,
            innerCmd: $innerCmd,
            concurrency: self::ARCHIVE_CHECK_CONCURRENCY
        );

        $pipe = popen(command: $cmd . ' 2>/dev/null', mode: 'r');
        if ($pipe === false) {
            unlink(filename: $tmpIn);
            return [];
        }

        $result = [];
        $total = count(value: $urls);
        $checked = 0;

        while (($line = fgets(stream: $pipe)) !== false) {
            $line = trim(string: $line);
            if ($line === '') {
                continue;
            }
            if (!preg_match(pattern: '~^PAYWALL:(\d+)\|OGIMG:(.*?)\|URL:(.+)$~', subject: $line, matches: $m)) {
                continue;
            }
            $isPaywall = ((int) $m[1]) > 0;
            $ogImage = trim(string: $m[2]);
            $url = $m[3];
            $result[$url] = $isPaywall;
            // Skip the og:image side-effect for reddit posts — the dedicated
            // Phase-5 resolver parses the post JSON and pulls a much better
            // thumbnail (signed preview URL, gallery slide, direct image).
            // Writing here would otherwise stamp an empty cache for every
            // reddit URL whose og:image grep misses, which then blocks the
            // proper resolver from running at all.
            if (strpos(haystack: $url, needle: 'reddit.com/') === false) {
                $this->writeOgImageCache(url: $url, imageUrl: $ogImage);
            }
            $checked++;
            $entry = $byUrl[$url] ?? null;
            $paper = $entry['paper'] ?? '?';
            $titleShort = $entry !== null ? mb_substr(string: $entry['item']->title, start: 0, length: 60) : '';
            $emit(sprintf(
                '  [%3d/%d] %-14s %-5s %s',
                $checked,
                $total,
                $paper,
                $isPaywall ? 'PLUS' : 'free',
                $titleShort
            ));
        }

        pclose(handle: $pipe);
        unlink(filename: $tmpIn);

        return $result;
    }

    private function totalArticleCount(PDO $db): int
    {
        return (int) $db->query(query: 'SELECT COUNT(*) FROM articles')->fetchColumn();
    }

    /**
     * Aggregate user signals plus external-rating statistics per paper and
     * per category. Used as input to the magic-bucket scoring pass.
     *
     * Affinity = Bayesian-smoothed average curator vote + 0.5 × read-rate.
     * The smoothing prior (3 phantom zero-votes) keeps a single +3 on an
     * otherwise unrated paper from dominating the ranking.
     *
     * Per-paper rating stats (mean + sd) let downstream scoring z-normalise
     * the article's external rating so HN points, Reddit scores and X
     * engagement counts become comparable across sources.
     *
     * @return array{
     *   paper: array<string, array{affinity: float, avg_rating: float, rating_sd: float, n_votes: int}>,
     *   category: array<string, array{affinity: float, n_votes: int}>
     * }
     */
    private function magicComputeAffinity(PDO $db): array
    {
        $paperAff = [];
        foreach ((array) $db->query(query: '
            SELECT paper,
                   COUNT(*) AS total,
                   SUM(CASE WHEN vote != 0 THEN 1 ELSE 0 END) AS n_votes,
                   COALESCE(SUM(vote), 0) AS sum_vote,
                   SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) AS reads,
                   AVG(rating) AS avg_rating,
                   COUNT(rating) AS n_rated,
                   COALESCE(SUM(rating * rating), 0) AS sum_sq_rating
            FROM articles
            GROUP BY paper
        ')->fetchAll(mode: PDO::FETCH_ASSOC) as $row) {
            $nVotes = (int) $row['n_votes'];
            $sumVote = (float) $row['sum_vote'];
            // Bayesian shrinkage toward 0 — a paper needs sustained signal
            // to climb away from neutral.
            $smoothedVote = $sumVote / max(1, $nVotes + 3);
            $total = (int) $row['total'];
            $readRate = $total > 0 ? ((int) $row['reads']) / (float) $total : 0.0;
            $affinity = $smoothedVote + 0.5 * $readRate;

            $nRated = (int) $row['n_rated'];
            $avgRating = $nRated > 0 ? (float) $row['avg_rating'] : 0.0;
            $sumSq = (float) $row['sum_sq_rating'];
            $variance = $nRated > 1 ? max(0.0, ($sumSq / $nRated) - ($avgRating * $avgRating)) : 0.0;

            $paperAff[(string) $row['paper']] = [
                'affinity' => $affinity,
                'avg_rating' => $avgRating,
                'rating_sd' => sqrt(num: $variance),
                'n_votes' => $nVotes
            ];
        }

        $categoryAff = [];
        foreach ((array) $db->query(query: '
            SELECT category,
                   COUNT(*) AS total,
                   SUM(CASE WHEN vote != 0 THEN 1 ELSE 0 END) AS n_votes,
                   COALESCE(SUM(vote), 0) AS sum_vote,
                   SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) AS reads
            FROM articles
            WHERE category IS NOT NULL
            GROUP BY category
        ')->fetchAll(mode: PDO::FETCH_ASSOC) as $row) {
            $nVotes = (int) $row['n_votes'];
            $sumVote = (float) $row['sum_vote'];
            $smoothedVote = $sumVote / max(1, $nVotes + 3);
            $total = (int) $row['total'];
            $readRate = $total > 0 ? ((int) $row['reads']) / (float) $total : 0.0;
            $categoryAff[(string) $row['category']] = [
                'affinity' => $smoothedVote + 0.5 * $readRate,
                'n_votes' => $nVotes
            ];
        }

        return ['paper' => $paperAff, 'category' => $categoryAff];
    }

    /**
     * Composite relevance score for one article row. Higher = better fit.
     *
     * @param array<string, mixed> $row
     * @param array{paper: array<string, array{affinity: float, avg_rating: float, rating_sd: float, n_votes: int}>, category: array<string, array{affinity: float, n_votes: int}>} $aff
     */
    private function magicScore(array $row, array $aff): float
    {
        $paper = (string) ($row['paper'] ?? '');
        $cat = (string) ($row['category'] ?? '');

        // 1. Personal taste signals transferred via paper + category.
        $score = 1.2 * (float) ($aff['paper'][$paper]['affinity'] ?? 0.0);
        $score += 1.8 * (float) ($aff['category'][$cat]['affinity'] ?? 0.0);

        // 2. Own vote on this article — strongest signal when present.
        $score += 3.0 * (float) ($row['vote'] ?? 0);

        // 3. External rating normalised within its paper (z-score) and
        //    compressed via tanh so a single viral post can't blow up the
        //    ranking. Papers without rating data contribute 0.
        if ($row['rating'] !== null) {
            $rating = (float) $row['rating'];
            $mean = (float) ($aff['paper'][$paper]['avg_rating'] ?? 0.0);
            $sd = (float) ($aff['paper'][$paper]['rating_sd'] ?? 0.0);
            if ($sd > 0.0) {
                $score += 0.6 * tanh(num: ($rating - $mean) / $sd);
            }
        }

        // 4. Recency penalty — ~0.08 per day, decays older posts gently.
        $published = $row['published_at'] !== null ? (int) $row['published_at'] : time();
        $ageDays = max(0.0, (time() - $published) / 86400.0);
        $score -= 0.08 * $ageDays;

        return $score;
    }

    /**
     * Greedy diversity selection from a pre-sorted list. Walks through the
     * input in score order and picks each item only while its paper hasn't
     * hit the cap. If fewer than $count items survive, remaining slots get
     * filled from the leftovers (still highest-scoring first) regardless of
     * the cap — so the result is always exactly $count when the input pool
     * is large enough.
     *
     * @param array<int, array<string, mixed>> $sorted
     * @return array<int, array<string, mixed>>
     */
    private function magicDiversitySelect(array $sorted, int $count, int $maxPerPaper): array
    {
        $picked = [];
        $perPaper = [];
        $leftovers = [];
        foreach ($sorted as $row) {
            $paper = (string) ($row['paper'] ?? '');
            $perPaper[$paper] = $perPaper[$paper] ?? 0;
            if ($perPaper[$paper] < $maxPerPaper) {
                $picked[] = $row;
                $perPaper[$paper]++;
                if (count(value: $picked) >= $count) {
                    return $picked;
                }
            } else {
                $leftovers[] = $row;
            }
        }
        foreach ($leftovers as $row) {
            if (count(value: $picked) >= $count) {
                break;
            }
            $picked[] = $row;
        }
        return $picked;
    }

    /**
     * Duplicate detection via title embeddings (Google text-embedding-004).
     * Each article gets a 768-dim vector computed once and stored as BLOB.
     * For every new (= not-yet-checked) article we run a cosine similarity
     * sweep over the existing canonicals in the 7-day window; on a hit above
     * the threshold the new article gets duplicate_of set to that canonical
     * (chain followed to the root). Articles that don't match end up as new
     * canonicals themselves and become matchable for subsequent candidates.
     *
     * Wall-time: dominated by the embed-API call (~30-50 ms per request when
     * batched), cosine pass over 7k vectors is ~50 ms in pure PHP.
     *
     * @param array<string, mixed> $aiConfig
     */
    private function clusterDuplicates(PDO $db, array $aiConfig, string $apiKey, callable $emit): void
    {
        $cutoff = time() - 7 * 86400;
        $threshold = 0.85;
        $embedModel = 'gemini-embedding-001';

        // 1. Backfill embeddings for any article in the window that doesn't
        // have one yet — both fresh imports from this scrape and existing
        // articles from before this feature was added.
        $missing = (array) $db->query(query: "
            SELECT url, paper, title
            FROM articles
            WHERE published_at IS NOT NULL
              AND published_at > {$cutoff}
              AND embedding IS NULL
            ORDER BY published_at DESC
        ")->fetchAll(mode: PDO::FETCH_ASSOC);
        if ($missing !== []) {
            $emit(sprintf('  %d Artikel ohne Embedding → berechnen', count(value: $missing)));
            $stmt = $db->prepare(query: 'UPDATE articles SET embedding = :emb WHERE url = :url');
            $chunks = array_chunk(array: $missing, length: 100);
            $written = 0;
            foreach ($chunks as $chunkIdx => $chunk) {
                $texts = [];
                foreach ($chunk as $i => $r) {
                    $texts[$i] = '[' . (string) $r['paper'] . '] ' .
                        mb_substr(string: (string) $r['title'], start: 0, length: 300);
                }
                $batchError = null;
                $vectors = $this->computeEmbeddingsBatch(
                    texts: $texts,
                    apiKey: $apiKey,
                    model: $embedModel,
                    error: $batchError
                );
                $batchOk = 0;
                foreach ($vectors as $i => $vec) {
                    if ($vec === null) {
                        continue;
                    }
                    $stmt->execute(params: [
                        ':emb' => pack('f*', ...$vec),
                        ':url' => (string) $chunk[$i]['url']
                    ]);
                    $written++;
                    $batchOk++;
                }
                $emit(sprintf('  Embedding-Batch %d/%d: %d Vektoren (gesamt %d)', $chunkIdx + 1, count(value: $chunks), $batchOk, $written));
                if ($batchOk === 0) {
                    // API call failed — bail rather than spam more failing
                    // requests. The successfully embedded articles up to this
                    // point are already in the DB; next scrape resumes from
                    // there.
                    if ($batchError !== null) {
                        $emit('  ⚠️  ' . $batchError);
                    }
                    $emit('  ⚠️  Embedding-API liefert nichts mehr — Backfill abgebrochen, wird beim nächsten Lauf fortgesetzt');
                    break;
                }
                if (count(value: $chunks) > 1) {
                    usleep(microseconds: 100000);
                }
            }
            $emit(sprintf('  → %d Embeddings gespeichert', $written));
        }

        // 2. Stream every embedding-equipped article in the window. We hold
        // raw blobs (3 KB each at 768 dims) rather than unpacked PHP float
        // arrays (~80 KB each) — PHP's per-element array overhead would
        // otherwise blow past the shared-host memory limit at N=9000+.
        // Canonicals (duplicate_of IS NULL, dedup_checked_at IS NOT NULL)
        // form the match pool; new candidates (dedup_checked_at IS NULL)
        // drive the loop.
        $stmt = $db->query(query: "
            SELECT url, paper, published_at, duplicate_of, dedup_checked_at, embedding
            FROM articles
            WHERE published_at IS NOT NULL
              AND published_at > {$cutoff}
              AND embedding IS NOT NULL
            ORDER BY published_at ASC
        ");
        $pool = [];
        $candidates = [];
        while ($r = $stmt->fetch(mode: PDO::FETCH_ASSOC)) {
            $row = [
                'url' => (string) $r['url'],
                'paper' => (string) $r['paper'],
                'publishedAt' => (int) $r['published_at'],
                'blob' => (string) $r['embedding']
            ];
            if ($r['dedup_checked_at'] === null) {
                $candidates[] = $row;
            } elseif ($r['duplicate_of'] === null) {
                $pool[] = $row;
            }
        }
        unset($stmt);
        if ($candidates === []) {
            $emit('  → keine neuen Kandidaten');
            return;
        }
        $emit(sprintf('  %d neue Kandidaten vs. %d bestehende canonicals', count(value: $candidates), count(value: $pool)));

        // 3. For each candidate find the closest canonical. Unpacks happen
        // inline so the float arrays live only for one cosine call and get
        // GC'd straight after. Above threshold → duplicate; otherwise the
        // candidate becomes a canonical itself and joins the pool for the
        // rest of this run.
        $socialPapers = ['hackernews' => 1, 'reddit' => 1, 'x' => 1];
        $update = $db->prepare(query: 'UPDATE articles SET duplicate_of = :dup WHERE url = :url');
        $checkStmt = $db->prepare(query: 'UPDATE articles SET dedup_checked_at = :ts WHERE url = :url');
        $now = time();
        $dupsWritten = 0;
        foreach ($candidates as $cand) {
            $candVec = array_values(array: (array) unpack(format: 'f*', string: $cand['blob']));
            $bestSim = 0.0;
            $bestIdx = null;
            foreach ($pool as $i => $p) {
                $pVec = array_values(array: (array) unpack(format: 'f*', string: $p['blob']));
                $sim = $this->cosineSimilarity(a: $candVec, b: $pVec);
                if ($sim > $bestSim) {
                    $bestSim = $sim;
                    $bestIdx = $i;
                }
            }
            if ($bestIdx !== null && $bestSim >= $threshold) {
                $canonical = $pool[$bestIdx];
                // Swap canonical if the candidate is non-social and the
                // current canonical is social — preserves the rule that a
                // real publication beats a Reddit/X reshare.
                $candSocial = isset($socialPapers[$cand['paper']]) ? 1 : 0;
                $canonSocial = isset($socialPapers[$canonical['paper']]) ? 1 : 0;
                if ($candSocial === 0 && $canonSocial === 1) {
                    $update->execute(params: [':dup' => $cand['url'], ':url' => $canonical['url']]);
                    $pool[$bestIdx] = $cand;
                } else {
                    $update->execute(params: [':dup' => $canonical['url'], ':url' => $cand['url']]);
                }
                $dupsWritten++;
            } else {
                $pool[] = $cand;
            }
            $checkStmt->execute(params: [':ts' => $now, ':url' => $cand['url']]);
        }
        $emit(sprintf('  → %d Duplikate per Embedding markiert (Threshold %.2f)', $dupsWritten, $threshold));
    }

    /**
     * One round-trip to Google's batchEmbedContents endpoint. Returns an
     * array keyed identically to $texts; entries that the API didn't return
     * a vector for stay null. $error is set to a human-readable message
     * when something went wrong (curl error, non-200 HTTP, API error body).
     *
     * @param array<int|string, string> $texts
     * @return array<int|string, array<int, float>|null>
     */
    private function computeEmbeddingsBatch(array $texts, string $apiKey, string $model, ?string &$error = null): array
    {
        $error = null;
        $result = [];
        foreach (array_keys(array: $texts) as $k) {
            $result[$k] = null;
        }
        if ($texts === []) {
            return $result;
        }
        $keys = array_keys(array: $texts);
        // gemini-embedding-001 defaults to 3072 dims — too heavy to fit
        // thousands of vectors in PHP memory on a shared host. 768 dims is
        // the official Matryoshka cut-off that still gives near-3072 recall
        // for short-text similarity.
        $requests = [];
        foreach ($keys as $k) {
            $requests[] = [
                'model' => 'models/' . $model,
                'content' => ['parts' => [['text' => $texts[$k]]]],
                'outputDimensionality' => 768
            ];
        }
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model
            . ':batchEmbedContents?key=' . urlencode(string: $apiKey);
        $ch = curl_init(url: $url);
        curl_setopt_array(handle: $ch, options: [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(value: ['requests' => $requests]),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        $raw = curl_exec(handle: $ch);
        $curlErr = curl_error(handle: $ch);
        $http = (int) curl_getinfo(handle: $ch, option: CURLINFO_HTTP_CODE);
        curl_close(handle: $ch);
        if (!is_string(value: $raw)) {
            $error = sprintf('Embedding curl-Fehler: %s', $curlErr !== '' ? $curlErr : 'unbekannt');
            return $result;
        }
        if ($http !== 200) {
            $snippet = mb_substr(string: $raw, start: 0, length: 400);
            $error = sprintf('Embedding HTTP %d: %s', $http, $snippet);
            return $result;
        }
        $decoded = json_decode(json: $raw, associative: true);
        if (!is_array(value: $decoded) || !isset($decoded['embeddings'])) {
            $snippet = mb_substr(string: $raw, start: 0, length: 400);
            $error = sprintf('Embedding-Response ungültig: %s', $snippet);
            return $result;
        }
        $embeddings = (array) $decoded['embeddings'];
        foreach ($keys as $i => $k) {
            $values = $embeddings[$i]['values'] ?? null;
            if (is_array(value: $values)) {
                $result[$k] = array_map(callback: 'floatval', array: $values);
            }
        }
        return $result;
    }

    /**
     * Cosine similarity between two equal-length float vectors. Returns 0.0
     * if either side is a zero vector to keep the caller's threshold logic
     * monotone.
     *
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    /**
     * Drop near-duplicate stories from a ranked magic bucket. Loads each
     * candidate's stored embedding, then greedily keeps a row only when it
     * isn't too close to anything already kept. Threshold 0.70 is softer
     * than Phase 8's 0.85 — catches semantic siblings that the
     * cross-source dedup didn't merge.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function dedupMagicBucket(array $rows, PDO $db, callable $emit): array
    {
        if (count(value: $rows) < 2) {
            return $rows;
        }
        $urls = array_values(array: array_map(callback: fn(array $r): string => (string) $r['url'], array: $rows));
        $placeholders = implode(separator: ',', array: array_fill(start_index: 0, count: count(value: $urls), value: '?'));
        $stmt = $db->prepare(query: 'SELECT url, embedding FROM articles WHERE url IN (' . $placeholders . ')');
        $stmt->execute(params: $urls);
        $blobs = [];
        while ($row = $stmt->fetch(mode: PDO::FETCH_ASSOC)) {
            if ($row['embedding'] !== null) {
                $blobs[(string) $row['url']] = (string) $row['embedding'];
            }
        }
        $threshold = 0.70;
        $kept = [];
        $keptVectors = [];
        $dropped = 0;
        foreach ($rows as $row) {
            $url = (string) $row['url'];
            if (!isset($blobs[$url])) {
                $kept[] = $row;
                continue;
            }
            $vec = array_values(array: (array) unpack(format: 'f*', string: $blobs[$url]));
            $isDup = false;
            foreach ($keptVectors as $other) {
                if ($this->cosineSimilarity(a: $vec, b: $other) >= $threshold) {
                    $isDup = true;
                    break;
                }
            }
            if ($isDup) {
                $dropped++;
                continue;
            }
            $kept[] = $row;
            $keptVectors[] = $vec;
        }
        if ($dropped > 0) {
            $emit(sprintf('  → %d themenähnliche Artikel aus Bucket entfernt (Threshold %.2f)', $dropped, $threshold));
        }
        return $kept;
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count(value: $a), count(value: $b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }
        if ($magA <= 0.0 || $magB <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt(num: $magA) * sqrt(num: $magB));
    }

    /**
     * Ask the configured LLM to re-rank N candidates into a top-10 list
     * based on the user's preference signals. Returns the reordered subset
     * or null if the call fails / no AI is configured / response is junk.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @param array{paper: array<string, array<string, float|int>>, category: array<string, array<string, float|int>>} $aff
     * @param array<string, mixed> $aiConfig
     * @return array<int, array<string, mixed>>|null
     */
    private function magicAiRerank(array $candidates, array $aff, array $aiConfig, string $apiKey, callable $emit): ?array
    {
        if (!class_exists(class: 'vielhuber\\aihelper\\aihelper')) {
            return null;
        }

        // Top / bottom 5 papers & categories by affinity for the prompt.
        $sorted = $aff['paper'];
        uasort(array: $sorted, callback: fn(array $a, array $b): int => $b['affinity'] <=> $a['affinity']);
        $likedPapers = array_slice(array: array_keys(array: $sorted), offset: 0, length: 5);
        $dislikedPapers = array_slice(array: array_reverse(array: array_keys(array: $sorted)), offset: 0, length: 3);

        $sorted = $aff['category'];
        uasort(array: $sorted, callback: fn(array $a, array $b): int => $b['affinity'] <=> $a['affinity']);
        $likedCats = array_slice(array: array_keys(array: $sorted), offset: 0, length: 5);
        $dislikedCats = array_slice(array: array_reverse(array: array_keys(array: $sorted)), offset: 0, length: 3);

        $lines = [];
        foreach ($candidates as $i => $c) {
            $lines[] = ($i + 1) . '. [' . ($c['paper'] ?? '?') . ' | ' . ($c['category'] ?? '-') . '] '
                . mb_substr(string: (string) ($c['title'] ?? ''), start: 0, length: 140);
        }

        $total = count(value: $candidates);
        $prompt =
            "Du sortierst Nachrichten-Artikel nach Relevanz für einen Nutzer und dessen erkannte Präferenzen.\n\n" .
            "Lieblings-Quellen: " . implode(separator: ', ', array: $likedPapers) . "\n" .
            "Lieblings-Kategorien: " . implode(separator: ', ', array: $likedCats) . "\n" .
            "Ungeliebte Quellen: " . implode(separator: ', ', array: $dislikedPapers) . "\n" .
            "Ungeliebte Kategorien: " . implode(separator: ', ', array: $dislikedCats) . "\n\n" .
            "Kandidaten:\n" . implode(separator: "\n", array: $lines) . "\n\n" .
            "Ordne ALLE {$total} Artikel nach Relevanz für diesen Nutzer (beste zuerst). " .
            "Keine auslassen, keine duplizieren. " .
            "Antworte AUSSCHLIESSLICH mit einer komma-separierten Liste aller {$total} Nummern aus der Liste oben " .
            "in der neuen Reihenfolge. Beispiel: 7,3,12,1,18,4,22,9,15,2,...";

        try {
            $aiClass = 'vielhuber\\aihelper\\aihelper';
            $ai = $aiClass::create(
                provider: (string) ($aiConfig['provider'] ?? ''),
                model: (string) ($aiConfig['model'] ?? ''),
                temperature: (float) ($aiConfig['temperature'] ?? 0.0),
                api_key: $apiKey,
                max_tries: (int) ($aiConfig['max_tries'] ?? 2),
                timeout: (int) ($aiConfig['timeout'] ?? 60)
            );
            $response = $ai->ask(prompt: $prompt);
            $raw = trim(string: (string) ($response['response'] ?? ''));
        } catch (\Throwable $e) {
            $emit('  ⚠️  AI-Rerank fehlgeschlagen: ' . $e->getMessage());
            return null;
        }

        $indices = [];
        foreach (preg_split(pattern: '/[^0-9]+/', subject: $raw) as $tok) {
            if ($tok === '' || !ctype_digit(text: $tok)) {
                continue;
            }
            $idx = (int) $tok - 1;
            if ($idx >= 0 && $idx < count(value: $candidates) && !in_array(needle: $idx, haystack: $indices, strict: true)) {
                $indices[] = $idx;
            }
        }
        // Require at least half the candidates as a sanity check; if the
        // LLM truncated heavily the rerank is unreliable and we fall back.
        if (count(value: $indices) < (int) (count(value: $candidates) / 2)) {
            $emit('  ⚠️  AI-Antwort unbrauchbar (' . count(value: $indices) . ' valide Indizes von ' . count(value: $candidates) . ')');
            return null;
        }
        // Append any candidate the LLM forgot at the end so nothing drops.
        $seen = array_flip(array: $indices);
        for ($i = 0, $n = count(value: $candidates); $i < $n; $i++) {
            if (!isset($seen[$i])) {
                $indices[] = $i;
            }
        }
        $reordered = [];
        foreach ($indices as $i) {
            $reordered[] = $candidates[$i];
        }
        return $reordered;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchArticlesForDashboard(
        string $paperFilter,
        string $statusFilter,
        string $paywallFilter,
        string $categoryFilter,
        string $readFilter,
        string $sortFilter,
        string $magicFilter,
        string $thumbFilter
    ): array {
        $db = $this->openDatabase();
        $where = [];
        $params = [];
        if ($paperFilter !== '') {
            $where[] = 'paper = :paper';
            $params[':paper'] = $paperFilter;
        }
        if ($statusFilter !== '') {
            $where[] = 'status = :status';
            $params[':status'] = $statusFilter;
        }
        if ($paywallFilter !== '') {
            $where[] = 'paywall = :paywall';
            $params[':paywall'] = $paywallFilter === 'plus' ? 1 : 0;
        }
        if ($thumbFilter === 'yes') {
            $where[] = 'thumbnail IS NOT NULL';
        } elseif ($thumbFilter === 'no') {
            $where[] = 'thumbnail IS NULL';
        }
        // Read articles are hidden by default — they only surface when the
        // user explicitly picks the "gelesen" filter.
        if ($readFilter === 'read') {
            $where[] = 'read_at IS NOT NULL';
        } else {
            $where[] = 'read_at IS NULL';
        }
        // Duplicate-articles (same story across multiple sources) are
        // collapsed to the canonical entry — non-canonical rows stay in the
        // DB but are hidden by the dashboard.
        $where[] = 'duplicate_of IS NULL';
        if ($categoryFilter !== '') {
            $values = $this->expandCategoryFilter(selected: $categoryFilter);
            $placeholders = [];
            foreach ($values as $i => $v) {
                $key = ':cat' . $i;
                $placeholders[] = $key;
                $params[$key] = $v;
            }
            $where[] = 'category IN (' . implode(separator: ',', array: $placeholders) . ')';
        }
        if ($magicFilter !== 'all') {
            // "Magisch" (default): show only articles that are currently
            // sitting in the magic bucket — a frozen top-10 snapshot taken
            // at the end of the last scrape. As the user reads them, the
            // bucket drains; it is repopulated only by the next scrape.
            $where[] = 'magic_rank IS NOT NULL';
            $orderBy = 'magic_rank ASC';
            $limit = self::DASHBOARD_MAX_ITEMS;
        } else {
            $sortDef = $this->sortOptions()[$sortFilter] ?? $this->sortOptions()[''];
            $orderBy = $sortDef['orderBy'];
            // Cap "Alle"-Modus to the most recent 100 items — keeps the
            // dashboard snappy. New articles slide in on reload.
            $limit = 100;
        }
        $sql =
            'SELECT url, paper, title, published_at, status, paywall, thumbnail, category, rating, read_at, vote FROM articles' .
            (empty($where) ? '' : ' WHERE ' . implode(separator: ' AND ', array: $where)) .
            ' ORDER BY ' . $orderBy . ' LIMIT ' . $limit;
        $stmt = $db->prepare(query: $sql);
        $stmt->execute(params: $params);
        return $stmt->fetchAll(mode: PDO::FETCH_ASSOC) ?: [];
    }

    private function renderDashboard(
        string $paperFilter,
        string $statusFilter,
        string $paywallFilter,
        string $categoryFilter,
        string $readFilter,
        string $sortFilter,
        string $magicFilter,
        string $thumbFilter
    ): string {
        $articles = $this->fetchArticlesForDashboard(
            paperFilter: $paperFilter,
            statusFilter: $statusFilter,
            paywallFilter: $paywallFilter,
            categoryFilter: $categoryFilter,
            readFilter: $readFilter,
            sortFilter: $sortFilter,
            magicFilter: $magicFilter,
            thumbFilter: $thumbFilter
        );

        // Auto-submitting <select> dropdowns. The form's GET action keeps
        // every filter in the URL so links stay shareable. The dropdown
        // shows the bare domain (e.g. spiegel.de) instead of the brand
        // label. Strip www./m. prefixes for visual consistency.
        $paperList = $this->papers();
        $paperList = array_map(
            callback: function (array $info): array {
                $host = (string) parse_url(url: $info['url'] ?? '', component: PHP_URL_HOST);
                $info['domain'] = (string) preg_replace(pattern: '~^(?:www|m)\.~', replacement: '', subject: $host);
                return $info;
            },
            array: $paperList
        );
        uasort(
            array: $paperList,
            callback: fn(array $a, array $b): int => strcasecmp(string1: $a['domain'], string2: $b['domain'])
        );
        $paperOptions = '<option value="">Quelle</option>';
        foreach ($paperList as $key => $info) {
            $escapedKey = htmlspecialchars(string: (string) $key, flags: ENT_QUOTES);
            $escapedDomain = htmlspecialchars(string: $info['domain'], flags: ENT_QUOTES);
            $sel = $paperFilter === $key ? ' selected' : '';
            $paperOptions .= '<option value="' . $escapedKey . '"' . $sel . '>' . $escapedDomain . '</option>';
        }

        $statusOptions = '<option value="">Status</option>';
        foreach (['archive' => 'archive.today', 'original' => 'original'] as $value => $label) {
            $sel = $statusFilter === $value ? ' selected' : '';
            $statusOptions .= '<option value="' . $value . '"' . $sel . '>' . $label . '</option>';
        }

        $paywallOptions = '<option value="">Typ</option>';
        foreach (['plus' => 'PLUS', 'free' => 'FREE'] as $value => $label) {
            $sel = $paywallFilter === $value ? ' selected' : '';
            $paywallOptions .= '<option value="' . $value . '"' . $sel . '>' . $label . '</option>';
        }

        $readOptions = '';
        foreach (['' => 'Lesestatus', 'unread' => 'ungelesen', 'read' => 'gelesen'] as $value => $label) {
            $sel = $readFilter === $value ? ' selected' : '';
            $readOptions .= '<option value="' . $value . '"' . $sel . '>' . $label . '</option>';
        }

        $sortDropdown = '';
        foreach ($this->sortOptions() as $value => $def) {
            $sel = $sortFilter === $value ? ' selected' : '';
            $sortDropdown .= '<option value="' . htmlspecialchars(string: (string) $value, flags: ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars(string: $def['label'], flags: ENT_QUOTES) . '</option>';
        }

        $magicOptions = '';
        foreach (['' => 'Magisch', 'all' => 'Alle'] as $value => $label) {
            $sel = $magicFilter === $value ? ' selected' : '';
            $magicOptions .= '<option value="' . $value . '"' . $sel . '>' . $label . '</option>';
        }

        $thumbOptions = '<option value="">Bild</option>';
        foreach (['yes' => 'mit Bild', 'no' => 'ohne Bild'] as $value => $label) {
            $sel = $thumbFilter === $value ? ' selected' : '';
            $thumbOptions .= '<option value="' . $value . '"' . $sel . '>' . $label . '</option>';
        }

        $categoryOptions = '<option value="">Kategorie</option>';
        foreach ($this->categories() as $parent => $children) {
            $parentName = (string) $parent;
            $escapedParent = htmlspecialchars(string: $parentName, flags: ENT_QUOTES);
            $sel = $categoryFilter === $parentName ? ' selected' : '';
            $label = !empty($children) ? $escapedParent . ' (alle)' : $escapedParent;
            $categoryOptions .= '<option value="' . $escapedParent . '"' . $sel . '>' . $label . '</option>';
            foreach ($children as $child) {
                $childName = (string) $child;
                $escapedChild = htmlspecialchars(string: $childName, flags: ENT_QUOTES);
                $sel = $categoryFilter === $childName ? ' selected' : '';
                $categoryOptions .= '<option value="' . $escapedChild . '"' . $sel . '>&nbsp;&nbsp;&mdash; ' . $escapedChild . '</option>';
            }
        }

        $rows = '';
        // Collect the first N article targets for Chrome speculation-rules
        // prerender hints (rendered into <head> further down). Limited to
        // keep RAM / bandwidth overhead inside Chrome bounded.
        $prerenderTargets = [];
        $prerenderLimit = 10;
        foreach ($articles as $row) {
            $isArchived = $row['status'] === 'archive';
            $url = (string) $row['url'];
            $target = $isArchived ? $this->proxyUrl(originalUrl: $url) : $url;
            if (count(value: $prerenderTargets) < $prerenderLimit) {
                $prerenderTargets[] = $target;
            }
            // Open in a new tab so the dashboard stays visible after the
            // click. preconnect + speculation-rules prefetch keep the
            // navigation fast across tabs.
            $rel = $isArchived ? ' rel="noopener"' : ' rel="noreferrer noopener"';
            $linkAttrs = $rel . ' target="_blank"';
            $badgeClass = $isArchived ? 'badge badge--archived' : 'badge badge--original';
            $badgeLabel = $isArchived ? 'archive.today' : 'original';
            $paywallBadge = '';
            if ($row['paywall'] !== null) {
                $isPlus = (int) $row['paywall'] === 1;
                $paywallBadge = '<a class="badge ' .
                    ($isPlus ? 'badge--plus' : 'badge--free') . '" href="/?paywall=' .
                    ($isPlus ? 'plus' : 'free') . '">' .
                    ($isPlus ? 'PLUS' : 'FREE') . '</a>';
            }
            $paperKey = (string) $row['paper'];
            $paperLabel = htmlspecialchars(
                string: $this->papers()[$paperKey]['label'] ?? $paperKey,
                flags: ENT_QUOTES,
                encoding: 'UTF-8'
            );
            $publishedAt = $row['published_at'] !== null ? (int) $row['published_at'] : null;
            $dateHtml =
                $publishedAt !== null
                    ? '<time class="meta__date" datetime="' .
                        htmlspecialchars(string: date(format: 'c', timestamp: $publishedAt), flags: ENT_QUOTES) .
                        '">' .
                        htmlspecialchars(string: date(format: 'd.m. H:i', timestamp: $publishedAt), flags: ENT_QUOTES) .
                        '</time>'
                    : '';

            $escapedUrl = htmlspecialchars(string: $url, flags: ENT_QUOTES, encoding: 'UTF-8');
            $escapedTarget = htmlspecialchars(string: $target, flags: ENT_QUOTES, encoding: 'UTF-8');

            $thumbInner = $row['thumbnail'] !== null && $row['thumbnail'] !== ''
                ? '<img class="item__thumb" src="' . htmlspecialchars(string: (string) $row['thumbnail'], flags: ENT_QUOTES) . '" alt="" loading="lazy" decoding="async">'
                : '<span class="item__thumb item__thumb--empty"></span>';
            $thumbnail =
                '<a class="item__thumb-link" data-mark-read="' . $escapedUrl . '" href="' .
                $escapedTarget . '"' . $linkAttrs . '>' . $thumbInner . '</a>';

            $categoryBadge = $row['category'] !== null && $row['category'] !== ''
                ? '<a class="meta__category" href="/?category=' .
                    htmlspecialchars(string: rawurlencode(string: (string) $row['category']), flags: ENT_QUOTES) .
                    '">' . htmlspecialchars(string: (string) $row['category'], flags: ENT_QUOTES) . '</a>'
                : '';

            $ratingBadge = $row['rating'] !== null
                ? '<span class="item__rating">' . $this->formatRating(value: (int) $row['rating']) . '</span>'
                : '';

            $voteValue = (int) ($row['vote'] ?? 0);
            $voteLabel = $voteValue > 0 ? '+' . $voteValue : (string) $voteValue;
            $voteClass = $voteValue > 0
                ? ' vote__val--up'
                : ($voteValue < 0 ? ' vote__val--down' : '');
            $voteHtml =
                '<span class="vote" data-vote-url="' . $escapedUrl . '">' .
                '<button type="button" class="vote__btn vote__btn--up" aria-label="Upvote">▲</button>' .
                '<span class="vote__val' . $voteClass . '">' . $voteLabel . '</span>' .
                '<button type="button" class="vote__btn vote__btn--down" aria-label="Downvote">▼</button>' .
                '</span>';

            $isRead = $row['read_at'] !== null;
            $itemClass = $isRead ? 'item item--read' : 'item';

            $rows .=
                '<li class="' . $itemClass . '" data-mark-read="' . $escapedUrl . '">' .
                '<div class="item__swipe">' .
                $thumbnail .
                '<div class="item__body">' .
                $ratingBadge .
                '<a class="item__link" data-mark-read="' . $escapedUrl . '" href="' .
                $escapedTarget .
                '"' .
                $linkAttrs .
                '>' .
                htmlspecialchars(string: (string) $row['title'], flags: ENT_QUOTES, encoding: 'UTF-8') .
                '</a>' .
                '<div class="item__meta">' .
                '<a class="meta__paper paper--' . htmlspecialchars(string: $paperKey, flags: ENT_QUOTES) . '" href="/?paper=' .
                htmlspecialchars(string: rawurlencode(string: $paperKey), flags: ENT_QUOTES) . '">' .
                $paperLabel .
                '</a>' .
                $categoryBadge .
                '<a class="' . $badgeClass . '" href="/?status=' . ($isArchived ? 'archive' : 'original') . '">' . $badgeLabel . '</a>' .
                $paywallBadge .
                $voteHtml .
                $dateHtml .
                '</div>' .
                '</div>' .
                '</div>' .
                '</li>';
        }

        if ($rows === '') {
            // "Alles erledigt" — Kaffee-Tasse mit aufsteigendem Dampf.
            $rows =
                '<li class="item item--empty">' .
                '<div class="done">' .
                '<div class="done__steam">' .
                '<span class="done__puff"></span>' .
                '<span class="done__puff"></span>' .
                '<span class="done__puff"></span>' .
                '</div>' .
                '<div class="done__cup">☕</div>' .
                '<div class="done__label">Et voilà.</div>' .
                '</div>' .
                '</li>';
        }

        $pwa = $this->pwaHeadTags();
        $count = count(value: $articles);
        $countLabel = htmlspecialchars(string: $count . ' Artikel', flags: ENT_QUOTES);

        // All links open via target="_blank", which kills the same-origin
        // prerender activation (prerender is tab-bound). So we drop
        // prerender entirely and warm the click destination with
        // speculation-rules `prefetch` (HTML bytes in HTTP cache, works
        // cross-tab) plus a `preconnect` for every cross-origin host
        // (DNS + TLS handshake done before the user clicks).
        $prerenderTag = '';
        if ($prerenderTargets !== []) {
            $rules = json_encode(
                value: ['prefetch' => [['urls' => $prerenderTargets, 'eagerness' => 'eager']]],
                flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if (is_string(value: $rules)) {
                $prerenderTag = '<script type="speculationrules">' . $rules . '</script>';
            }
            $selfHost = (string) parse_url(url: $this->currentOrigin(), component: PHP_URL_HOST);
            $seenHosts = [];
            foreach ($prerenderTargets as $u) {
                $scheme = (string) parse_url(url: $u, component: PHP_URL_SCHEME);
                $host = (string) parse_url(url: $u, component: PHP_URL_HOST);
                if ($host === '' || strcasecmp(string1: $host, string2: $selfHost) === 0) {
                    continue;
                }
                if (isset($seenHosts[$host])) {
                    continue;
                }
                $seenHosts[$host] = true;
                $origin = ($scheme !== '' ? $scheme : 'https') . '://' . $host;
                $prerenderTag .= '<link rel="preconnect" href="'
                    . htmlspecialchars(string: $origin, flags: ENT_QUOTES) . '" crossorigin>';
            }
        }

        return <<<HTML
        <!doctype html>
        <html lang="de">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1,viewport-fit=cover">
            <title>extrablatt!</title>
            {$pwa}
            {$prerenderTag}
            <style>
                :root { color-scheme: light; }
                * { box-sizing: border-box; }
                html { overflow-y: scroll; }
                body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #f4f4f5; color: #111; min-height: 100vh; }
                main { max-width: 760px; margin: 0 auto; padding: 1.25rem 1rem 3rem; }
                header.top { display: flex; align-items: baseline; gap: 12px; margin: 0 0 1rem; flex-wrap: wrap; }
                header.top h1 { font-size: clamp(1.4rem, 4vw, 2rem); margin: 0; letter-spacing: -0.02em; font-weight: 800; }
                header.top h1 a { color: inherit; text-decoration: none; }
                header.top h1 a:hover { text-decoration: underline; }
                header.top .count { font: 500 12px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: #71717a; }
                header.top .scrape-link { margin-left: auto; font: 600 12px/1 system-ui, sans-serif; text-decoration: none; background: #18181b; color: #fff; padding: 8px 12px; border-radius: 6px; }
                header.top .scrape-link:hover { background: #3f3f46; }
                header.top .reset-btn { font: 600 12px/1 system-ui, sans-serif; background: #fff; color: #991b1b; padding: 8px 12px; border-radius: 6px; border: 1px solid #fecaca; cursor: pointer; }
                header.top .reset-btn:hover { background: #fef2f2; border-color: #f87171; }
                header.top .markall-btn { font: 600 12px/1 system-ui, sans-serif; background: #fff; color: #1e40af; padding: 8px 12px; border-radius: 6px; border: 1px solid #bfdbfe; cursor: pointer; }
                header.top .markall-btn:hover { background: #eff6ff; border-color: #60a5fa; }
                form.filters { display: grid; grid-template-columns: repeat(8, minmax(0, 1fr)); gap: 5px; margin: 0 0 1rem; }
                form.filters select { min-width: 0; width: 100%; font: 600 12px/1 system-ui, sans-serif; color: #18181b; background: #fff; border: 1px solid #d4d4d8; padding: 8px 22px 8px 8px; border-radius: 6px; cursor: pointer; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8'><path fill='%2371717a' d='M6 8 0 0h12z'/></svg>"); background-repeat: no-repeat; background-position: right 7px center; background-size: 8px 5px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; }
                form.filters select:hover { border-color: #71717a; }
                form.filters select:focus { outline: none; border-color: #18181b; }
                ul.items { list-style: none; padding: 0; margin: 0; }
                .item { position: relative; overflow: hidden; border: 1px solid #e4e4e7; border-radius: 8px; margin: 0 0 10px; transition: opacity 0.18s ease, max-height 0.18s ease, margin 0.18s ease, border-width 0.18s ease; contain: layout paint style; content-visibility: auto; contain-intrinsic-size: 0 92px; }
                .item__swipe { position: relative; display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; background: #fff; touch-action: pan-y; transition: transform 0.18s ease; will-change: transform; }
                .item.swiping .item__swipe { transition: none; }
                .item.swipe-out { opacity: 0; max-height: 0; margin-top: 0; margin-bottom: 0; border-width: 0; }
                .item::before, .item::after { content: ""; position: absolute; top: 0; bottom: 0; width: 80px; display: flex; align-items: center; justify-content: center; color: #fff; font: 700 26px/1 system-ui, sans-serif; pointer-events: none; opacity: 0; transition: opacity 0.12s ease; }
                .item::before { left: 0; background: #16a34a; }
                .item::after { right: 0; background: #dc2626; }
                .item.swipe-left::after { opacity: 1; content: "▼"; }
                .item.swipe-right::before { opacity: 1; content: "▲"; }
                .item__thumb-link { flex-shrink: 0; display: block; line-height: 0; }
                .item__thumb { display: block; width: 64px; height: 64px; border-radius: 6px; object-fit: cover; background: #e4e4e7; }
                .item__thumb--empty { background: linear-gradient(135deg,#e4e4e7,#d4d4d8); }
                .item__body { flex: 1; min-width: 0; position: relative; }
                .item__rating { position: absolute; top: 0; right: 0; font: 700 10px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; padding: 4px 7px; border-radius: 4px; background: #fff7ed; color: #c2410c; white-space: nowrap; }
                .item__link { color: #111; text-decoration: none; font: 600 15px/1.35 system-ui,sans-serif; overflow-wrap: anywhere; display: block; padding-right: 70px; }
                .item__link:hover { text-decoration: underline; }
                .badge { font: 700 10px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: 0.04em; text-transform: uppercase; padding: 3px 6px; border-radius: 3px; white-space: nowrap; text-decoration: none; }
                .badge--archived { background: #dcfce7; color: #166534; }
                .badge--archived:hover { background: #bbf7d0; }
                .badge--original { background: #fee2e2; color: #991b1b; }
                .badge--original:hover { background: #fecaca; }
                .badge--plus { background: #fef3c7; color: #92400e; }
                .badge--plus:hover { background: #fde68a; }
                .badge--free { background: #e0f2fe; color: #075985; }
                .badge--free:hover { background: #bae6fd; }
                .item__meta { display: flex; gap: 6px; align-items: center; margin-top: 6px; font: 500 11px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: #71717a; flex-wrap: wrap; }
                .meta__paper { font-weight: 700; font-size: 10px; padding: 3px 6px; border-radius: 3px; background: #e4e4e7; color: #18181b; text-decoration: none; }
                .meta__paper:hover { background: #d4d4d8; }
                .meta__category { font-weight: 600; font-size: 10px; padding: 3px 6px; border-radius: 3px; background: #ede9fe; color: #5b21b6; text-decoration: none; }
                .meta__category:hover { background: #ddd6fe; }
                .vote { display: inline-flex; align-items: center; gap: 2px; }
                .vote__btn { font: 700 11px/1 system-ui, sans-serif; background: #fff; border: 1px solid #d4d4d8; color: #71717a; padding: 3px 5px; border-radius: 3px; cursor: pointer; }
                .vote__btn:hover { background: #f4f4f5; color: #18181b; border-color: #71717a; }
                .vote__val { font: 700 10px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; min-width: 18px; text-align: center; color: #71717a; }
                .vote__val--up { color: #16a34a; }
                .vote__val--down { color: #dc2626; }
                .meta__date { color: #71717a; }
                .item--empty { text-align: center; color: #71717a; padding: 24px 14px; display: block; background: #fff; }
                .done { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 28px 0 18px; }
                .done__steam { position: relative; width: 60px; height: 36px; }
                .done__puff { position: absolute; bottom: 0; width: 10px; height: 10px; border-radius: 50%; background: rgba(120,120,120,0.55); opacity: 0; filter: blur(1px); animation: done-steam 2.8s ease-out infinite; }
                .done__puff:nth-child(1) { left: 12px; animation-delay: 0s; }
                .done__puff:nth-child(2) { left: 25px; animation-delay: 0.9s; }
                .done__puff:nth-child(3) { left: 38px; animation-delay: 1.7s; }
                @keyframes done-steam { 0% { opacity: 0; transform: translateY(0) scale(0.5); } 25% { opacity: 0.7; } 100% { opacity: 0; transform: translateY(-32px) scale(1.6); } }
                .done__cup { font-size: 72px; line-height: 1; animation: done-tilt 4s ease-in-out infinite; transform-origin: bottom center; }
                @keyframes done-tilt { 0%, 100% { transform: rotate(-3deg); } 50% { transform: rotate(3deg); } }
                .done__label { font: 600 14px/1 system-ui, sans-serif; color: #71717a; margin-top: 10px; letter-spacing: 0.02em; }
                .item--empty a { color: #111; }
                .top-btn { position: fixed; bottom: 20px; right: max(12px, calc((100vw - 760px) / 2 - 70px)); background: rgba(0,0,0,.78); color: #fff; text-decoration: none; font: 700 12px/1 system-ui, sans-serif; padding: 11px 14px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.25); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); z-index: 2147483647; opacity: 0; pointer-events: none; transition: opacity .2s ease; }
                .top-btn.visible { opacity: 1; pointer-events: auto; }
                .top-btn:hover { background: #000; }
            </style>
        </head>
        <body>
            <main>
                <header class="top">
                    <h1><a href="/">extrablatt!</a></h1>
                    <span class="count" id="count" data-suffix=" Artikel">{$countLabel}</span>
                    <a class="scrape-link" href="/?scrape=1" target="_blank" rel="noopener">Scrape ▶</a>
                    <form method="post" action="/" onsubmit="return confirm('Alle ungelesenen Artikel als gelesen markieren?');" style="margin:0">
                        <input type="hidden" name="mark_all_read" value="1">
                        <button type="submit" class="markall-btn">All read</button>
                    </form>
                    <form method="post" action="/" onsubmit="return confirm('Alles zurücksetzen: {$count} DB-Einträge plus alle Cache-Dateien löschen?');" style="margin:0">
                        <input type="hidden" name="reset" value="1">
                        <button type="submit" class="reset-btn">Reset</button>
                    </form>
                </header>
                <form class="filters" method="get" action="/">
                    <select name="paper" onchange="this.form.submit()">{$paperOptions}</select>
                    <select name="status" onchange="this.form.submit()">{$statusOptions}</select>
                    <select name="paywall" onchange="this.form.submit()">{$paywallOptions}</select>
                    <select name="thumb" onchange="this.form.submit()">{$thumbOptions}</select>
                    <select name="category" onchange="this.form.submit()">{$categoryOptions}</select>
                    <select name="read" onchange="this.form.submit()">{$readOptions}</select>
                    <select name="magic" onchange="this.form.submit()">{$magicOptions}</select>
                    <select name="sort" onchange="this.form.submit()">{$sortDropdown}</select>
                </form>
                <ul class="items">{$rows}</ul>
            </main>
            <a href="#" class="top-btn" onclick="window.scrollTo({top:0,behavior:'smooth'});return false;">↑ Top</a>
            <script>
                // Recount visible articles, update the header label, and
                // surface the "Et voilà" empty-state when the last item is
                // swiped away (the server-rendered placeholder only fires on
                // initial empty load, so JS has to inject it after drains).
                window.__refreshCount = function () {
                    var \$count = document.getElementById('count');
                    var \$list = document.querySelector('ul.items');
                    if (\$count) {
                        var n = document.querySelectorAll('ul.items > li.item:not(.item--empty):not(.swipe-out)').length;
                        \$count.textContent = n + (\$count.getAttribute('data-suffix') || '');
                        if (\$list && n === 0 && !\$list.querySelector('.item--empty')) {
                            \$list.insertAdjacentHTML('beforeend',
                                '<li class="item item--empty">' +
                                '<div class="done">' +
                                '<div class="done__steam">' +
                                '<span class="done__puff"></span>' +
                                '<span class="done__puff"></span>' +
                                '<span class="done__puff"></span>' +
                                '</div>' +
                                '<div class="done__cup">☕</div>' +
                                '<div class="done__label">Et voilà.</div>' +
                                '</div>' +
                                '</li>');
                        }
                    }
                };
                (function () {
                    var btn = document.querySelector('.top-btn');
                    if (!btn) return;
                    function update() {
                        if (window.scrollY > 200) btn.classList.add('visible');
                        else btn.classList.remove('visible');
                    }
                    window.addEventListener('scroll', update, { passive: true });
                    update();
                })();
                // Voting controls: ▲ / ▼ adjust the curator vote by ±1,
                // clamped to [-3, +3] on both client and server. Optimistic
                // UI update, sendBeacon for fire-and-forget persistence.
                (function () {
                    document.addEventListener('click', function (e) {
                        var \$btn = e.target.closest && e.target.closest('.vote__btn');
                        if (!\$btn) return;
                        e.preventDefault();
                        e.stopPropagation();
                        var \$vote = \$btn.closest('.vote');
                        if (!\$vote) return;
                        var url = \$vote.getAttribute('data-vote-url');
                        if (!url) return;
                        var delta = \$btn.classList.contains('vote__btn--up') ? 1 : -1;
                        var \$val = \$vote.querySelector('.vote__val');
                        var current = parseInt(\$val.textContent, 10) || 0;
                        var next = Math.max(-3, Math.min(3, current + delta));
                        if (next === current) return;
                        \$val.textContent = next > 0 ? '+' + next : String(next);
                        \$val.classList.toggle('vote__val--up', next > 0);
                        \$val.classList.toggle('vote__val--down', next < 0);
                        try {
                            var data = new URLSearchParams();
                            data.append('vote', url);
                            data.append('delta', String(delta));
                            if (navigator.sendBeacon) {
                                navigator.sendBeacon('/', data);
                            } else {
                                fetch('/', { method: 'POST', body: data, keepalive: true });
                            }
                        } catch (err) { /* swallow */ }
                    }, true);
                })();
                // Swipe gestures on each item:
                //   • right → mark read + downvote (vote -1)
                //   • left  → mark read + upvote   (vote +1)
                // The item slides off and is removed from the DOM. Vertical
                // pan still scrolls the list (touch-action: pan-y).
                (function () {
                    var THRESHOLD = 80;
                    var DIRECTION_HINT = 20;
                    var startX = 0, startY = 0;
                    var \$item = null, \$swipe = null;
                    var active = false;
                    var moved = false;
                    var sendBeacon = function (params) {
                        try {
                            if (navigator.sendBeacon) {
                                navigator.sendBeacon('/', params);
                            } else {
                                fetch('/', { method: 'POST', body: params, keepalive: true });
                            }
                        } catch (err) { /* swallow */ }
                    };
                    document.addEventListener('pointerdown', function (e) {
                        // Ignore secondary buttons and clicks on the vote
                        // widget — those have their own handler.
                        if (e.button !== undefined && e.button !== 0) return;
                        if (e.target.closest && e.target.closest('.vote')) return;
                        var target = e.target.closest && e.target.closest('.item__swipe');
                        if (!target) return;
                        \$swipe = target;
                        \$item = \$swipe.closest('.item');
                        startX = e.clientX;
                        startY = e.clientY;
                        active = false;
                        moved = false;
                    }, true);
                    document.addEventListener('pointermove', function (e) {
                        if (!\$swipe) return;
                        var dx = e.clientX - startX;
                        var dy = e.clientY - startY;
                        if (!active) {
                            if (Math.abs(dx) > 8 && Math.abs(dx) > Math.abs(dy)) {
                                active = true;
                                \$item.classList.add('swiping');
                            } else if (Math.abs(dy) > 8) {
                                \$swipe = null;
                                return;
                            }
                        }
                        if (active) {
                            moved = true;
                            if (e.cancelable) e.preventDefault();
                            \$swipe.style.transform = 'translateX(' + dx + 'px)';
                            \$item.classList.toggle('swipe-left', dx < -DIRECTION_HINT);
                            \$item.classList.toggle('swipe-right', dx > DIRECTION_HINT);
                        }
                    }, { passive: false, capture: true });
                    var finish = function (e) {
                        if (!\$swipe) return;
                        var swipe = \$swipe, item = \$item, wasActive = active, wasMoved = moved;
                        \$swipe = null;
                        \$item = null;
                        if (!wasActive) return;
                        var dx = e.clientX - startX;
                        if (Math.abs(dx) >= THRESHOLD) {
                            // Commit: right (dx>0) → upvote, left (dx<0) → downvote.
                            var delta = dx > 0 ? 1 : -1;
                            var url = item.getAttribute('data-mark-read');
                            if (url) {
                                var read = new URLSearchParams();
                                read.append('mark_read', url);
                                sendBeacon(read);
                                var vote = new URLSearchParams();
                                vote.append('vote', url);
                                vote.append('delta', String(delta));
                                sendBeacon(vote);
                            }
                            item.classList.remove('swiping', 'swipe-left', 'swipe-right');
                            swipe.style.transform = 'translateX(' + (dx > 0 ? 1 : -1) * (window.innerWidth || 400) + 'px)';
                            // After the slide-off finishes, collapse the row.
                            requestAnimationFrame(function () {
                                item.style.maxHeight = item.offsetHeight + 'px';
                                requestAnimationFrame(function () {
                                    item.classList.add('swipe-out');
                                    window.__refreshCount && window.__refreshCount();
                                });
                            });
                            setTimeout(function () { item.remove(); }, 220);
                        } else {
                            item.classList.remove('swiping', 'swipe-left', 'swipe-right');
                            swipe.style.transform = '';
                        }
                        // Suppress the synthesised click after an actual drag.
                        if (wasMoved) {
                            var swallow = function (evt) {
                                evt.preventDefault();
                                evt.stopPropagation();
                                document.removeEventListener('click', swallow, true);
                            };
                            document.addEventListener('click', swallow, true);
                            setTimeout(function () {
                                document.removeEventListener('click', swallow, true);
                            }, 400);
                        }
                    };
                    document.addEventListener('pointerup', finish, true);
                    document.addEventListener('pointercancel', function () {
                        if (!\$swipe) return;
                        \$item.classList.remove('swiping', 'swipe-left', 'swipe-right');
                        \$swipe.style.transform = '';
                        \$swipe = null;
                        \$item = null;
                    }, true);
                })();
                // Persist read state on click. Uses sendBeacon so the
                // request survives navigation away from the dashboard. The
                // item stays in the current list — that lets you still swipe
                // it to vote afterwards. A reload (or any filter change)
                // hides it because the default view excludes read articles.
                (function () {
                    document.addEventListener('click', function (e) {
                        var \$link = e.target.closest && e.target.closest('a[data-mark-read]');
                        if (!\$link) return;
                        var url = \$link.getAttribute('data-mark-read');
                        if (!url) return;
                        try {
                            var data = new URLSearchParams();
                            data.append('mark_read', url);
                            if (navigator.sendBeacon) {
                                navigator.sendBeacon('/', data);
                            } else {
                                fetch('/', { method: 'POST', body: data, keepalive: true });
                            }
                        } catch (err) { /* swallow */ }
                    }, true);
                })();
            </script>
        </body>
        </html>
        HTML;
    }
}

