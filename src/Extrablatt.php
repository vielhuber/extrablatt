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
    private const TV_MAX_AGE_DAYS = 30;
    // Media feeds (Streaming/Kino/Gaming) only accept releases within this
    // window — "neu" means at most three months old.
    private const MEDIA_WINDOW_DAYS = 90;
    // laut.de author rating (1-5) an album needs to count as "top".
    private const LAUT_MIN_RATING = 4;
    // Metascore an album needs to count as "top" (75 = generally favorable).
    private const METACRITIC_ALBUM_MIN_SCORE = 75;
    // Rotten-Tomatoes affiliate slug => label woven into item titles.
    private const STREAMING_SERVICES = [
        'netflix' => 'Netflix',
        'amazon-prime-video' => 'Prime Video',
        'apple-tv-plus' => 'Apple TV+',
        'disney-plus' => 'Disney+',
    ];
    private const DASHBOARD_MAX_ITEMS = 10000;
    private const ARCHIVE_CHECK_CONCURRENCY = 8;
    private const THUMBNAIL_SIZE = 160;
    private const THUMBNAIL_JPEG_QUALITY = 78;
    private const THUMBNAIL_MAX_SOURCE_BYTES = 8_000_000;
    private const FETCH_CONNECT_TIMEOUT_SECONDS = 8;
    private const FETCH_MAX_TIME_SECONDS = 20;
    // Public, non-secret salt for domain-separating the cookie-signing key.
    // The actual signing key is derived per deployment from AUTH_PASSWORD via
    // authCookieKey(), so a valid cookie cannot be forged without knowing the
    // password. Changing this salt (or the password) invalidates all sessions.
    private const AUTH_COOKIE_KEY = 'extrablatt-cookie-key-v1';

    /**
     * Per-deployment HMAC key for the auth cookie, derived from AUTH_PASSWORD
     * so the signing secret is never present in the (public) source.
     */
    private function authCookieKey(string $password): string
    {
        return hash_hmac(algo: 'sha256', data: self::AUTH_COOKIE_KEY, key: $password);
    }

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
        $expected = hash_hmac(algo: 'sha256', data: $expiry, key: $this->authCookieKey($password));
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
        $sig = hash_hmac(algo: 'sha256', data: (string) $expiry, key: $this->authCookieKey($expected));
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
        $tvFilter = (string) ($_GET['tv'] ?? '');
        $mediaFilter = (string) ($_GET['media'] ?? '');
        $statusFilter = (string) ($_GET['status'] ?? '');
        $paywallFilter = (string) ($_GET['paywall'] ?? '');
        $categoryFilter = (string) ($_GET['category'] ?? '');
        $readFilter = (string) ($_GET['read'] ?? '');
        $sortFilter = (string) ($_GET['sort'] ?? '');
        $magicFilter = (string) ($_GET['magic'] ?? '');
        $thumbFilter = (string) ($_GET['thumb'] ?? '');
        $viewFilter = (string) ($_GET['view'] ?? '');
        $searchQuery = trim(string: (string) ($_GET['q'] ?? ''));

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
            // Quelle-Dropdown holds only news papers — TV moves into its own
            // dropdown bound to $tvFilter / $_GET['tv'].
            if ($paperFilter !== '' && !array_key_exists(key: $paperFilter, array: $this->papers())) {
                $paperFilter = '';
            }
            // TV-Dropdown: "" (off), "all" (sentinel for all 5 shows) or one
            // of the configured talkshow papers.
            if ($tvFilter !== '' && $tvFilter !== 'all' && !in_array(needle: $tvFilter, haystack: $this->talkshowPapers(), strict: true)) {
                $tvFilter = '';
            }
            if ($mediaFilter !== '' && !isset($this->mediaTabs()[$mediaFilter])) {
                $mediaFilter = '';
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
            $mediaViews = array_keys(array: $this->mediaTabs());
            if (!in_array(needle: $viewFilter, haystack: array_merge(['zeitung', 'meldungen', 'talkshows', 'factcheck', 'bild'], $mediaViews), strict: true)) {
                $viewFilter = 'zeitung';
            }
            // "talkshows" view is a Meldungen shortcut: forces tv=all and
            // magic=all, so a fresh click lands directly on the unfiltered
            // talk-show list.
            if ($viewFilter === 'talkshows') {
                $viewFilter = 'meldungen';
                $tvFilter = 'all';
                $magicFilter = 'all';
                $paperFilter = '';
                $mediaFilter = '';
            }
            // Media views (serien/filme/alben/games) work the same way:
            // Meldungen shortcuts forcing media=<tab> and magic=all. They
            // default to rating-ranked lists — critic score is the point of
            // these tabs; the sort dropdown still lets the user override.
            if (in_array(needle: $viewFilter, haystack: $mediaViews, strict: true)) {
                $mediaFilter = $viewFilter;
                $viewFilter = 'meldungen';
                $magicFilter = 'all';
                $paperFilter = '';
                $tvFilter = '';
                if ($sortFilter === '') {
                    $sortFilter = 'rating_desc';
                }
            }
            $factcheckStatement = '';
            $factcheckPending = false;
            if ($viewFilter === 'factcheck') {
                $factcheckStatement = trim(string: (string) ($_GET['statement'] ?? ''));
                // Fragment endpoint — synchronous LLM call, returns just the
                // result block HTML to be swapped into the placeholder by JS.
                // Page chrome is skipped so the response is a clean replacement.
                if (isset($_GET['factcheck_fragment'])) {
                    header(header: 'Content-Type: text/html; charset=utf-8');
                    $result = $factcheckStatement !== '' ? $this->runFactCheck(statement: $factcheckStatement) : null;
                    echo $this->buildFactCheckResult(statement: $factcheckStatement, result: $result);
                    return;
                }
                // Initial page load: render immediately with a skeleton; the
                // browser fetches the fragment once the DOM is ready, so the
                // user sees the page in well under a second instead of waiting
                // 30-90 seconds for the high-effort reasoning call to finish.
                $factcheckPending = $factcheckStatement !== '';
            }
            header(header: 'Content-Type: text/html; charset=utf-8');
            echo $this->renderDashboard(
                paperFilter: $paperFilter,
                tvFilter: $tvFilter,
                mediaFilter: $mediaFilter,
                statusFilter: $statusFilter,
                paywallFilter: $paywallFilter,
                categoryFilter: $categoryFilter,
                readFilter: $readFilter,
                sortFilter: $sortFilter,
                magicFilter: $magicFilter,
                thumbFilter: $thumbFilter,
                viewFilter: $viewFilter,
                factcheckStatement: $factcheckStatement,
                factcheckPending: $factcheckPending,
                searchQuery: $searchQuery
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
        if (str_starts_with(haystack: $feedUrl, needle: 'github://')) {
            return $this->fetchGitHubTrendingItems(paper: $paper, feedUrl: $feedUrl);
        }
        if (str_starts_with(haystack: $feedUrl, needle: 'producthunt://')) {
            return $this->fetchProductHuntWeeklyItems(paper: $paper);
        }
        if (str_starts_with(haystack: $feedUrl, needle: 'rottentomatoes://')) {
            return $this->fetchRottenTomatoesItems(paper: $paper, feedUrl: $feedUrl);
        }
        if ($feedUrl === 'metacritic://albums') {
            return $this->fetchMetacriticAlbumItems(paper: $paper);
        }
        if (str_starts_with(haystack: $feedUrl, needle: 'metacritic://')) {
            return $this->fetchMetacriticGameItems(paper: $paper);
        }
        if (str_starts_with(haystack: $feedUrl, needle: 'lautde://')) {
            return $this->fetchLautAlbumItems(paper: $paper);
        }
        if (str_starts_with(haystack: $feedUrl, needle: 'ardmediathek://')) {
            return $this->fetchArdMediathekItems(paper: $paper, feedUrl: $feedUrl);
        }
        if (str_starts_with(haystack: $feedUrl, needle: 'zdfmediathek://')) {
            return $this->fetchZdfMediathekItems(paper: $paper, feedUrl: $feedUrl);
        }
        if (str_starts_with(haystack: $feedUrl, needle: 'wikipedia://')) {
            return $this->fetchWikipediaCurrentEventsItems(paper: $paper);
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

    /**
     * Scrape the GitHub Trending HTML page (no API key required). The feed URL
     * uses a custom scheme `github://trending-<window>` where <window> is one
     * of daily/weekly/monthly, mapped onto GitHub's `?since=` query parameter.
     *
     * @return array<int, FeedItem>
     */
    private function fetchGitHubTrendingItems(string $paper, string $feedUrl): array
    {
        $body = $this->cacheGet(key: 'feed:' . $paper);
        if ($body === null || $body === '') {
            $window = substr(string: $feedUrl, offset: strlen(string: 'github://trending-'));
            if (!in_array(needle: $window, haystack: ['daily', 'weekly', 'monthly'], strict: true)) {
                $window = 'weekly';
            }
            $url = 'https://github.com/trending?since=' . $window;
            $result = $this->fetchViaImpersonate(url: $url);
            if ($result->body === null) {
                return [];
            }
            $body = $result->body;
            $this->cacheSet(key: 'feed:' . $paper, value: $body);
        }
        return $this->parseGitHubTrending(html: $body);
    }

    /**
     * @return array<int, FeedItem>
     */
    private function parseGitHubTrending(string $html): array
    {
        $count = preg_match_all(
            pattern: '~<article class="Box-row">(.*?)</article>~s',
            subject: $html,
            matches: $blocks
        );
        if ($count === false || $count === 0 || !isset($blocks[1])) {
            return [];
        }
        $items = [];
        $now = time();
        foreach ($blocks[1] as $block) {
            if (
                preg_match(
                    pattern: '~<h2 class="h3 lh-condensed">.*?<a [^>]*?href="(/[^"]+)"~s',
                    subject: $block,
                    matches: $hrefMatch
                ) !== 1
            ) {
                continue;
            }
            $path = trim(string: $hrefMatch[1]);
            if ($path === '' || substr_count(haystack: $path, needle: '/') !== 2) {
                continue;
            }
            $repo = ltrim(string: $path, characters: '/');
            $description = '';
            if (
                preg_match(
                    pattern: '~<p[^>]*class="[^"]*col-9[^"]*"[^>]*>(.*?)</p>~s',
                    subject: $block,
                    matches: $descMatch
                ) === 1
            ) {
                $description = trim(string: html_entity_decode(
                    string: (string) preg_replace(pattern: '~\s+~u', replacement: ' ', subject: strip_tags(string: $descMatch[1])),
                    flags: ENT_QUOTES | ENT_HTML5,
                    encoding: 'UTF-8'
                ));
            }
            $language = '';
            if (
                preg_match(
                    pattern: '~<span itemprop="programmingLanguage">([^<]+)</span>~',
                    subject: $block,
                    matches: $langMatch
                ) === 1
            ) {
                $language = trim(string: $langMatch[1]);
            }
            $starsWeek = null;
            if (
                preg_match(
                    pattern: '~([0-9,]+)\s*stars\s*(?:this\s*week|today|this\s*month)~',
                    subject: $block,
                    matches: $weekMatch
                ) === 1
            ) {
                $starsWeek = (int) str_replace(search: ',', replace: '', subject: $weekMatch[1]);
            }
            $titleParts = [$repo];
            if ($language !== '') {
                $titleParts[] = '[' . $language . ']';
            }
            if ($description !== '') {
                $titleParts[] = '— ' . mb_substr(string: $description, start: 0, length: 220);
            }
            $items[] = new FeedItem(
                title: implode(separator: ' ', array: $titleParts),
                link: 'https://github.com' . $path,
                publishedAt: $now,
                imageUrl: 'https://opengraph.githubassets.com/1/' . $repo,
                rating: $starsWeek
            );
            if (count(value: $items) >= self::SOCIAL_FEED_MAX_ITEMS) {
                break;
            }
        }
        return $items;
    }

    /**
     * Scrape Wikipedia's Portal:Current_events into individual news items.
     * No official RSS feed exists for the portal, but the page has clean
     * semantic markup: a "Topics in the news" (ITN) highlights box and one
     * "vevent" block per day whose leaf <li> items each carry a news
     * sentence plus an external source link (Reuters, AP, Al Jazeera, …).
     *
     * @return array<int, FeedItem>
     */
    private function fetchWikipediaCurrentEventsItems(string $paper): array
    {
        $body = $this->cacheGet(key: 'feed:' . $paper);
        if ($body === null || $body === '') {
            $result = $this->fetchViaImpersonate(url: 'https://en.wikipedia.org/wiki/Portal:Current_events');
            if ($result->body === null) {
                return [];
            }
            $body = $result->body;
            $this->cacheSet(key: 'feed:' . $paper, value: $body);
        }
        return $this->parseWikipediaCurrentEvents(html: $body);
    }

    /**
     * @return array<int, FeedItem>
     */
    private function parseWikipediaCurrentEvents(string $html): array
    {
        if (trim(string: $html) === '') {
            return [];
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(use_errors: true);
        // The encoding hint forces UTF-8 so umlauts/dashes survive parsing.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors(use_errors: $previous);
        $xpath = new \DOMXPath(document: $dom);

        $items = [];
        $seen = [];
        $now = time();

        // 1) Top news — the "Topics in the news" highlights box. Each <li> is
        //    one item linking to a Wikipedia article. These get a rating boost
        //    so they outrank routine daily items in the magic bucket.
        $itnList = $xpath->query(expression: "//h2[@id='Topics_in_the_news']/following::ul[1]/li");
        if ($itnList !== false) {
            foreach ($itnList as $li) {
                if (!$li instanceof \DOMElement) {
                    continue;
                }
                $text = $this->wikipediaCleanItemText(xpath: $xpath, node: $li);
                // Drop the "(… pictured)" caption note from ITN items. It sits
                // just before the closing period, so allow trailing punctuation.
                $text = trim(string: (string) preg_replace(pattern: '~\s*\([^)]*pictured[^)]*\)\s*([.;,])?\s*$~iu', replacement: '$1', subject: $text));
                if (mb_strlen(string: $text) < 20) {
                    continue;
                }
                $link = $this->wikipediaBestLink(xpath: $xpath, node: $li);
                if ($link === '' || isset($seen[$link])) {
                    continue;
                }
                $seen[$link] = true;
                $items[] = new FeedItem(
                    title: mb_substr(string: $text, start: 0, length: 240),
                    link: $link,
                    publishedAt: $now,
                    imageUrl: null,
                    rating: 100
                );
            }
        }

        // 2) Daily news — leaf <li> items inside each day's content block that
        //    carry an external source citation. Days are already newest-first.
        $dayBlocks = $xpath->query(expression: "//div[contains(concat(' ', normalize-space(@class), ' '), ' current-events-main ')]");
        if ($dayBlocks !== false) {
            foreach ($dayBlocks as $day) {
                if (!$day instanceof \DOMElement) {
                    continue;
                }
                $publishedAt = $now;
                $bday = $xpath->query(expression: ".//span[contains(concat(' ', normalize-space(@class), ' '), ' bday ')]", contextNode: $day)->item(index: 0);
                if ($bday !== null) {
                    $ts = strtotime(datetime: trim(string: $bday->textContent));
                    if ($ts !== false) {
                        // Anchor at local noon so day-granular dates sort sanely.
                        $publishedAt = $ts + 12 * 3600;
                    }
                }
                $leaves = $xpath->query(
                    expression: ".//div[contains(concat(' ', normalize-space(@class), ' '), ' current-events-content ')]"
                        . "//li[not(.//ul) and .//a[contains(concat(' ', normalize-space(@class), ' '), ' external ')]]",
                    contextNode: $day
                );
                if ($leaves === false) {
                    continue;
                }
                foreach ($leaves as $li) {
                    if (!$li instanceof \DOMElement) {
                        continue;
                    }
                    $ext = $xpath->query(expression: ".//a[contains(concat(' ', normalize-space(@class), ' '), ' external ')]", contextNode: $li)->item(index: 0);
                    if (!$ext instanceof \DOMElement) {
                        continue;
                    }
                    $link = trim(string: $ext->getAttribute(qualifiedName: 'href'));
                    if ($link === '' || isset($seen[$link]) || !str_starts_with(haystack: $link, needle: 'http')) {
                        continue;
                    }
                    $text = $this->wikipediaCleanItemText(xpath: $xpath, node: $li);
                    if (mb_strlen(string: $text) < 20) {
                        continue;
                    }
                    $seen[$link] = true;
                    $items[] = new FeedItem(
                        title: mb_substr(string: $text, start: 0, length: 240),
                        link: $link,
                        publishedAt: $publishedAt,
                        imageUrl: null,
                        rating: null
                    );
                    if (count(value: $items) >= self::SOCIAL_FEED_MAX_ITEMS) {
                        return $items;
                    }
                }
            }
        }
        return $items;
    }

    /**
     * Plain-text of a Wikipedia list item with the trailing source-citation
     * anchors (e.g. "(Reuters)") stripped out.
     */
    private function wikipediaCleanItemText(\DOMXPath $xpath, \DOMElement $node): string
    {
        $clone = $node->cloneNode(deep: true);
        $externals = $xpath->query(expression: ".//a[contains(concat(' ', normalize-space(@class), ' '), ' external ')]", contextNode: $clone);
        if ($externals !== false) {
            foreach (iterator_to_array(iterator: $externals) as $a) {
                if ($a->parentNode !== null) {
                    $a->parentNode->removeChild(child: $a);
                }
            }
        }
        $text = (string) preg_replace(pattern: '~\s+~u', replacement: ' ', subject: $clone->textContent);
        return trim(string: $text);
    }

    /**
     * Best target link for a top-news item: prefer the bold (editorially
     * highlighted) Wikipedia article, else the first internal wiki link.
     * Returns an absolute en.wikipedia.org URL, or '' if none found.
     */
    private function wikipediaBestLink(\DOMXPath $xpath, \DOMElement $node): string
    {
        $bold = $xpath->query(expression: ".//b//a[starts-with(@href, '/wiki/')]", contextNode: $node)->item(index: 0);
        $anchor = $bold instanceof \DOMElement
            ? $bold
            : $xpath->query(expression: ".//a[starts-with(@href, '/wiki/')]", contextNode: $node)->item(index: 0);
        if (!$anchor instanceof \DOMElement) {
            return '';
        }
        $href = $anchor->getAttribute(qualifiedName: 'href');
        return $href !== '' ? 'https://en.wikipedia.org' . $href : '';
    }

    /**
     * Scrape the ProductHunt weekly leaderboard. Cloudflare-gated, so the
     * impersonating curl binary is mandatory (plain curl gets the JS
     * challenge). URL is built from the most recent COMPLETED ISO week —
     * the current week's leaderboard is still in flux and only finalises
     * after Sunday UTC.
     *
     * @return array<int, FeedItem>
     */
    private function fetchProductHuntWeeklyItems(string $paper): array
    {
        $body = $this->cacheGet(key: 'feed:' . $paper);
        $lastWeekTs = time() - 7 * 86400;
        $weekNumber = (int) date(format: 'W', timestamp: $lastWeekTs);
        $weekYear = (int) date(format: 'o', timestamp: $lastWeekTs);
        $weekStart = strtotime(datetime: $weekYear . 'W' . str_pad(string: (string) $weekNumber, length: 2, pad_string: '0', pad_type: STR_PAD_LEFT));
        // Date items at the week's END (Sunday ~23:00), not the Monday start:
        // the weekly leaderboard only finalises at week's end, and a Monday
        // timestamp pushes the items out of the 7-day "Zeitung" window by
        // mid-next-week. Clamp to now so it never lands in the future.
        $weekEnd = min(((int) ($weekStart !== false ? $weekStart : $lastWeekTs)) + 6 * 86400 + 23 * 3600, time());
        if ($body === null || $body === '') {
            $url = 'https://www.producthunt.com/leaderboard/weekly/' . $weekYear . '/' . $weekNumber;
            $result = $this->fetchViaImpersonate(url: $url);
            if ($result->body === null) {
                return [];
            }
            $body = $result->body;
            $this->cacheSet(key: 'feed:' . $paper, value: $body);
        }
        return $this->parseProductHuntLeaderboard(html: $body, publishedTs: $weekEnd);
    }

    /**
     * @return array<int, FeedItem>
     */
    private function parseProductHuntLeaderboard(string $html, int $publishedTs): array
    {
        // Each card carries a "<rank>. <title>" anchor that points to the
        // product slug; the span with data-target="true" marks the entry
        // point and lets us anchor the regex reliably across rebuilds.
        $count = preg_match_all(
            pattern: '~href="(/products/[a-z0-9-]+)"[^>]*>\s*<span[^>]*data-target="true"[^>]*></span>\s*(\d{1,2})\.\s*([^<]+)</a>~',
            subject: $html,
            matches: $matches,
            flags: PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );
        if ($count === false || $count === 0) {
            return [];
        }
        $items = [];
        foreach ($matches as $m) {
            $path = trim(string: $m[1][0]);
            $rank = (int) $m[2][0];
            $title = trim(string: html_entity_decode(string: $m[3][0], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
            $matchEnd = (int) $m[0][1] + strlen(string: (string) $m[0][0]);
            if ($path === '' || $title === '' || $rank < 1) {
                continue;
            }
            // Tagline lives between the title's closing </a> and the first
            // /topics/ link (the category list anchor). Strip SVGs first so
            // their inline path text doesn't leak in.
            $tail = substr(string: $html, offset: $matchEnd, length: 3000);
            // Cut at the opening "<a" of the first /topics/ link so the
            // preceding anchor element doesn't leave a stray "<a class=…"
            // fragment in the tagline once tags are stripped.
            $topicPos = false;
            if (preg_match(pattern: '~<a [^>]*href="/topics/~', subject: $tail, matches: $topicMatch, flags: PREG_OFFSET_CAPTURE) === 1) {
                $topicPos = (int) $topicMatch[0][1];
            }
            $taglineSlice = $topicPos !== false ? substr(string: $tail, offset: 0, length: $topicPos) : $tail;
            $taglineSlice = (string) preg_replace(pattern: '~<svg[^>]*>.*?</svg>~s', replacement: ' ', subject: $taglineSlice);
            $tagline = trim(string: (string) preg_replace(
                pattern: '~\s+~u',
                replacement: ' ',
                subject: (string) preg_replace(pattern: '~<[^>]+>~', replacement: ' ', subject: $taglineSlice)
            ));
            $tagline = mb_substr(string: $tagline, start: 0, length: 220);
            $titleParts = [sprintf('#%d %s', $rank, $title)];
            if ($tagline !== '') {
                $titleParts[] = '— ' . $tagline;
            }
            $items[] = new FeedItem(
                title: implode(separator: ' ', array: $titleParts),
                link: 'https://www.producthunt.com' . $path,
                publishedAt: $publishedTs,
                imageUrl: null,
                rating: max(0, 200 - $rank)
            );
            if (count(value: $items) >= self::SOCIAL_FEED_MAX_ITEMS) {
                break;
            }
        }
        return $items;
    }

    /**
     * Scrape Rotten Tomatoes browse grids via the undocumented cnapi JSON
     * endpoint (the napi sibling 404s; the SSR page only server-renders an
     * unrelated tile rail). Three feed variants share the custom scheme
     * rottentomatoes://<slug>: tv-series and movies-at-home loop over the
     * four streaming services, movies-in-theaters is a single grid.
     *
     * @return array<int, FeedItem>
     */
    private function fetchRottenTomatoesItems(string $paper, string $feedUrl): array
    {
        $slug = substr(string: $feedUrl, offset: strlen(string: 'rottentomatoes://'));
        $queries = [];
        if ($slug === 'movies-in-theaters') {
            $queries[''] = 'https://www.rottentomatoes.com/cnapi/browse/movies_in_theaters/sort:newest';
        }
        if ($slug === 'tv-series' || $slug === 'movies-at-home') {
            $browse = $slug === 'tv-series' ? 'tv_series_browse' : 'movies_at_home';
            foreach (self::STREAMING_SERVICES as $affiliate => $service) {
                $queries[$service] = 'https://www.rottentomatoes.com/cnapi/browse/' . $browse . '/affiliates:' . $affiliate . '~sort:newest';
            }
        }
        $items = [];
        $seenLinks = [];
        foreach ($queries as $service => $url) {
            $cacheKey = 'feed:' . $paper . ($service !== '' ? ':' . $service : '');
            $body = $this->cacheGet(key: $cacheKey);
            if ($body === null || $body === '') {
                $result = $this->fetchViaImpersonate(url: $url);
                if ($result->body === null) {
                    continue;
                }
                $body = $result->body;
                $this->cacheSet(key: $cacheKey, value: $body);
            }
            // A title available on several services keeps its first service —
            // the RT detail URL is identical across them.
            foreach ($this->parseRottenTomatoesGrid(json: $body, service: (string) $service) as $item) {
                if (isset($seenLinks[$item->link])) {
                    continue;
                }
                $seenLinks[$item->link] = true;
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * @return array<int, FeedItem>
     */
    private function parseRottenTomatoesGrid(string $json, string $service): array
    {
        $data = json_decode(json: $json, associative: true);
        $list = is_array(value: $data) ? ($data['grid']['list'] ?? null) : null;
        if (!is_array(value: $list)) {
            return [];
        }
        $now = time();
        $cutoff = $now - self::MEDIA_WINDOW_DAYS * 86400;
        $items = [];
        foreach ($list as $entry) {
            if (!is_array(value: $entry)) {
                continue;
            }
            $mediaUrl = (string) ($entry['mediaUrl'] ?? '');
            $title = trim(string: (string) ($entry['title'] ?? ''));
            if ($mediaUrl === '' || $title === '') {
                continue;
            }
            $dateText = (string) ($entry['releaseDateText'] ?? '');
            // Re-releases of decades-old classics dominate the "newest" sort
            // in theaters — they are not new content.
            if (str_starts_with(haystack: $dateText, needle: 'Re-released')) {
                continue;
            }
            $publishedAt = $now;
            // "Streaming Jul 10, 2026" / "Opened Jul 10, 2026".
            if (preg_match(pattern: '~([A-Z][a-z]{2} \d{1,2}, \d{4})$~', subject: $dateText, matches: $m) === 1) {
                $ts = strtotime(datetime: $m[1]);
                if ($ts !== false) {
                    if ($ts < $cutoff) {
                        continue;
                    }
                    $publishedAt = min($ts, $now);
                }
            }
            // "Latest Episode: Jul 10" — series carry no year; a December
            // episode read in January would parse one year ahead.
            if (preg_match(pattern: '~Latest Episode: ([A-Z][a-z]{2} \d{1,2})$~', subject: $dateText, matches: $m) === 1) {
                $ts = strtotime(datetime: $m[1] . ', ' . date(format: 'Y', timestamp: $now));
                if ($ts !== false && $ts > $now + 86400) {
                    $ts = strtotime(datetime: $m[1] . ', ' . ((int) date(format: 'Y', timestamp: $now) - 1));
                }
                if ($ts !== false) {
                    $publishedAt = min($ts, $now);
                }
            }
            $criticsScore = null;
            $critics = $entry['criticsScore'] ?? null;
            if (is_array(value: $critics) && ($critics['score'] ?? null) !== null && (string) $critics['score'] !== '') {
                $criticsScore = (int) $critics['score'];
            }
            $audienceScore = null;
            $audience = $entry['audienceScore'] ?? null;
            if (is_array(value: $audience) && ($audience['score'] ?? null) !== null && (string) $audience['score'] !== '') {
                $audienceScore = (int) $audience['score'];
            }
            // Entries without a critics score are catalog noise (reality
            // long-runners, B-movies, audience-only self-promotion) — the
            // media tabs rank by critic verdict, so that score is mandatory.
            if ($criticsScore === null) {
                continue;
            }
            $suffixParts = [];
            if ($service !== '') {
                $suffixParts[] = $service;
            }
            if ($criticsScore !== null) {
                $suffixParts[] = 'Kritiker ' . $criticsScore . ' %';
            }
            if ($audienceScore !== null) {
                $suffixParts[] = 'Publikum ' . $audienceScore . ' %';
            }
            $items[] = new FeedItem(
                title: $title . ' (' . implode(separator: ', ', array: $suffixParts) . ')',
                link: 'https://www.rottentomatoes.com' . $mediaUrl,
                publishedAt: $publishedAt,
                imageUrl: ((string) ($entry['posterUri'] ?? '')) !== '' ? (string) $entry['posterUri'] : null,
                rating: $criticsScore
            );
        }
        return $items;
    }

    /**
     * Scrape Metacritic's PC games browse pages (SSR HTML — the finder JSON
     * API silently ignores every platform parameter variant). Sorted by
     * metascore over the release-year window covering the media window, then
     * filtered to actual releases inside it, so only scored, genuinely new
     * games survive.
     *
     * @return array<int, FeedItem>
     */
    private function fetchMetacriticGameItems(string $paper): array
    {
        $now = time();
        $cutoff = $now - self::MEDIA_WINDOW_DAYS * 86400;
        $items = [];
        $seenLinks = [];
        foreach ([1, 2] as $page) {
            $cacheKey = 'feed:' . $paper . ':' . $page;
            $body = $this->cacheGet(key: $cacheKey);
            if ($body === null || $body === '') {
                $url = 'https://www.metacritic.com/browse/game/pc/?releaseYearMin=' . date(format: 'Y', timestamp: $cutoff)
                    . '&releaseYearMax=' . date(format: 'Y', timestamp: $now) . '&sortBy=-metaScore&page=' . $page;
                $result = $this->fetchViaImpersonate(url: $url);
                if ($result->body === null) {
                    continue;
                }
                $body = $result->body;
                $this->cacheSet(key: $cacheKey, value: $body);
            }
            foreach ($this->parseMetacriticGames(html: $body, cutoff: $cutoff) as $item) {
                if (isset($seenLinks[$item->link])) {
                    continue;
                }
                $seenLinks[$item->link] = true;
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * @return array<int, FeedItem>
     */
    private function parseMetacriticGames(string $html, int $cutoff): array
    {
        // One product card per anchor; the tempered dot keeps the match
        // inside the same <a>, so nav links to /game/... never match (they
        // carry no Metascore inside the anchor).
        $count = preg_match_all(
            pattern: '~<a[^>]+href="(/game/[^"]+)"[^>]*>((?:(?!</a>).)*?Metascore (\d+) out of 100)~s',
            subject: $html,
            matches: $cards,
            flags: PREG_SET_ORDER
        );
        if ($count === false || $count === 0) {
            return [];
        }
        $now = time();
        $items = [];
        foreach ($cards as $card) {
            $body = $card[2];
            if (preg_match(pattern: '~data-title="([^"]+)"~', subject: $body, matches: $titleMatch) !== 1) {
                continue;
            }
            $title = trim(string: html_entity_decode(string: $titleMatch[1], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
            if ($title === '') {
                continue;
            }
            if (preg_match(pattern: '~<span>([A-Z][a-z]{2} \d{1,2}, \d{4})</span>~', subject: $body, matches: $dateMatch) !== 1) {
                continue;
            }
            $releasedAt = strtotime(datetime: $dateMatch[1]);
            if ($releasedAt === false || $releasedAt < $cutoff || $releasedAt > $now) {
                continue;
            }
            // The lazy-loaded cover ships a 1x/2x srcset — take the sharper
            // 2x variant as thumbnail source.
            $imageUrl = null;
            if (preg_match(pattern: '~srcset="([^"]*)"~', subject: $body, matches: $srcsetMatch) === 1) {
                $variants = explode(separator: ',', string: html_entity_decode(string: $srcsetMatch[1], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
                $last = trim(string: (string) end(array: $variants));
                $candidate = trim(string: (string) preg_replace(pattern: '~\s+\d+x$~', replacement: '', subject: $last));
                if (str_starts_with(haystack: $candidate, needle: 'https://')) {
                    $imageUrl = $candidate;
                }
            }
            $score = (int) $card[3];
            $items[] = new FeedItem(
                title: $title . ' (PC, Metascore ' . $score . ')',
                link: 'https://www.metacritic.com' . $card[1],
                publishedAt: $releasedAt,
                imageUrl: $imageUrl,
                rating: $score
            );
        }
        return $items;
    }

    /**
     * Scrape Metacritic's legacy album new-releases browse list (the only
     * music surface that survived the 2023 redesign — the finder API knows
     * no music product type and /music/ paths 403). Rows carry title,
     * artist, release date, metascore and cover; only fresh releases at or
     * above METACRITIC_ALBUM_MIN_SCORE survive.
     *
     * @return array<int, FeedItem>
     */
    private function fetchMetacriticAlbumItems(string $paper): array
    {
        $body = $this->cacheGet(key: 'feed:' . $paper);
        if ($body === null || $body === '') {
            $result = $this->fetchViaImpersonate(url: 'https://www.metacritic.com/browse/albums/release-date/new-releases/date');
            if ($result->body === null) {
                return [];
            }
            $body = $result->body;
            $this->cacheSet(key: 'feed:' . $paper, value: $body);
        }
        return $this->parseMetacriticAlbums(html: $body);
    }

    /**
     * @return array<int, FeedItem>
     */
    private function parseMetacriticAlbums(string $html): array
    {
        $count = preg_match_all(
            pattern: '~<div class="metascore_w large release[^"]*">(\d+)</div>\s*</a>\s*</div>\s*<a href="(/music/[^"]+)" class="title"><h3>([^<]+)</h3></a>\s*<div class="clamp-details">\s*<div class="artist">\s*by ([^<]+?)\s*</div>\s*<span>([A-Z][a-z]+ \d{1,2}, \d{4})</span>~s',
            subject: $html,
            matches: $rows,
            flags: PREG_SET_ORDER
        );
        if ($count === false || $count === 0) {
            return [];
        }
        // Cover lookup: the poster cell precedes the summary cell, keyed by
        // the same detail href.
        $covers = [];
        if (preg_match_all(pattern: '~<a href="(/music/[^"]+)"><img src="(https://static\.metacritic\.com/[^"]+)"~', subject: $html, matches: $imgMatches, flags: PREG_SET_ORDER) > 0) {
            foreach ($imgMatches as $imgMatch) {
                $covers[$imgMatch[1]] = $imgMatch[2];
            }
        }
        $now = time();
        $cutoff = $now - self::MEDIA_WINDOW_DAYS * 86400;
        $items = [];
        $seenPaths = [];
        foreach ($rows as $row) {
            $score = (int) $row[1];
            if ($score < self::METACRITIC_ALBUM_MIN_SCORE) {
                continue;
            }
            $path = $row[2];
            if (isset($seenPaths[$path])) {
                continue;
            }
            $releasedAt = strtotime(datetime: $row[5]);
            if ($releasedAt === false || $releasedAt < $cutoff || $releasedAt > $now) {
                continue;
            }
            $albumTitle = trim(string: html_entity_decode(string: $row[3], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
            $artist = trim(string: html_entity_decode(string: $row[4], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
            if ($albumTitle === '' || $artist === '') {
                continue;
            }
            $seenPaths[$path] = true;
            $items[] = new FeedItem(
                title: '"' . $albumTitle . '" von ' . $artist . ' (Metascore ' . $score . ')',
                link: 'https://www.metacritic.com' . $path,
                publishedAt: $releasedAt,
                imageUrl: $covers[$path] ?? null,
                rating: $score
            );
        }
        return $items;
    }

    /**
     * Scrape laut.de's album review list. The RSS feed carries neither
     * ratings nor covers, but the /Alben teaser list exposes both via
     * data-authors-rating (1-5) plus cover art. Only top-rated albums
     * (>= LAUT_MIN_RATING) survive; the unrated first teaser is laut.de's
     * own "Album der Woche" pick and counts as top by definition.
     *
     * @return array<int, FeedItem>
     */
    private function fetchLautAlbumItems(string $paper): array
    {
        $body = $this->cacheGet(key: 'feed:' . $paper);
        if ($body === null || $body === '') {
            $result = $this->fetchViaImpersonate(url: 'https://www.laut.de/Alben');
            if ($result->body === null) {
                return [];
            }
            $body = $result->body;
            $this->cacheSet(key: 'feed:' . $paper, value: $body);
        }
        return $this->parseLautAlbums(html: $body);
    }

    /**
     * @return array<int, FeedItem>
     */
    private function parseLautAlbums(string $html): array
    {
        // Only the "Album der Woche" and "Neu" sections hold new releases —
        // the "Topklicks" (section id releases_top) and "Meilensteine" rails
        // below re-surface classics.
        $cut = strpos(haystack: $html, needle: 'id="releases_top"');
        if ($cut !== false) {
            $html = substr(string: $html, offset: 0, length: $cut);
        }
        $count = preg_match_all(
            pattern: '~<article class="teaser"([^>]*)>(.*?)</article>~s',
            subject: $html,
            matches: $teasers,
            flags: PREG_SET_ORDER
        );
        if ($count === false || $count === 0) {
            return [];
        }
        $now = time();
        $items = [];
        $seenPaths = [];
        foreach ($teasers as $teaser) {
            $rating = null;
            if (preg_match(pattern: '~data-authors-rating="(\d)"~', subject: $teaser[1], matches: $ratingMatch) === 1) {
                $rating = (int) $ratingMatch[1];
            }
            $isAlbumOfWeek = $rating === null && str_contains(haystack: $teaser[2], needle: 'Album der Woche');
            if (!$isAlbumOfWeek && ($rating === null || $rating < self::LAUT_MIN_RATING)) {
                continue;
            }
            if (preg_match(pattern: '~href="(/[^"]+/Alben/[^"#]+)"~', subject: $teaser[2], matches: $hrefMatch) !== 1) {
                continue;
            }
            $path = $hrefMatch[1];
            if (isset($seenPaths[$path])) {
                continue;
            }
            // Two teaser layouts: the "Album der Woche" lead uses
            // artist/title spans, the rated list entries a teasertitel h3.
            if (
                preg_match(pattern: '~<span class="artist">([^<]*)</span>\s*<span class="title">([^<]*)</span>~s', subject: $teaser[2], matches: $nameMatch) !== 1
                && preg_match(pattern: '~<h3 class="teasertitel">\s*<span>([^<]*)</span>\s*<strong[^>]*>([^<]*)</strong>~s', subject: $teaser[2], matches: $nameMatch) !== 1
            ) {
                continue;
            }
            $artist = trim(string: html_entity_decode(string: $nameMatch[1], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
            $albumTitle = trim(string: html_entity_decode(string: $nameMatch[2], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
            if ($artist === '' || $albumTitle === '') {
                continue;
            }
            $imageUrl = null;
            if (preg_match(pattern: '~<img[^>]+src="(/bilder/[^"]+)"~', subject: $teaser[2], matches: $imgMatch) === 1) {
                // Strip the "__<w>,<h>/" resize segment for the full-size cover.
                $coverPath = (string) preg_replace(pattern: '~^/bilder/__\d+,\d+/~', replacement: '/bilder/', subject: html_entity_decode(string: $imgMatch[1], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
                $imageUrl = 'https://www.laut.de' . $coverPath;
            }
            $seenPaths[$path] = true;
            $suffix = $isAlbumOfWeek ? 'laut.de Album der Woche' : 'laut.de ' . $rating . '/5';
            $items[] = new FeedItem(
                title: '"' . $albumTitle . '" von ' . $artist . ' (' . $suffix . ')',
                link: 'https://www.laut.de' . $path,
                publishedAt: $now,
                imageUrl: $imageUrl,
                // Normalised to the 0-100 scale every other media source
                // uses, so the rating-ranked tabs mix sources fairly.
                rating: ($isAlbumOfWeek ? 5 : $rating) * 20
            );
        }
        return $items;
    }

    /**
     * Fetch the ARD Mediathek "Sendung" landing page and parse it into one
     * FeedItem per full episode. Maischberger lists both the full Sendung
     * ("maischberger am 10.06.2026") and per-guest topic segments; we keep
     * only the full episodes and fold each day's segment titles into a
     * guest list. Miosga / hart aber fair only list full episodes anyway.
     *
     * @return array<int, FeedItem>
     */
    private function fetchArdMediathekItems(string $paper, string $feedUrl): array
    {
        $body = $this->cacheGet(key: 'feed:' . $paper);
        if ($body === null || $body === '') {
            $path = substr(string: $feedUrl, offset: strlen(string: 'ardmediathek://'));
            $url = 'https://www.ardmediathek.de/sendung/' . $path;
            $result = $this->fetchViaImpersonate(url: $url);
            if ($result->body === null) {
                return [];
            }
            $body = $result->body;
            $this->cacheSet(key: 'feed:' . $paper, value: $body);
        }
        return $this->parseArdMediathek(html: $body, paper: $paper);
    }

    /**
     * @return array<int, FeedItem>
     */
    private function parseArdMediathek(string $html, string $paper): array
    {
        // Each video tile is an <a href="/video/<show>/<slug>/<broadcaster>/<id>">
        // wrapping <h3>title</h3>, a DD.MM.YYYY metadata line and a picture
        // backed by api.ardmediathek.de/image-service/images/urn:ard:image:<hash>.
        $count = preg_match_all(
            pattern: '~<a [^>]*href="(/video/[^"]+)"[^>]*>(.*?)</a>~s',
            subject: $html,
            matches: $matches,
            flags: PREG_SET_ORDER
        );
        if ($count === false || $count === 0) {
            return [];
        }
        $papers = $this->papers();
        $label = (string) ($papers[$paper]['label'] ?? $paper);
        $defaultImage = (string) ($papers[$paper]['default_image'] ?? '');

        $entries = [];
        $seenUrls = [];
        foreach ($matches as $m) {
            $path = $m[1];
            $inner = $m[2];
            if (!str_contains(haystack: $path, needle: '/video/')) {
                continue;
            }
            if (preg_match(pattern: '~<h3[^>]*>([^<]+)</h3>~', subject: $inner, matches: $hm) !== 1) {
                continue;
            }
            $title = trim(string: html_entity_decode(string: $hm[1], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
            if ($title === '' || str_contains(haystack: $title, needle: 'Gebärdensprache')) {
                continue;
            }
            $publishedAt = time();
            if (preg_match(pattern: '~(\d\d)\.(\d\d)\.(\d{2,4})~', subject: $inner, matches: $dm) === 1) {
                $year = (int) $dm[3];
                if ($year < 100) {
                    $year += 2000;
                }
                $ts = mktime(hour: 22, minute: 15, second: 0, month: (int) $dm[2], day: (int) $dm[1], year: $year);
                if ($ts !== false) {
                    $publishedAt = $ts;
                }
            }
            $imageUrl = null;
            if (preg_match(pattern: '~urn(?::|%3A)ard(?::|%3A)image(?::|%3A)([a-z0-9]+)~i', subject: $inner, matches: $im) === 1) {
                $imageUrl = 'https://api.ardmediathek.de/image-service/images/urn:ard:image:' . $im[1] . '?w=1024';
            }
            $fullUrl = 'https://www.ardmediathek.de' . $path;
            if (isset($seenUrls[$fullUrl])) {
                continue;
            }
            $seenUrls[$fullUrl] = true;
            // Episode slug is the second path segment (/video/<show>/<slug>/…).
            $episodeSlug = '';
            $parts = explode(separator: '/', string: trim(string: $path, characters: '/'));
            if (count(value: $parts) >= 3) {
                $episodeSlug = $parts[2];
            }
            $entries[] = [
                'title' => $title,
                'link' => $fullUrl,
                'publishedAt' => $publishedAt,
                'imageUrl' => $imageUrl,
                'slug' => $episodeSlug,
            ];
        }
        if ($entries === []) {
            return [];
        }

        // Split into "full episode" entries (slug matches <show>-am-DD-MM-YYYY)
        // and topic segments. If any full-episode entry exists we keep only
        // those and aggregate the same-day segment titles into a guest list.
        $hauptsendungen = [];
        $segments = [];
        foreach ($entries as $e) {
            if (preg_match(pattern: '~-am-\d{1,2}-\d{1,2}-\d{2,4}(?:$|/)~', subject: $e['slug']) === 1) {
                $hauptsendungen[] = $e;
            } else {
                $segments[] = $e;
            }
        }

        // Skip anything older than TV_MAX_AGE_DAYS — ARD-Mediathek keeps
        // some shows (hart aber fair, Miosga) online for half a year, but
        // the dashboard only wants the recent episodes.
        $ageCutoff = time() - self::TV_MAX_AGE_DAYS * 86400;

        $items = [];
        if ($hauptsendungen !== []) {
            $segmentsByDate = [];
            foreach ($segments as $s) {
                $key = date(format: 'Y-m-d', timestamp: $s['publishedAt']);
                $segmentsByDate[$key] = $segmentsByDate[$key] ?? [];
                $segmentsByDate[$key][] = $s;
            }
            foreach ($hauptsendungen as $h) {
                if ($h['publishedAt'] < $ageCutoff) {
                    continue;
                }
                $dateLabel = date(format: 'd.m.Y', timestamp: $h['publishedAt']);
                $key = date(format: 'Y-m-d', timestamp: $h['publishedAt']);
                $guests = [];
                foreach ($segmentsByDate[$key] ?? [] as $seg) {
                    foreach ($this->extractArdSegmentGuests(title: $seg['title']) as $name) {
                        if (!in_array(needle: $name, haystack: $guests, strict: true)) {
                            $guests[] = $name;
                        }
                    }
                }
                $newTitle = $label . ' ' . $dateLabel;
                if ($guests !== []) {
                    $newTitle .= ': Gäste: ' . implode(separator: ', ', array: $guests);
                }
                $items[] = new FeedItem(
                    title: $newTitle,
                    link: $h['link'],
                    publishedAt: $h['publishedAt'],
                    imageUrl: $h['imageUrl'] ?? ($defaultImage !== '' ? $defaultImage : null),
                    rating: null
                );
                if (count(value: $items) >= self::SOCIAL_FEED_MAX_ITEMS) {
                    break;
                }
            }
        } else {
            // No "<show>-am-…" slug pattern → all entries are full episodes
            // already (Miosga / hart aber fair). Just normalise the title.
            foreach ($entries as $e) {
                if ($e['publishedAt'] < $ageCutoff) {
                    continue;
                }
                $dateLabel = date(format: 'd.m.Y', timestamp: $e['publishedAt']);
                $items[] = new FeedItem(
                    title: $label . ' ' . $dateLabel . ': ' . $e['title'],
                    link: $e['link'],
                    publishedAt: $e['publishedAt'],
                    imageUrl: $e['imageUrl'] ?? ($defaultImage !== '' ? $defaultImage : null),
                    rating: null
                );
                if (count(value: $items) >= self::SOCIAL_FEED_MAX_ITEMS) {
                    break;
                }
            }
        }
        return $items;
    }

    /**
     * Extract guest names from a Maischberger topic segment title. Segment
     * titles look like "<Names> über <Topic>" or "<Names>: <Topic>". Take
     * everything before the boundary marker, split on " und " / "," and
     * keep the trailing 2-3 capitalised words to strip preceding role
     * labels ("CDU-Außenpolitiker Armin Laschet" → "Armin Laschet").
     *
     * @return array<int, string>
     */
    private function extractArdSegmentGuests(string $title): array
    {
        // Boundary markers used in segment titles before the topic part.
        if (preg_match(
            pattern: '~^(.+?)(?:\s+über\s|\s+im\s+Gespräch|\s+diskutier|\s*[:·]\s)~u',
            subject: $title,
            matches: $bm
        ) !== 1) {
            return [];
        }
        $head = trim(string: $bm[1]);
        $parts = preg_split(pattern: '~\s*(?:,|\s+und\s+)\s*~u', subject: $head) ?: [];
        $names = [];
        foreach ($parts as $part) {
            $part = trim(string: (string) preg_replace(pattern: '~\s*\([^)]*\)\s*$~', replacement: '', subject: $part));
            if (preg_match(
                pattern: '~((?:[A-ZÄÖÜ][\p{L}\.\-]+\s+){1,2}[A-ZÄÖÜ][\p{L}\.\-]+)\s*$~u',
                subject: $part,
                matches: $pm
            ) === 1) {
                $name = trim(string: $pm[1]);
                if ($name !== '' && !in_array(needle: $name, haystack: $names, strict: true)) {
                    $names[] = $name;
                }
            }
        }
        return $names;
    }

    /**
     * Fetch a ZDF Mediathek show page, then crawl the latest episodes (max
     * 8) to extract guest names. ZDF guests live in <b>Name, Role</b>
     * blocks inside the episode page, not on the overview, so a two-stage
     * fetch is required.
     *
     * @return array<int, FeedItem>
     */
    private function fetchZdfMediathekItems(string $paper, string $feedUrl): array
    {
        $body = $this->cacheGet(key: 'feed:' . $paper);
        $slug = substr(string: $feedUrl, offset: strlen(string: 'zdfmediathek://'));
        if ($body === null || $body === '') {
            $url = 'https://www.zdf.de/talk/' . $slug;
            $result = $this->fetchViaImpersonate(url: $url);
            if ($result->body === null) {
                return [];
            }
            $body = $result->body;
            $this->cacheSet(key: 'feed:' . $paper, value: $body);
        }
        return $this->parseZdfMediathek(html: $body, slug: $slug, paper: $paper);
    }

    /**
     * @return array<int, FeedItem>
     */
    private function parseZdfMediathek(string $html, string $slug, string $paper): array
    {
        // Strip "-114" / "-128" trailing show ID so the per-episode slug
        // pattern works for any show. ZDF episode slugs always follow
        // <show-name>-vom-<day>-<month>-<year>-<seq>.
        $showBase = preg_replace(pattern: '~-\d+$~', replacement: '', subject: $slug) ?? $slug;
        // Canonical strings appear in the page as JSON-escaped within
        // self.__next_f.push payloads (\"canonical\":\"…\") so we just
        // match the slug body itself rather than the surrounding JSON.
        $count = preg_match_all(
            pattern: '~' . preg_quote(str: $showBase, delimiter: '~') . '-vom-\d{1,2}-[a-zäöü]+-\d{4}-\d+~u',
            subject: $html,
            matches: $found
        );
        if ($count === false || $count === 0) {
            return [];
        }
        // ZDF slugs transliterate "März" to "maerz" (ä → ae); accept both.
        $monthMap = [
            'januar' => 1, 'februar' => 2, 'märz' => 3, 'maerz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6,
            'juli' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12
        ];
        $papers = $this->papers();
        $label = (string) ($papers[$paper]['label'] ?? $paper);
        $defaultImage = (string) ($papers[$paper]['default_image'] ?? '');
        $ageCutoff = time() - self::TV_MAX_AGE_DAYS * 86400;
        $items = [];
        $seenCanonical = [];
        foreach ($found[0] as $canonical) {
            if (isset($seenCanonical[$canonical])) {
                continue;
            }
            $seenCanonical[$canonical] = true;

            // ZDF slugs always carry an explicit date — if it doesn't parse
            // we treat the episode as undateable and skip rather than
            // dropping it into "today" with a wrong timestamp.
            $publishedAt = null;
            if (preg_match(pattern: '~-vom-(\d{1,2})-([a-zäöü]+)-(\d{4})-~u', subject: $canonical, matches: $dm) === 1) {
                $mon = $monthMap[mb_strtolower(string: $dm[2])] ?? 0;
                if ($mon > 0) {
                    $ts = mktime(hour: 23, minute: 15, second: 0, month: $mon, day: (int) $dm[1], year: (int) $dm[3]);
                    if ($ts !== false) {
                        $publishedAt = $ts;
                    }
                }
            }
            if ($publishedAt === null || $publishedAt < $ageCutoff) {
                continue;
            }
            $episodeUrl = 'https://www.zdf.de/video/talk/' . $slug . '/' . $canonical;
            $meta = $this->fetchZdfEpisodeGuests(url: $episodeUrl, canonical: $canonical);
            // The episode <title> tag carries the actual discussion topic
            // (e.g. "Zum Auftakt der Fußball-WM: Die amerikanische Sport-
            // kultur"); strip the show-name-and-channel suffix that the
            // og:title injects on some episodes.
            $topic = (string) ($meta['topic'] ?? '');
            // Strip trailing "| <Show> streamen im ZDF" / "| im ZDF" decoration.
            $topic = (string) preg_replace(
                pattern: '~\s*[|·]\s*(?:&quot;)?(?:' . preg_quote(str: $label, delimiter: '~') . '|maybrit illner|markus lanz)(?:&quot;)?\s+(?:streamen\s+)?im\s+ZDF\s*$~iu',
                replacement: '',
                subject: $topic
            );
            // Strip leading "<show name>: " prefix — the episode <title> tag
            // sometimes repeats the show name before the actual topic.
            $topic = (string) preg_replace(
                pattern: '~^\s*(?:' . preg_quote(str: $label, delimiter: '~') . '|maybrit illner|markus lanz|illner|lanz)\s*:\s*~iu',
                replacement: '',
                subject: $topic
            );
            $topic = trim(string: $topic, characters: " \t\n\r\0\x0B\"'");
            // If the topic boils down to the bare show label, drop it —
            // older episode pages sometimes carry only "Markus Lanz" as
            // <title> tag and would otherwise produce "Markus Lanz DD.MM.YYYY: Markus Lanz".
            if (mb_strtolower(string: $topic) === mb_strtolower(string: $label)) {
                $topic = '';
            }
            $dateLabel = date(format: 'd.m.Y', timestamp: $publishedAt);
            $newTitle = $label . ' ' . $dateLabel;
            if ($topic !== '') {
                $newTitle .= ': ' . $topic;
            }
            if (!empty($meta['guests'])) {
                $newTitle .= ' — Gäste: ' . implode(separator: ', ', array: $meta['guests']);
            }
            $imageUrl = $meta['image'] ?? null;
            if ($imageUrl === null && $defaultImage !== '') {
                $imageUrl = $defaultImage;
            }
            $items[] = new FeedItem(
                title: $newTitle,
                link: $episodeUrl,
                publishedAt: $publishedAt,
                imageUrl: $imageUrl,
                rating: null
            );
            // Cap per-show crawl: most viewers only care about the last week's
            // shows and each episode page is a separate impersonated fetch.
            if (count(value: $items) >= 8) {
                break;
            }
        }
        return $items;
    }

    /**
     * @return array{guests: array<int, string>, image: ?string, topic: string, airing: string}
     */
    private function fetchZdfEpisodeGuests(string $url, string $canonical): array
    {
        $cacheKey = 'zdf-episode:' . md5(string: $canonical);
        $body = $this->cacheGet(key: $cacheKey);
        if ($body === null || $body === '') {
            $result = $this->fetchViaImpersonate(url: $url);
            if ($result->body === null) {
                return ['guests' => [], 'image' => null, 'topic' => '', 'airing' => ''];
            }
            $body = $result->body;
            $this->cacheSet(key: $cacheKey, value: $body);
        }
        // ZDF talk shows expose their guest list in two different ways:
        //   - Lanz: <b>Name Surname, Rolle</b> blocks in the body
        //   - Illner: description meta tag like "Zu Gast: A, B (SPD), C"
        // Try the structured Lanz markup first; on a miss, fall back to the
        // meta-description pattern which always works for Illner.
        $guests = [];
        if (preg_match_all(
            pattern: '~<b>([A-ZÄÖÜ][\p{L}\.\-]+(?:\s+[A-ZÄÖÜ][\p{L}\.\-]+){1,3}),[^<]+</b>~u',
            subject: $body,
            matches: $gm
        ) > 0) {
            foreach ($gm[1] as $name) {
                $clean = trim(string: html_entity_decode(string: $name, flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
                if ($clean !== '' && !in_array(needle: $clean, haystack: $guests, strict: true)) {
                    $guests[] = $clean;
                }
                if (count(value: $guests) >= 5) {
                    break;
                }
            }
        }
        // Description-based fallback for shows that don't use <b>…</b> guest
        // blocks (mainly Illner). The description meta tag carries a comma-
        // separated guest list under one of several phrasings.
        if ($guests === []) {
            $descGuestList = '';
            if (preg_match(pattern: '~<meta\s+name="description"\s+content="[^"]*?Zu Gast(?:\s+bei[^:]+sind|\s+am[^:]+:|:)\s*([^"]+)"~u', subject: $body, matches: $zg) === 1) {
                $descGuestList = $zg[1];
            } elseif (preg_match(pattern: '~<meta\s+name="description"\s+content="[^"]*?(?:diskutiert|empfängt|spricht)\s+mit\s+([^"]+)"~u', subject: $body, matches: $dg) === 1) {
                $descGuestList = $dg[1];
            }
            if ($descGuestList !== '') {
                $list = html_entity_decode(string: $descGuestList, flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');
                // Split on commas + " und " conjunction; strip trailing period
                // and parenthesised party tags; keep the trailing 2-3
                // capitalised words to drop any preceding role label.
                $parts = preg_split(pattern: '~\s*(?:,|\s+und\s+)\s*~u', subject: rtrim(string: $list, characters: ' .')) ?: [];
                foreach ($parts as $part) {
                    $part = (string) preg_replace(pattern: '~\s*\([^)]*\)\s*$~', replacement: '', subject: $part);
                    if (preg_match(pattern: '~((?:[A-ZÄÖÜ][\p{L}\.\-]+\s+){1,2}[A-ZÄÖÜ][\p{L}\.\-]+)\s*$~u', subject: $part, matches: $pm) === 1) {
                        $clean = trim(string: $pm[1]);
                        if ($clean !== '' && !in_array(needle: $clean, haystack: $guests, strict: true)) {
                            $guests[] = $clean;
                        }
                    }
                    if (count(value: $guests) >= 5) {
                        break;
                    }
                }
            }
        }
        $image = null;
        if (preg_match(pattern: '~<meta property="og:image" content="([^"]+)"~', subject: $body, matches: $im) === 1) {
            $image = html_entity_decode(string: $im[1], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');
        }
        $topic = '';
        if (preg_match(pattern: '~<title>([^<]+)</title>~', subject: $body, matches: $tm) === 1) {
            $topic = trim(string: html_entity_decode(string: $tm[1], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
        }
        $airing = '';
        if (preg_match(pattern: '~<meta property="og:title" content="([^"]+)"~', subject: $body, matches: $am) === 1) {
            $airing = trim(string: html_entity_decode(string: $am[1], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
        }
        return ['guests' => $guests, 'image' => $image, 'topic' => $topic, 'airing' => $airing];
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
        // Trailing newline so bash `while read` in buildParallelPipeline()
        // does not drop the last entry (read returns non-zero at EOF).
        file_put_contents(filename: $tmpIn, data: implode(separator: "\n", array: $toProbe) . "\n");

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
        if (!in_array(needle: 'thumbnail_fail_count', haystack: $columns, strict: true)) {
            $db->exec(statement: 'ALTER TABLE articles ADD COLUMN thumbnail_fail_count INTEGER NOT NULL DEFAULT 0');
        }
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
            'papers' => is_array(value: $parsed['papers'] ?? null) ? $parsed['papers'] : [],
            'weather_location' => trim(string: (string) ($parsed['weather_location'] ?? '')),
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
            'Kultur & Medien' => ['Musik', 'Film & TV', 'Kunst & Ausstellungen', 'Reality-TV', 'TV', 'Kulturbetrieb'],
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

    private function paperDomain(array $info): string
    {
        $host = (string) parse_url(url: (string) ($info['url'] ?? ''), component: PHP_URL_HOST);
        return (string) preg_replace(pattern: '~^(?:www|m)\.~', replacement: '', subject: $host);
    }

    /**
     * All paper keys sharing the given paper's domain (e.g. the two Medium
     * tag feeds) — they act as one source in the dropdown and its filter.
     *
     * @return array<int, string>
     */
    private function paperDomainSiblings(string $paper): array
    {
        $papers = $this->papers();
        $tvPapers = $this->talkshowPapers();
        if (!isset($papers[$paper]) || in_array(needle: $paper, haystack: $tvPapers, strict: true)) {
            return [$paper];
        }
        $domain = $this->paperDomain(info: $papers[$paper]);
        $siblings = [];
        foreach ($papers as $key => $info) {
            if (in_array(needle: (string) $key, haystack: $tvPapers, strict: true)) {
                continue;
            }
            if ($this->paperDomain(info: $info) === $domain) {
                $siblings[] = (string) $key;
            }
        }
        return $siblings;
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
     * Paper keys whose RSS uses an ardmediathek:// or zdfmediathek:// scheme.
     * One source of truth — used by the dedup exclusion (Phase 8) and the
     * Zeitung TV digest filter (Phase 10).
     *
     * @return array<int, string>
     */
    private function talkshowPapers(): array
    {
        $keys = [];
        foreach ($this->papers() as $key => $cfg) {
            $rss = (string) ($cfg['rss'] ?? '');
            if (str_starts_with(haystack: $rss, needle: 'ardmediathek://') || str_starts_with(haystack: $rss, needle: 'zdfmediathek://')) {
                $keys[] = (string) $key;
            }
        }
        return $keys;
    }

    /**
     * Paper keys carrying a fixed "category" in their config (media sources
     * like Rotten Tomatoes, Metacritic, laut.de). Their items are
     * monothematic: Phase 1 seeds the category cache with the configured
     * value and Phase 8 skips them during dedup.
     *
     * @return array<int, string>
     */
    private function fixedCategoryPapers(): array
    {
        $keys = [];
        foreach ($this->papers() as $key => $cfg) {
            if ((string) ($cfg['category'] ?? '') !== '') {
                $keys[] = (string) $key;
            }
        }
        return $keys;
    }

    /**
     * Media shortcut tabs (Serien/Filme/Alben/Games) — each is a Meldungen
     * preset filtering on the given paper keys, same pattern as the
     * "talkshows" view. Keys double as view names and ?media= values.
     *
     * @return array<string, array{label: string, papers: array<int, string>}>
     */
    private function mediaTabs(): array
    {
        return [
            'serien' => ['label' => 'Serien', 'papers' => ['streaming_serien']],
            'filme' => ['label' => 'Filme', 'papers' => ['streaming_filme', 'kino']],
            'alben' => ['label' => 'Alben', 'papers' => ['alben', 'alben_metacritic']],
            'games' => ['label' => 'Games', 'papers' => ['spiele']],
        ];
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
     * Bail-out gate for any entry point that depends on the AI provider.
     * Verifies provider/model/base URL are configured and that the host
     * answers any HTTP response within a short timeout. Emits a clear
     * warning on failure and returns false so the caller can `return;`
     * without touching the DB or caches.
     */
    private function preflightAi(callable $emit): bool
    {
        $env = $this->loadEnv();
        $aiProvider = (string) ($env['AI_PROVIDER'] ?? '');
        $aiModel = (string) ($env['AI_MODEL'] ?? '');
        $aiBaseUrl = (string) ($env['AI_BASE_URL'] ?? '');
        $aiApiKey = (string) ($env['AI_API_KEY'] ?? '');
        if ($aiProvider === '' || $aiModel === '' || $aiBaseUrl === '') {
            $emit('⚠️  AI nicht vollständig konfiguriert (AI_PROVIDER / AI_MODEL / AI_BASE_URL in .env) — Scrape abgebrochen.');
            return false;
        }
        $reach = $this->checkAiHostReachable(baseUrl: $aiBaseUrl, apiKey: $aiApiKey);
        if (!$reach['ok']) {
            $emit(sprintf('⚠️  AI-Host nicht erreichbar (%s): %s — Scrape abgebrochen.', $aiBaseUrl, $reach['reason']));
            return false;
        }
        $emit(sprintf('AI-Host erreichbar: %s (%s)', $aiBaseUrl, $reach['reason']));
        $emit('');
        return true;
    }

    /**
     * Preflight check for the configured AI base URL. Sends an authenticated
     * GET /models with a short connect+read timeout, so a working setup
     * answers HTTP 200 (confirming host *and* auth). Any HTTP response still
     * counts as reachable — only network failures and 5xx abort. The probe is
     * authenticated because some proxies (e.g. cliproxyapi) return 401/404 on
     * an unauthenticated /models, which looks alarming in the log even though
     * the host is up.
     *
     * @return array{ok: bool, reason: string}
     */
    private function checkAiHostReachable(string $baseUrl, string $apiKey = ''): array
    {
        $probeUrl = rtrim(string: $baseUrl, characters: '/') . '/models';
        $options = [
            CURLOPT_URL => $probeUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
        ];
        if ($apiKey !== '') {
            $options[CURLOPT_HTTPHEADER] = ['Authorization: Bearer ' . $apiKey];
        }
        $ch = curl_init();
        curl_setopt_array(handle: $ch, options: $options);
        curl_exec(handle: $ch);
        $code = (int) curl_getinfo(handle: $ch, option: CURLINFO_HTTP_CODE);
        $err = (string) curl_error(handle: $ch);
        if ($code === 0) {
            return ['ok' => false, 'reason' => $err !== '' ? $err : 'kein HTTP-Response'];
        }
        if ($code >= 500) {
            return ['ok' => false, 'reason' => 'HTTP ' . $code];
        }
        return ['ok' => true, 'reason' => 'HTTP ' . $code];
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

        if (!$this->preflightAi(emit: $emit)) {
            return;
        }

        $db = $this->openDatabase();
        $env = $this->loadEnv();
        $aiConfig = [
            'provider' => (string) ($env['AI_PROVIDER'] ?? ''),
            'model' => (string) ($env['AI_MODEL'] ?? ''),
            'url' => (string) ($env['AI_BASE_URL'] ?? '')
        ];
        $apiKey = (string) ($env['AI_API_KEY'] ?? '');

        if ($phase === 8) {
            $emit('Phase 8/10: Duplikat-Erkennung (Jaccard + LLM-Verifikation)');
            $this->clusterDuplicates(db: $db, aiConfig: $aiConfig, apiKey: $apiKey, emit: $emit);
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

        // Preflight: AI host must be configured and reachable. Phases 6, 8, 9
        // and 10 all rely on it; running without AI would pollute the title
        // cache with NULLs and mark candidates dedup_checked without checking.
        // Bail before any DB or feed work happens.
        if (!$this->preflightAi(emit: $emit)) {
            return;
        }

        $db = $this->openDatabase();

        // A manual scrape always re-fetches every feed. Drop cached feed bodies.
        // Article-level caches stay intact (tied to article URLs, not feed state).
        $this->cacheClear(prefix: 'feed:');

        // Phase 1: feeds.
        $emit('Phase 1/10: RSS-Feeds einlesen');
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

        // Talk-show papers (ardmediathek:// / zdfmediathek://) are
        // monothematic — every episode is "TV" by definition. Seed the
        // title-keyed category cache up front so Phase 6 skips the AI call
        // for these items and Phase 7 writes them with category=TV.
        $papers = $this->papers();
        $tvSeeded = 0;
        foreach ($allItems as $entry) {
            $rss = (string) ($papers[$entry['paper']]['rss'] ?? '');
            if (!str_starts_with(haystack: $rss, needle: 'ardmediathek://') && !str_starts_with(haystack: $rss, needle: 'zdfmediathek://')) {
                continue;
            }
            $title = $entry['item']->title;
            if (!$this->categoryCacheExists(title: $title)) {
                $this->writeCategoryCache(title: $title, category: 'TV');
                $tvSeeded++;
            }
        }
        if ($tvSeeded > 0) {
            $emit(sprintf('  → %d Talkshow-Items als Kategorie "TV" vorgemerkt', $tvSeeded));
        }

        // Media papers (Rotten Tomatoes, Metacritic, laut.de) are equally
        // monothematic — their config carries a fixed "category", so Phase 6
        // skips the AI call and Phase 7 writes them with that category.
        $mediaSeeded = 0;
        foreach ($allItems as $entry) {
            $fixedCategory = (string) ($papers[$entry['paper']]['category'] ?? '');
            if ($fixedCategory === '') {
                continue;
            }
            $title = $entry['item']->title;
            if (!$this->categoryCacheExists(title: $title)) {
                $this->writeCategoryCache(title: $title, category: $fixedCategory);
                $mediaSeeded++;
            }
        }
        if ($mediaSeeded > 0) {
            $emit(sprintf('  → %d Medien-Items mit fixer Kategorie vorgemerkt', $mediaSeeded));
        }
        $emit('');

        if (empty($allItems)) {
            $emit('Keine Artikel im Feed — Abbruch.');
            return;
        }

        $urls = array_map(callback: fn(array $entry): string => $entry['item']->link, array: $allItems);

        // Phase 2: paywall.
        $emit('Phase 2/10: Paywall-Status prüfen (HTML-Probe der Originalseiten)');
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
        $emit('Phase 3/10: Archive-Verfügbarkeit prüfen (nur PLUS, parallel)');
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
        $emit('Phase 4/10: Volltext-Check der archivierten PLUS-Artikel');
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
        $emit('Phase 5/10: Thumbnails herunterladen + skalieren');

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
        // via Jaccard + LLM and keeps both rows.
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

        // Media backfill: media rows compete in the rating-ranked tabs where
        // a missing cover is glaring, so they must reach 100% coverage. Rows
        // still present in the feeds self-heal via the Phase-7 upsert (the
        // grids deliver covers); this pass catches rows that already rotated
        // out: og:image from the cached Phase-2 probe or a live probe of the
        // detail page (works for Rotten Tomatoes and laut.de — Metacritic
        // ships no og tags at all), source favicon as last resort.
        $mediaPaperKeys = $this->fixedCategoryPapers();
        if ($mediaPaperKeys !== []) {
            $list = implode(separator: ',', array: array_map(callback: fn(string $p): string => "'" . str_replace(search: "'", replace: "''", subject: $p) . "'", array: $mediaPaperKeys));
            $mediaRows = (array) $db->query(query: "
                SELECT url, paper, title, image_url
                FROM articles
                WHERE paper IN ({$list})
                  AND thumbnail IS NULL
                  AND COALESCE(thumbnail_fail_count, 0) < 3
            ")->fetchAll(mode: PDO::FETCH_ASSOC);
            $inCurrentFeeds = array_flip(array: $urls);
            $mediaImages = [];
            $mediaCandidates = [];
            $mediaProbed = 0;
            foreach ($mediaRows as $row) {
                $url = (string) $row['url'];
                // Fresh-feed URLs are covered by the regular Phase-5 path
                // plus the Phase-7 upsert in this very scrape.
                if (isset($inCurrentFeeds[$url])) {
                    continue;
                }
                $img = (string) ($row['image_url'] ?? '');
                if ($img === '') {
                    $img = (string) $this->readOgImageCache(url: $url);
                }
                if ($img === '' && $mediaProbed < 40) {
                    $mediaProbed++;
                    $page = $this->fetchViaImpersonate(url: $url);
                    if ($page->body !== null && preg_match(pattern: '~<meta property="og:image" content="([^"]+)"~', subject: $page->body, matches: $ogMatch) === 1) {
                        $img = html_entity_decode(string: $ogMatch[1], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');
                        $this->writeOgImageCache(url: $url, imageUrl: $img);
                    }
                }
                if ($img === '' || preg_match(pattern: '~^https?://~i', subject: $img) !== 1) {
                    $img = (string) ($papersConfig[$row['paper']]['default_image'] ?? '');
                }
                if ($img === '') {
                    continue;
                }
                $mediaImages[$url] = $img;
                $mediaCandidates[] = [
                    'paper' => (string) $row['paper'],
                    'item' => new FeedItem(
                        title: (string) $row['title'],
                        link: $url,
                        publishedAt: null,
                        imageUrl: $img
                    )
                ];
            }
            if ($mediaCandidates !== []) {
                $emit(sprintf('  Medien-Backfill: %d Einträge ohne Thumbnail (%d og-Proben)', count(value: $mediaCandidates), $mediaProbed));
                $mediaStart = microtime(as_float: true);
                $mediaThumbs = $this->downloadThumbnailsStreaming(items: $mediaCandidates, imageUrls: $mediaImages, emit: $emit);
                $mediaUpdate = $db->prepare(query: 'UPDATE articles SET thumbnail = :thumb, image_url = COALESCE(image_url, :img), thumbnail_fail_count = 0 WHERE url = :url');
                $mediaFailBump = $db->prepare(query: 'UPDATE articles SET thumbnail_fail_count = COALESCE(thumbnail_fail_count, 0) + 1 WHERE url = :url');
                $mediaWritten = 0;
                $mediaFailed = 0;
                foreach ($mediaImages as $url => $img) {
                    $thumb = $mediaThumbs[$url] ?? null;
                    if ($thumb !== null) {
                        $mediaUpdate->execute(params: [':thumb' => $thumb, ':img' => $img, ':url' => (string) $url]);
                        $mediaWritten++;
                    } else {
                        $mediaFailBump->execute(params: [':url' => (string) $url]);
                        $mediaFailed++;
                    }
                }
                $mediaMs = (int) round(num: (microtime(as_float: true) - $mediaStart) * 1000);
                $emit(sprintf('  → Medien-Backfill: %d geschrieben, %d fehlgeschlagen (%d ms)', $mediaWritten, $mediaFailed, $mediaMs));
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
        $emit('Phase 6/10: AI-Kategorisierung');
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
        // via Jaccard + LLM and keeps both rows.
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
                aiConfig: ['provider' => $aiProvider, 'model' => $aiModel, 'url' => (string) ($env['AI_BASE_URL'] ?? '')],
                apiKey: $apiKey,
                emit: $emit
            );
            $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
            $emit(sprintf('  → %d kategorisiert (%d ms)', count(value: array_filter($freshCategories)), $ms));
        }
        $emit('');

        // Phase 7: upsert.
        $emit('Phase 7/10: In Datenbank schreiben');
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
                // Defence in depth: a sporadic Phase-7 bug (seen for ARD
                // talk-show items) drops FeedItem->imageUrl somewhere between
                // Phase 1 and here even though the parser returned a value.
                // Fall back to the same image we already prepared for the
                // thumbnail download so the column always lands non-null.
                ':image_url' => $item->imageUrl ?? ($effectiveImages[$item->link] ?? null),
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
        $emit('Phase 8/10: Duplikat-Erkennung (Jaccard + LLM-Verifikation)');
        $phaseStart = microtime(as_float: true);
        $envDup = $this->loadEnv();
        $aiProviderDup = (string) ($envDup['AI_PROVIDER'] ?? '');
        $aiModelDup = (string) ($envDup['AI_MODEL'] ?? '');
        $apiKeyDup = (string) ($envDup['AI_API_KEY'] ?? '');
        $aiConfigDup = ['provider' => $aiProviderDup, 'model' => $aiModelDup, 'url' => (string) ($envDup['AI_BASE_URL'] ?? '')];
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
        $emit('Phase 9/10: Magic-Bucket berechnen (Affinität + AI-Rerank)');
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
            // Cap candidates to 30 before LLM rerank: anything beyond that is
            // long-tail noise from low-affinity sources and inflates token cost.
            $candidates = array_slice(array: $candidates, offset: 0, length: 30);
            $emit(sprintf('  → %d ungelesene gescort, %d Kandidaten (Top 1/Quelle, max. 30)', count(value: $unread), count(value: $candidates)));

            $env = $this->loadEnv();
            $aiProvider = (string) ($env['AI_PROVIDER'] ?? '');
            $aiModel = (string) ($env['AI_MODEL'] ?? '');
            $apiKey = (string) ($env['AI_API_KEY'] ?? '');
            $aiConfig = ['provider' => $aiProvider, 'model' => $aiModel, 'url' => (string) ($env['AI_BASE_URL'] ?? '')];

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

            // Final cap: only the top 10 land in the frozen bucket.
            $final = array_slice(array: $final, offset: 0, length: 10);

            $rankStmt = $db->prepare(query: 'UPDATE articles SET magic_rank = :rank WHERE url = :url');
            foreach ($final as $i => $row) {
                $rankStmt->execute(params: [':rank' => $i + 1, ':url' => (string) $row['url']]);
            }
            $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
            $emit(sprintf('  → %d Artikel im Bucket (%d ms)', count(value: $final), $ms));
        }
        $emit('');

        // Phase 10: tagesübersicht. LLM fasst alle heutigen Schlagzeilen
        // (egal ob gelesen oder im Magic-Bucket) zu einer plakativen
        // Fließtext-Tagesschau zusammen, die der Dashboard-Render oberhalb
        // der Filter einblendet.
        $emit('Phase 10/10: Wochenübersicht generieren');
        $phaseStart = microtime(as_float: true);
        $env = $this->loadEnv();
        $this->generateDailyDigest(
            db: $db,
            aiConfig: [
                'provider' => (string) ($env['AI_PROVIDER'] ?? ''),
                'model' => (string) ($env['AI_MODEL'] ?? ''),
                'url' => (string) ($env['AI_BASE_URL'] ?? ''),
            ],
            apiKey: (string) ($env['AI_API_KEY'] ?? ''),
            emit: $emit
        );
        $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
        $emit(sprintf('  → Phase 10 fertig (%d ms)', $ms));
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

        // Media rows without a rating predate the score filter (the feeds
        // only deliver scored items now) — they would pollute the
        // rating-ranked media tabs forever.
        $mediaPapers = $this->fixedCategoryPapers();
        if ($mediaPapers !== []) {
            $list = implode(separator: ',', array: array_map(callback: fn(string $p): string => "'" . str_replace(search: "'", replace: "''", subject: $p) . "'", array: $mediaPapers));
            $mediaDeleted = $db->exec(statement: "DELETE FROM articles WHERE paper IN ({$list}) AND rating IS NULL");
            if ((int) $mediaDeleted > 0) {
                $emit(sprintf('  → %d Medien-Artikel ohne Wertung gelöscht', (int) $mediaDeleted));
            }
        }

        // Talk-show episode bodies (zdf-episode:<md5>) accumulate one entry
        // per crawled episode and would grow unbounded otherwise. Drop the
        // old ones; the next scrape re-fetches whatever's still listed in
        // the show overview, episodes that aged out simply stay dropped.
        $tvCacheCutoff = time() - 30 * 86400;
        $tvBytes = (int) $db->query(query: "SELECT COALESCE(SUM(LENGTH(value)),0) FROM cache WHERE key LIKE 'zdf-episode:%' AND updated_at < {$tvCacheCutoff}")->fetchColumn();
        $tvDeleted = $db->exec(statement: "DELETE FROM cache WHERE key LIKE 'zdf-episode:%' AND updated_at < {$tvCacheCutoff}");
        if ((int) $tvDeleted > 0) {
            $emit(sprintf('  → %d Talkshow-Episoden-Cache-Einträge gelöscht (%d MB frei)', (int) $tvDeleted, intdiv($tvBytes, 1048576)));
        }

        // Drop display-only payload from old, untouched articles. Keeps the
        // ML signals (category, paywall, rating, duplicate_of) and personal
        // signals (vote, read_at) so future categorisation, dedup and
        // affinity scoring stay accurate. Articles the user interacted with
        // (read or voted) are preserved in full.
        $articleCutoff = time() - 30 * 86400;
        $bytesBefore = (int) $db->query(query: "
            SELECT COALESCE(SUM(LENGTH(thumbnail)) + SUM(LENGTH(image_url)), 0)
            FROM articles
            WHERE published_at < {$articleCutoff}
              AND read_at IS NULL AND vote = 0
              AND (thumbnail IS NOT NULL OR image_url IS NOT NULL)
        ")->fetchColumn();
        $prunedRows = $db->exec(statement: "
            UPDATE articles
            SET thumbnail = NULL, image_url = NULL
            WHERE published_at < {$articleCutoff}
              AND read_at IS NULL AND vote = 0
              AND (thumbnail IS NOT NULL OR image_url IS NOT NULL)
        ");
        $emit(sprintf('  → %d alte Artikel ausgedünnt (%d MB frei, Kategorien bleiben)', (int) $prunedRows, intdiv($bytesBefore, 1048576)));

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
        // Trailing newline so bash `while read` in buildParallelPipeline()
        // does not drop the last entry (read returns non-zero at EOF).
        file_put_contents(filename: $tmpIn, data: implode(separator: "\n", array: $lines) . "\n");

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
        // Trailing newline so bash `while read` in buildParallelPipeline()
        // does not drop the last entry (read returns non-zero at EOF).
        file_put_contents(filename: $tmpIn, data: implode(separator: "\n", array: $lines) . "\n");

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
        $aiUrl = (string) ($aiConfig['url'] ?? '');
        $ai = $aiClass::create(
            provider: (string) ($aiConfig['provider'] ?? 'anthropic'),
            model: (string) ($aiConfig['model'] ?? 'claude-haiku-4-5-20251001'),
            temperature: (float) ($aiConfig['temperature'] ?? 0.0),
            api_key: $apiKey,
            max_tries: (int) ($aiConfig['max_tries'] ?? 2),
            timeout: (int) ($aiConfig['timeout'] ?? 120),
            url: $aiUrl !== '' ? $aiUrl : null
        );

        $categoryList = implode(separator: ', ', array: $categories);
        $batchSize = 30;

        // Pass 1: split into cache hits vs. distinct pending titles. Identical
        // titles across papers share the title-based cache key, so we ask the
        // LLM only once per distinct title.
        $cacheHits = [];
        $pendingTitles = [];
        foreach ($items as $entry) {
            $title = $entry['item']->title;
            if (array_key_exists(key: $title, array: $cacheHits) || isset($pendingTitles[$title])) {
                continue;
            }
            if ($this->categoryCacheExists(title: $title)) {
                $cacheHits[$title] = $this->readCategoryCache(title: $title);
            } else {
                $pendingTitles[$title] = true;
            }
        }

        // Pass 2: batch the pending titles into one LLM call per chunk.
        $aiResults = [];
        $pendingList = array_keys(array: $pendingTitles);
        $batchCount = $pendingList === [] ? 0 : (int) ceil(num: count(value: $pendingList) / $batchSize);
        foreach (array_chunk(array: $pendingList, length: $batchSize) as $batchIdx => $titles) {
            $batchStart = microtime(as_float: true);

            $numbered = [];
            foreach ($titles as $i => $t) {
                $numbered[] = ($i + 1) . '. ' . $t;
            }
            $prompt =
                "Ordne JEDEM der folgenden nummerierten Nachrichtenartikel-Titel GENAU EINE der genannten Kategorien zu.\n\n" .
                "Verfügbare Kategorien: " . $categoryList . "\n\n" .
                "Antworte AUSSCHLIESSLICH mit einem JSON-Objekt im Format {\"1\":\"Kategorie\",\"2\":\"Kategorie\",...}.\n" .
                "Verwende für jeden Eintrag exakt einen Kategorienamen aus der Liste, kein Markdown, keine Erklärungen.\n\n" .
                "Titel:\n" . implode(separator: "\n", array: $numbered);

            try {
                $resp = $ai->ask(prompt: $prompt)['response'] ?? null;
            } catch (\Throwable $e) {
                $resp = null;
            }

            if (is_object(value: $resp) || is_array(value: $resp)) {
                $parsed = json_decode(json: (string) json_encode(value: $resp), associative: true);
            } else {
                $raw = trim(string: (string) $resp);
                $raw = (string) preg_replace(pattern: '~^\s*```(?:json)?\s*|\s*```\s*$~i', replacement: '', subject: $raw);
                $parsed = json_decode(json: $raw, associative: true);
            }
            if (!is_array(value: $parsed)) {
                $parsed = [];
            }

            $mapping = [];
            foreach ($parsed as $key => $value) {
                $idx = (int) $key;
                if ($idx < 1 || $idx > count(value: $titles)) {
                    continue;
                }
                $cat = $this->matchCategory(raw: (string) $value, categories: $categories);
                if ($cat !== null) {
                    $mapping[$idx] = $cat;
                }
            }

            foreach ($titles as $i => $t) {
                $cat = $mapping[$i + 1] ?? null;
                $this->writeCategoryCache(title: $t, category: $cat);
                $aiResults[$t] = $cat;
            }

            $ms = (int) round(num: (microtime(as_float: true) - $batchStart) * 1000);
            $hits = count(value: $mapping);
            $emit(sprintf(
                '  Batch %d/%d: %d/%d Titel kategorisiert (%d ms)',
                $batchIdx + 1,
                $batchCount,
                $hits,
                count(value: $titles),
                $ms
            ));
        }

        // Pass 3: emit per item in original order, build url => category result.
        $result = [];
        $total = count(value: $items);
        $checked = 0;
        $fromCache = 0;
        $fromAi = 0;
        foreach ($items as $entry) {
            $item = $entry['item'];
            $checked++;
            $title = $item->title;

            if (array_key_exists(key: $title, array: $cacheHits)) {
                $category = $cacheHits[$title];
                $fromCache++;
                $label = ($category ?? '?unkategorisiert') . ' (cache)';
            } else {
                $category = $aiResults[$title] ?? null;
                $fromAi++;
                $label = $category ?? '?unkategorisiert';
            }

            if ($category !== null) {
                $result[$item->link] = $category;
            }

            $titleShort = mb_substr(string: $title, start: 0, length: 50);
            $emit(sprintf(
                '  [%3d/%d] %-14s %-22s %s',
                $checked,
                $total,
                $entry['paper'],
                $label,
                $titleShort
            ));
        }

        $emit(sprintf('  → AI-Calls: %d in %d Batches, Cache-Hits: %d', $fromAi, $batchCount, $fromCache));
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
        // Trailing newline so bash `while read` in buildParallelPipeline()
        // does not drop the last entry (read returns non-zero at EOF).
        file_put_contents(filename: $tmpIn, data: implode(separator: "\n", array: $urls) . "\n");

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
     * Duplicate detection via local title-token Jaccard + LLM verification.
     * Stage 1: tokenise titles, prefilter against existing canonicals in the
     * 7-day window, keep top-N suspect pairs per candidate at Jaccard ≥ 0.25.
     * Stage 2: ship all suspects in one batched LLM call, model returns the
     * subset of pairs that describe the same story. Confirmed candidates get
     * duplicate_of set to their canonical; unmatched candidates become new
     * canonicals matchable for subsequent runs.
     *
     * @param array<string, mixed> $aiConfig
     */
    private function clusterDuplicates(PDO $db, array $aiConfig, string $apiKey, callable $emit): void
    {
        // Stage 1: local token-Jaccard prefilter on normalized titles,
        // top-N suspect pairs per candidate. Stage 2:
        // single batched LLM verification call confirms which suspect pairs
        // really describe the same story. URL dedup via DB primary key
        // remains the first line of defense before this even runs.
        $cutoff = time() - 7 * 86400;
        $jaccardThreshold = 0.25;
        $topNPerCandidate = 3;

        // Talk-show episodes share titles between full sendung and topic
        // segments by design ("maischberger am 10.06.2026" + "Joschka Fischer
        // über…") — Jaccard would happily merge them, destroying the per-
        // guest segments. Exclude all talk-show papers from dedup. Media
        // papers (fixed-category sources) are excluded too: their titles are
        // product names, and a release must never be swallowed as duplicate
        // of a news article reporting about it.
        $excludedPapers = array_merge($this->talkshowPapers(), $this->fixedCategoryPapers());
        $excludeClause = '';
        if ($excludedPapers !== []) {
            $list = implode(separator: ',', array: array_map(callback: fn(string $p): string => "'" . str_replace(search: "'", replace: "''", subject: $p) . "'", array: $excludedPapers));
            $excludeClause = ' AND paper NOT IN (' . $list . ')';
        }
        $stmt = $db->query(query: "
            SELECT url, paper, title, published_at, duplicate_of, dedup_checked_at
            FROM articles
            WHERE published_at IS NOT NULL
              AND published_at > {$cutoff}
              AND title IS NOT NULL
              AND title != ''
              {$excludeClause}
            ORDER BY published_at ASC
        ");
        $pool = [];
        $candidates = [];
        while ($r = $stmt->fetch(mode: PDO::FETCH_ASSOC)) {
            $row = [
                'url' => (string) $r['url'],
                'paper' => (string) $r['paper'],
                'title' => (string) $r['title'],
                'tokens' => $this->normalizeTitleTokens(title: (string) $r['title'])
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
        $emit(sprintf('  %d neue Kandidaten vs. %d bestehende Canonicals', count(value: $candidates), count(value: $pool)));

        $suspectPairs = [];
        foreach ($candidates as $candIdx => $cand) {
            if ($cand['tokens'] === []) {
                continue;
            }
            $scored = [];
            foreach ($pool as $poolIdx => $p) {
                if ($p['tokens'] === []) {
                    continue;
                }
                $sim = $this->jaccardSimilarity(a: $cand['tokens'], b: $p['tokens']);
                if ($sim >= $jaccardThreshold) {
                    $scored[] = ['poolIdx' => $poolIdx, 'sim' => $sim];
                }
            }
            if ($scored === []) {
                continue;
            }
            usort(array: $scored, callback: fn(array $a, array $b): int => $b['sim'] <=> $a['sim']);
            foreach (array_slice(array: $scored, offset: 0, length: $topNPerCandidate) as $s) {
                $suspectPairs[] = [
                    'candIdx' => $candIdx,
                    'poolIdx' => $s['poolIdx'],
                    'sim' => $s['sim']
                ];
            }
        }

        $socialPapers = ['hackernews' => 1, 'reddit' => 1, 'x' => 1];
        $update = $db->prepare(query: 'UPDATE articles SET duplicate_of = :dup WHERE url = :url');
        $checkStmt = $db->prepare(query: 'UPDATE articles SET dedup_checked_at = :ts WHERE url = :url');
        $now = time();

        if ($suspectPairs === []) {
            $emit('  → keine verdächtigen Paare nach Jaccard-Vorfilter');
            foreach ($candidates as $cand) {
                $checkStmt->execute(params: [':ts' => $now, ':url' => $cand['url']]);
            }
            return;
        }
        $emit(sprintf('  → %d verdächtige Paare → LLM-Verifikation', count(value: $suspectPairs)));

        $confirmedIdx = $this->llmVerifyDuplicatePairs(
            pairs: $suspectPairs,
            candidates: $candidates,
            pool: $pool,
            aiConfig: $aiConfig,
            apiKey: $apiKey,
            emit: $emit
        );

        // First confirmed pool match wins per candidate — duplicate links
        // are 1:N (one canonical owns many duplicates), so a candidate that
        // matches multiple pool entries is collapsed into the first.
        $candToPool = [];
        foreach ($confirmedIdx as $pairIdx) {
            $p = $suspectPairs[$pairIdx] ?? null;
            if ($p === null) {
                continue;
            }
            if (!isset($candToPool[$p['candIdx']])) {
                $candToPool[$p['candIdx']] = $p['poolIdx'];
            }
        }

        $dupsWritten = 0;
        foreach ($candidates as $candIdx => $cand) {
            if (isset($candToPool[$candIdx])) {
                $poolIdx = $candToPool[$candIdx];
                $canonical = $pool[$poolIdx];
                $candSocial = isset($socialPapers[$cand['paper']]) ? 1 : 0;
                $canonSocial = isset($socialPapers[$canonical['paper']]) ? 1 : 0;
                if ($candSocial === 0 && $canonSocial === 1) {
                    $update->execute(params: [':dup' => $cand['url'], ':url' => $canonical['url']]);
                    $pool[$poolIdx] = $cand;
                } else {
                    $update->execute(params: [':dup' => $canonical['url'], ':url' => $cand['url']]);
                }
                $dupsWritten++;
            } else {
                $pool[] = $cand;
            }
            $checkStmt->execute(params: [':ts' => $now, ':url' => $cand['url']]);
        }
        $emit(sprintf('  → %d Duplikate per LLM bestätigt (Jaccard≥%.2f, top%d)', $dupsWritten, $jaccardThreshold, $topNPerCandidate));
    }

    /**
     * Tokenise a title for Jaccard prefiltering: lowercase, strip non-letters,
     * drop short tokens and a small German/English stopword list. Returns a
     * deduplicated word set as keys (for O(1) intersection later).
     *
     * @return array<string, true>
     */
    private function normalizeTitleTokens(string $title): array
    {
        static $stopwords = [
            'der' => 1, 'die' => 1, 'das' => 1, 'den' => 1, 'dem' => 1, 'des' => 1,
            'ein' => 1, 'eine' => 1, 'einer' => 1, 'eines' => 1, 'einen' => 1, 'einem' => 1,
            'und' => 1, 'oder' => 1, 'aber' => 1, 'doch' => 1, 'nicht' => 1,
            'in' => 1, 'im' => 1, 'auf' => 1, 'an' => 1, 'am' => 1, 'mit' => 1, 'von' => 1,
            'vom' => 1, 'zu' => 1, 'zur' => 1, 'zum' => 1, 'für' => 1, 'bei' => 1, 'nach' => 1,
            'aus' => 1, 'um' => 1, 'als' => 1, 'wie' => 1, 'so' => 1, 'auch' => 1,
            'ist' => 1, 'sind' => 1, 'war' => 1, 'waren' => 1, 'wird' => 1, 'werden' => 1,
            'hat' => 1, 'haben' => 1, 'hatte' => 1, 'hatten' => 1, 'kann' => 1, 'soll' => 1,
            'sich' => 1, 'sein' => 1, 'seine' => 1, 'ihre' => 1, 'ihr' => 1,
            'mehr' => 1, 'sehr' => 1, 'noch' => 1, 'schon' => 1, 'nur' => 1,
            'the' => 1, 'and' => 1, 'or' => 1, 'but' => 1, 'not' => 1,
            'of' => 1, 'on' => 1, 'at' => 1, 'to' => 1, 'for' => 1, 'with' => 1,
            'by' => 1, 'from' => 1, 'as' => 1, 'is' => 1, 'are' => 1, 'was' => 1, 'were' => 1,
            'be' => 1, 'been' => 1, 'has' => 1, 'have' => 1, 'had' => 1, 'will' => 1, 'would' => 1,
            'this' => 1, 'that' => 1, 'these' => 1, 'those' => 1, 'it' => 1, 'its' => 1,
            'how' => 1, 'why' => 1, 'what' => 1, 'when' => 1, 'where' => 1
        ];
        $lower = mb_strtolower(string: $title, encoding: 'UTF-8');
        $cleaned = (string) preg_replace(pattern: '~[^\p{L}\p{N}\s]+~u', replacement: ' ', subject: $lower);
        $parts = preg_split(pattern: '~\s+~', subject: $cleaned, limit: -1, flags: PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }
        // Prefix stemming (first 5 chars) collapses German inflection
        // ("kritisiert" / "kritik" → "kriti", "scharf" / "scharfe" → "scharf"),
        // turning the Jaccard score into a usable signal across reword variants.
        $tokens = [];
        foreach ($parts as $word) {
            if (mb_strlen(string: $word, encoding: 'UTF-8') < 3) {
                continue;
            }
            if (isset($stopwords[$word])) {
                continue;
            }
            $stem = mb_substr(string: $word, start: 0, length: 5, encoding: 'UTF-8');
            $tokens[$stem] = true;
        }
        return $tokens;
    }

    /**
     * @param array<string, true> $a
     * @param array<string, true> $b
     */
    private function jaccardSimilarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersect = count(value: array_intersect_key($a, $b));
        if ($intersect === 0) {
            return 0.0;
        }
        $union = count(value: $a) + count(value: $b) - $intersect;
        return $intersect / $union;
    }

    /**
     * Send all Jaccard-prefiltered suspect pairs to the LLM in one batched
     * call. Expects a JSON array of 1-based pair indices to be returned.
     * Returns a list of confirmed pair indices into $pairs (0-based).
     *
     * @param array<int, array{candIdx:int, poolIdx:int, sim:float}> $pairs
     * @param array<int, array{url:string, paper:string, title:string, tokens:array<string,true>}> $candidates
     * @param array<int, array{url:string, paper:string, title:string, tokens:array<string,true>}> $pool
     * @param array<string, mixed> $aiConfig
     * @return array<int, int>
     */
    private function llmVerifyDuplicatePairs(array $pairs, array $candidates, array $pool, array $aiConfig, string $apiKey, callable $emit): array
    {
        if (!class_exists(class: 'vielhuber\\aihelper\\aihelper')) {
            $emit('  ⚠️  aihelper nicht verfügbar — Dedup-LLM übersprungen');
            return [];
        }

        $lines = [];
        foreach ($pairs as $i => $p) {
            $candTitle = $candidates[$p['candIdx']]['title'] ?? '';
            $poolTitle = $pool[$p['poolIdx']]['title'] ?? '';
            $lines[] = sprintf(
                '%d) A: %s | B: %s',
                $i + 1,
                mb_substr(string: $candTitle, start: 0, length: 240),
                mb_substr(string: $poolTitle, start: 0, length: 240)
            );
        }

        $prompt = "Du erhältst eine nummerierte Liste von Schlagzeilen-Paaren (A vs. B).\n"
            . "Entscheide für jedes Paar, ob beide Schlagzeilen DIESELBE Nachrichtenstory beschreiben "
            . "(gleiches Ereignis, gleicher Sachverhalt, evtl. nur unterschiedlich formuliert).\n"
            . "Synonyme, Umformulierungen, andere Reihenfolge der Wörter → ja.\n"
            . "Bloß ähnliches Thema, anderer Vorfall oder andere Personen → nein.\n\n"
            . "Antworte AUSSCHLIESSLICH mit einem JSON-Objekt im Format {\"duplicates\":[1,3,7]} — "
            . "Liste der Paar-Nummern (1-basiert), die Duplikate sind. Wenn keines: {\"duplicates\":[]}. "
            . "Keine Erklärungen, kein Markdown.\n\n"
            . "Paare:\n" . implode(separator: "\n", array: $lines);

        try {
            $aiClass = 'vielhuber\\aihelper\\aihelper';
            $aiUrl = (string) ($aiConfig['url'] ?? '');
            $ai = $aiClass::create(
                provider: (string) ($aiConfig['provider'] ?? ''),
                model: (string) ($aiConfig['model'] ?? ''),
                temperature: 0.0,
                api_key: $apiKey,
                max_tries: (int) ($aiConfig['max_tries'] ?? 2),
                timeout: (int) ($aiConfig['timeout'] ?? 120),
                url: $aiUrl !== '' ? $aiUrl : null
            );
            $resp = $ai->ask(prompt: $prompt)['response'] ?? null;
        } catch (\Throwable $e) {
            $emit('  ⚠️  Dedup-LLM fehlgeschlagen: ' . $e->getMessage());
            return [];
        }

        if (is_object(value: $resp) || is_array(value: $resp)) {
            $parsed = json_decode(json: (string) json_encode(value: $resp), associative: true);
        } else {
            $raw = trim(string: (string) $resp);
            $raw = (string) preg_replace(pattern: '~^\s*```(?:json)?\s*|\s*```\s*$~i', replacement: '', subject: $raw);
            $parsed = json_decode(json: $raw, associative: true);
        }
        if (!is_array(value: $parsed) || !isset($parsed['duplicates']) || !is_array(value: $parsed['duplicates'])) {
            $emit('  ⚠️  Dedup-LLM Antwort kein gültiges JSON — keine Duplikate übernommen');
            return [];
        }
        $confirmed = [];
        foreach ($parsed['duplicates'] as $n) {
            $idx = (int) $n - 1;
            if ($idx >= 0 && isset($pairs[$idx])) {
                $confirmed[] = $idx;
            }
        }
        return $confirmed;
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
            $aiUrl = (string) ($aiConfig['url'] ?? '');
            $ai = $aiClass::create(
                provider: (string) ($aiConfig['provider'] ?? ''),
                model: (string) ($aiConfig['model'] ?? ''),
                temperature: (float) ($aiConfig['temperature'] ?? 0.0),
                api_key: $apiKey,
                max_tries: (int) ($aiConfig['max_tries'] ?? 2),
                timeout: (int) ($aiConfig['timeout'] ?? 60),
                url: $aiUrl !== '' ? $aiUrl : null
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
     * Build the daily digest: take every article published since today's
     * midnight (read or unread, magic bucket or not), feed the headline
     * list to the LLM, persist the JSON result under cache key
     * `daily_digest`. The renderer only surfaces a digest whose `date`
     * matches today, so an old digest from a stale scrape silently drops
     * out without an explicit cleanup pass.
     */
    private function generateDailyDigest(PDO $db, array $aiConfig, string $apiKey, callable $emit): void
    {
        if (!class_exists(class: 'vielhuber\\aihelper\\aihelper')) {
            $emit('  → kein AI-Helper verfügbar, überspringe');
            return;
        }
        $provider = (string) ($aiConfig['provider'] ?? '');
        $model = (string) ($aiConfig['model'] ?? '');
        if ($provider === '' || $model === '' || $apiKey === '') {
            $emit('  → kein AI-Provider konfiguriert, überspringe');
            return;
        }

        $now = time();
        $cutoff = $now - 7 * 86400;
        // Pull the full 7-day, non-duplicate pool newest-first, then
        // downsample (below). Feeding the raw pool (~18k headlines / ~470k
        // tokens) turned the Wochenübersicht into a flat trailing-7-day
        // average: it barely moved day-to-day and froze long-running stories
        // on their first-week framing (e.g. "WM 1. Woche" deep into week two).
        $stmt = $db->prepare(query: '
            SELECT url, paper, title, category, published_at
            FROM articles
            WHERE published_at >= :since
              AND duplicate_of IS NULL
              AND title IS NOT NULL AND title <> ""
            ORDER BY published_at DESC
        ');
        $stmt->execute(params: [':since' => $cutoff]);
        $pool = (array) $stmt->fetchAll(mode: PDO::FETCH_ASSOC);
        if ($pool === []) {
            $emit('  → keine Artikel in den letzten 7 Tagen, überspringe');
            return;
        }

        // Recency-weighted downsample: cap how many headlines each
        // (day, category) bucket may contribute, with today/yesterday getting
        // a far higher quota than older days. Keeps the prompt small (~1k
        // lines) and lets fresh items dominate, so the digest reflects the
        // CURRENT state of the week instead of averaging the whole window.
        $todayMidnight = strtotime(datetime: 'today');
        $perBucket = [];
        $articles = [];
        $todayCount = 0;
        $maxTotal = 1500;
        // Discovery feeds (GitHub-Trending repos, ProductHunt launches) cluster
        // in a single category on a single day — the per-(day,category) cap
        // would starve them (e.g. 17 ProductHunt items all "AI" → only 2 pass).
        // Give them a dedicated per-paper quota that bypasses the category cap
        // so enough reach the LLM for the trending paragraph.
        $discoveryQuota = ['github' => 12, 'producthunt' => 12, 'medium_ai' => 8, 'medium_programming' => 8];
        $discoveryCount = [];
        foreach ($pool as $a) {
            if (count(value: $articles) >= $maxTotal) {
                break;
            }
            $pub = (int) ($a['published_at'] ?? 0);
            $paper = (string) ($a['paper'] ?? '');
            if (isset($discoveryQuota[$paper])) {
                if (($discoveryCount[$paper] ?? 0) >= $discoveryQuota[$paper]) {
                    continue;
                }
                $discoveryCount[$paper] = ($discoveryCount[$paper] ?? 0) + 1;
                $articles[] = $a;
                if ($pub >= $todayMidnight) {
                    $todayCount++;
                }
                continue;
            }
            // Clamp future-dated items (some feeds post ahead) into today.
            $effective = $pub > $now ? $now : $pub;
            $daysAgo = (int) floor(num: max(0, $todayMidnight - strtotime(datetime: 'today', baseTimestamp: $effective)) / 86400);
            $cap = match (true) {
                $daysAgo <= 0 => 6,   // today
                $daysAgo === 1 => 3,  // yesterday
                default => 2,         // 2–6 days ago
            };
            $bucket = $daysAgo . '|' . ((string) ($a['category'] ?? '-'));
            $perBucket[$bucket] = ($perBucket[$bucket] ?? 0) + 1;
            if ($perBucket[$bucket] > $cap) {
                continue;
            }
            $articles[] = $a;
            if ($pub >= $todayMidnight) {
                $todayCount++;
            }
        }

        $lines = [];
        foreach ($articles as $i => $a) {
            $pub = (int) ($a['published_at'] ?? 0);
            $isToday = $pub >= $todayMidnight;
            $dateStr = date(format: 'd.m.', timestamp: $pub > $now ? $now : $pub);
            $lines[] = ($i + 1) . '. ' . $dateStr . ($isToday ? ' (heute)' : '')
                . ' [' . ((string) ($a['paper'] ?? '?')) . ' | ' . ((string) ($a['category'] ?? '-')) . '] '
                . mb_substr(string: (string) ($a['title'] ?? ''), start: 0, length: 180);
        }

        $prompt =
            "Heute ist der " . date(format: 'd.m.Y', timestamp: $now) . ".\n" .
            "Du bist ein Zeitungs-Chefredakteur und schreibst ZWEI Texte für einen Privatleser, " .
            "der nicht jeden Tag liest:\n" .
            "  1. EINE \"Meldung des Tages\" — die brisanteste Story von HEUTE, themenübergreifend gewählt.\n" .
            "  2. Eine Wochenübersicht mit 5 bis 7 Geschichten der letzten 7 Tage, geordnet nach Themengruppen.\n\n" .
            "Jede Zeile beginnt mit dem Erscheinungsdatum (TT.MM.); Artikel von HEUTE sind zusätzlich mit '(heute)' markiert:\n\n" .
            implode(separator: "\n", array: $lines) . "\n\n" .
            "MELDUNG DES TAGES (top_today): EIN Absatz von 1 bis 2 Sätzen zur BRISANTESTEN Story von heute, " .
            "THEMENÜBERGREIFEND. Die Story muss NICHT politisch sein — wähle aus ALLEN Themengruppen (Politik, " .
            "Wirtschaft, Wissen & Technik, Sport, Gesellschaft, …) die Story mit der größten Tragweite. " .
            "Wähle ausschliesslich aus den mit '(heute)' markierten Artikeln. Falls KEIN Artikel mit " .
            "'(heute)' markiert ist, setze \"top_today\" auf null.\n\n" .
            "WOCHENÜBERSICHT (items): 5 bis 7 Absätze zu je 1 bis 2 Sätzen.\n" .
            "Die zehn Themengruppen ergeben sich aus der Kategorie der Artikel:\n" .
            "  - Politik: Innenpolitik, Außenpolitik, Ukraine-Krieg, Nahost-Konflikt, Justiz & Verfassung\n" .
            "  - Wirtschaft & Finanzen: Konjunktur, Unternehmen, Börse & Märkte, Krypto, Arbeitsmarkt\n" .
            "  - Sport: Fußball, Motorsport, Tennis, Wintersport, Sportbusiness\n" .
            "  - Kultur & Medien: Musik, Film & TV, Kunst & Ausstellungen, Reality-TV, Kulturbetrieb\n" .
            "  - Wissen & Technik: AI, CSS, Programmierung, Open Source, Web & Internet, DevOps & Cloud, Hardware, Mobilgeräte, Wearables, GPUs & Chips, Gaming, Cybersecurity, Datenschutz, Wissenschaft, Klima & Umwelt, Tiere & Natur, Weltraum, Energie, Robotik, Militärtechnik\n" .
            "  - Gesundheit: Ernährung, Mentale Gesundheit, Krankheiten & Epidemien, Medizin & Pharma, Fitness & Longevity\n" .
            "  - Gesellschaft & Panorama: Kriminalität, Religion & Kirche, Brauchtum & Tradition, Familie & Beziehungen, Bildung\n" .
            "  - Lokal & Regional: Bayern, Berlin, Norddeutschland, NRW & Westdeutschland, Verkehr & Infrastruktur\n" .
            "  - Reise & Lifestyle: Kulinarik, Reiseziele, Wein & Getränke, Auto-Lifestyle, Haushaltstipps\n" .
            "  - Sonstiges: Auto & Verkehr, Garten & Pflanzen, Wetter & Natur, Unfälle, Verbraucher\n" .
            "MENGEN-VORGABEN je Themengruppe (Gesamtsumme MUSS zwischen 5 und 7 liegen):\n" .
            "  - Wissen & Technik: GENAU 2 Absätze (PFLICHT, wenn Stories im Set vorhanden — das ist der Lieblingsbereich des Lesers)\n" .
            "    Trending-Absatz (PFLICHT, sobald solche Einträge im Set sind): EINER der beiden Wissen-&-Technik-Absätze MUSS die Entwickler-/Produkt-Trends der Woche bündeln und dabei BEIDE Quellenarten abdecken — SOWOHL 2 bis 3 auffällige GitHub-Trending-Projekte (Quelle 'github', herausragende Open-Source-Projekte) ALS AUCH 2 bis 3 auffällige ProductHunt-Launches (Quelle 'producthunt', meistbeachtete Produkt-/Tool-Launches), jeweils kurz benannt (wofür sie stehen). Die \"sources\"-Liste dieses Absatzes MUSS sowohl github- als auch producthunt-Einträge enthalten. Sind zudem Medium-Fachartikel im Set (Quellen 'medium_ai' oder 'medium_programming'), webe zusätzlich 1 bis 2 der lesenswertesten davon in diesen Absatz ein (kurz benannt, worum es geht) und nimm sie in die \"sources\"-Liste auf — behandle sie als vollwertige Tech-Stories, nicht als Blog-Spam. Der ANDERE Technik-Absatz bleibt der echten Tech-Nachricht der Woche vorbehalten.\n" .
            "  - Politik: 1 bis 2 Absätze\n" .
            "  - Wirtschaft & Finanzen: 1 Absatz\n" .
            "  - Alle übrigen Gruppen (Sport, Kultur, Gesundheit, Gesellschaft, Lokal, Reise, Sonstiges): zusammen 0 bis 2 Absätze, nur wenn wirklich bedeutsam\n" .
            "REIHENFOLGE der Absätze im items-Array, STRIKT EINHALTEN:\n" .
            "  1. zuerst alle Wissen-&-Technik-Absätze (direkt nach der Meldung des Tages)\n" .
            "  2. dann alle Politik-Absätze\n" .
            "  3. dann alle Wirtschaft-&-Finanzen-Absätze\n" .
            "  4. dann Sport, Kultur & Medien, Gesundheit, Gesellschaft & Panorama, Lokal & Regional, Reise & Lifestyle, Sonstiges (in dieser Folge)\n" .
            "Themengruppen ohne ausgewählte Story werden einfach übersprungen. Innerhalb derselben Gruppe absteigend nach Brisanz und Tragweite. " .
            "Mehrfach-Berichterstattung zur gleichen Story (auch über mehrere Tage) in einem Absatz bündeln und dabei den AKTUELLEN Stand laut den JÜNGSTEN Artikeln (höchstes Datum) beschreiben — NICHT das Anfangs-Framing vom Wochenbeginn. " .
            "Bei laufenden Ereignissen (Turniere, Kriege, Prozesse, Wahlen) den heutigen Stand explizit benennen (z. B. aktuelle Turnierphase/Spieltag, jüngste Entwicklung), nicht den Beginn. " .
            "WICHTIG: Die Story aus \"Meldung des Tages\" DARF in der Wochenübersicht NICHT erneut " .
            "auftauchen — wähle thematisch komplett andere Geschichten, damit es keine inhaltliche " .
            "Doppelung gibt.\n\n" .
            "Sprache: klar, nüchtern, plakativ, ohne Floskeln.\n\n" .
            "WICHTIG: Artikel-Nummern dürfen im \"paragraph\"-Text NUR in der Inline-Link-Form [Begriff](N) vorkommen (siehe unten). " .
            "Schreibe KEINE nackten Zahlen oder Index-Listen wie \"(5, 12, 27)\" in den Fließtext.\n\n" .
            "WICHTIG: Schreibe KEIN Datum und KEINEN '(heute)'-Marker in den \"paragraph\"-Text, auch nicht am Anfang. " .
            "Die Datumsangaben in den Eingabezeilen dienen NUR deiner Einordnung des aktuellen Stands — der Fließtext ist reine Prosa OHNE Datums-Präfix.\n\n" .
            "INLINE-LINKS: Verlinke in JEDEM Absatz die zentralen Begriffe, die sich auf einen konkreten Quell-Artikel " .
            "beziehen (Eigennamen, Orte, Ereignisse, Produkte, Kernbegriffe), inline im Markdown-Format [Begriff](N). " .
            "Dabei ist N die Artikel-Nummer aus der obigen Liste — genau die Nummer, die den verlinkten Sachverhalt belegt " .
            "und die du auch in \"sources\" nennst. Setze 2 bis 4 solcher Inline-Links pro Absatz auf die wichtigsten Begriffe; " .
            "jede Nummer aus \"sources\" soll möglichst mindestens einmal als Inline-Link im Text auftauchen. " .
            "Verschachtele Links NICHT und verlinke denselben Begriff nur einmal. " .
            "Zusätzlich darfst du sparsam weitere Kernbegriffe OHNE Link mit doppelten Sternchen (**…**) fett hervorheben. " .
            "Beispiel: [Olaf Scholz](5) kündigte den Rücktritt aus dem [NATO-Bündnis](12) an.\n\n" .
            "Antworte AUSSCHLIESSLICH mit gültigem JSON, keine Erklärung davor oder dahinter, kein Markdown-Codeblock:\n" .
            "{\"top_today\":{\"paragraph\":\"...\",\"sources\":[1,4]},\"items\":[{\"paragraph\":\"...\",\"sources\":[1,4,12]}]}\n" .
            "\"top_today\" darf auch null sein. Die Zahlen in \"sources\" sind die Indizes der Artikel aus der Liste oben.";

        try {
            $aiClass = 'vielhuber\\aihelper\\aihelper';
            $aiUrl = (string) ($aiConfig['url'] ?? '');
            $ai = $aiClass::create(
                provider: $provider,
                model: $model,
                temperature: (float) ($aiConfig['temperature'] ?? 0.3),
                api_key: $apiKey,
                max_tries: (int) ($aiConfig['max_tries'] ?? 2),
                timeout: (int) ($aiConfig['timeout'] ?? 120),
                url: $aiUrl !== '' ? $aiUrl : null
            );
            $response = $ai->ask(prompt: $prompt);
            $resp = $response['response'] ?? null;
        } catch (\Throwable $e) {
            $emit('  ⚠️  Tagesübersicht fehlgeschlagen: ' . $e->getMessage());
            return;
        }

        // aihelper auto-decodes JSON responses to stdClass / array. For
        // string responses, strip an optional markdown code fence first.
        if (is_object(value: $resp) || is_array(value: $resp)) {
            $parsed = json_decode(json: (string) json_encode(value: $resp), associative: true);
        } else {
            $raw = trim(string: (string) $resp);
            $raw = (string) preg_replace(pattern: '~^\s*```(?:json)?\s*|\s*```\s*$~i', replacement: '', subject: $raw);
            $parsed = json_decode(json: $raw, associative: true);
        }
        if (!is_array(value: $parsed) || !isset($parsed['items']) || !is_array(value: $parsed['items'])) {
            $emit('  ⚠️  Tagesübersicht: AI-Antwort kein gültiges JSON');
            return;
        }

        $items = [];
        foreach ($parsed['items'] as $item) {
            $mapped = is_array(value: $item) ? $this->mapDigestSources(rawItem: $item, articles: $articles) : null;
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        $topToday = null;
        if (isset($parsed['top_today']) && is_array(value: $parsed['top_today'])) {
            $topToday = $this->mapDigestSources(rawItem: $parsed['top_today'], articles: $articles);
        }

        if ($items === [] && $topToday === null) {
            $emit('  ⚠️  Tagesübersicht: keine validen Items extrahiert');
            return;
        }

        $weatherLocation = (string) ($this->loadConfig()['weather_location'] ?? '');
        $weather = $weatherLocation !== '' ? $this->fetchWeather(location: $weatherLocation) : null;
        if ($weather !== null) {
            $weather['prose'] = $this->generateWeatherProse(
                weather: $weather,
                aiConfig: $aiConfig,
                apiKey: $apiKey
            );
        }

        $tv = $this->buildTalkshowBlock(db: $db, aiConfig: $aiConfig, apiKey: $apiKey, cutoff: $cutoff);

        // Media blocks (Streaming, Kino, Musik, Gaming) — same mechanics as
        // the talk-show block, one LLM prose paragraph per section.
        $media = [];
        foreach ($this->mediaSections() as $sectionKey => $section) {
            $block = $this->buildProseBlock(
                db: $db,
                paperKeys: $section['papers'],
                since: $now - (int) $section['window_days'] * 86400,
                limit: (int) $section['limit'],
                intro: (string) $section['intro'],
                requirements: (string) $section['requirements'],
                refNoun: (string) $section['refNoun'],
                aiConfig: $aiConfig,
                apiKey: $apiKey
            );
            if ($block !== null) {
                $media[$sectionKey] = $block;
            }
        }

        $payload = [
            'generated_at' => time(),
            'window_start' => $cutoff,
            'top_today' => $topToday,
            'items' => $items,
            'weather' => $weather,
            'tv' => $tv,
            'media' => $media,
        ];
        $this->cacheSet(
            key: 'daily_digest',
            value: (string) json_encode(value: $payload, flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $emit(sprintf(
            '  → %d Wochen-Stories aus %d Artikeln (%d heute, Meldung des Tages: %s, Wetter: %s, TV: %s, Medien: %d/%d Blöcke)',
            count(value: $items),
            count(value: $articles),
            $todayCount,
            $topToday !== null ? 'ja' : 'nein',
            $weather !== null ? sprintf('%s %.0f°C', $weather['location'], $weather['temp_current']) : 'nein',
            $tv !== null ? sprintf('%d Sendungen', (int) ($tv['count'] ?? 0)) : 'nein',
            count(value: $media),
            count(value: $this->mediaSections())
        ));
    }

    /**
     * Pull the last 7 days of talk-show episodes, ask the LLM to verdichten
     * the two to three brisantesten Sendungen as a single prose paragraph
     * plus a list of source indices. Returns null when no episodes exist
     * or no prose could be produced — the digest then omits the block.
     *
     * @param array<string, mixed> $aiConfig
     * @return array{paragraph: string, sources: array<int, array{url: string, paper: string}>, count: int}|null
     */
    private function buildTalkshowBlock(PDO $db, array $aiConfig, string $apiKey, int $cutoff): ?array
    {
        // Restrict to the configured talk-show papers explicitly — the
        // category 'TV' alone gets occasional false positives from articles
        // about TV programming on news sites (LLM over-matching the new
        // leaf during Phase 6).
        return $this->buildProseBlock(
            db: $db,
            paperKeys: $this->talkshowPapers(),
            since: $cutoff,
            limit: 60,
            intro: 'Du erhältst die Schlagzeilen der letzten politischen Talkshows ' .
                '(Maischberger, Markus Lanz, Caren Miosga, hart aber fair, Maybrit Illner) ' .
                'der vergangenen 7 Tage. Wähle die 2 bis 3 BRISANTESTEN Sendungen aus und ' .
                'verdichte sie in einem prägnanten Fließtext (2 bis 3 Sätze).',
            requirements: "- Wähle die brisantesten Diskussionen — Streit, harte Konfrontation, große Tragweite — " .
                "  NICHT die Sendungen mit den dominantesten Themen, sondern die mit der schärfsten Auseinandersetzung.\n" .
                "- Pro Sendung KURZ: welche Show, welches Thema, welche Pointe; " .
                "  Gäste-Namen organisch einbauen, keine reine Liste.\n" .
                "- Verlinkbare Begriffe: Show-Name, Gast, Streitthema.\n" .
                '- Keine Sender-Übersicht.',
            refNoun: 'Sendungs-Nummer',
            aiConfig: $aiConfig,
            apiKey: $apiKey
        );
    }

    /**
     * Configuration of the media digest blocks — one prose section each for
     * streaming series, streaming movies, cinema, music albums and PC games,
     * built with the exact same mechanics as the talk-show block. The keys
     * double as payload keys inside the cached digest ("media" object).
     *
     * @return array<string, array{papers: array<int, string>, window_days: int, limit: int, heading: string, subtitle: string, intro: string, requirements: string, refNoun: string}>
     */
    private function mediaSections(): array
    {
        return [
            'streaming_serien' => [
                'papers' => ['streaming_serien'],
                'window_days' => self::MEDIA_WINDOW_DAYS,
                'limit' => 80,
                'heading' => 'Streaming-Serien',
                'subtitle' => 'Netflix · Prime Video · Apple TV+ · Disney+',
                'intro' => 'Du erhältst Serien, die zuletzt neu oder mit neuen Folgen auf Netflix, Prime Video, Apple TV+ oder Disney+ ' .
                    'erschienen sind (Rotten-Tomatoes-Daten mit Kritiker- und Publikums-Score in Prozent). ' .
                    'Wähle die 3 bis 4 LOHNENDSTEN neuen Serien bzw. Staffeln aus und verdichte sie in einem prägnanten Fließtext (2 bis 4 Sätze).',
                'requirements' => "- Bevorzuge echte Serien-Neustarts und neue Staffeln nennenswerter Produktionen, gern mit hohem Kritiker-Score; " .
                    "  ignoriere Dauerläufer-Reality-Formate, Game-Shows und alte Katalog-Ware.\n" .
                    '- Nenne zu jeder Empfehlung organisch den Streaming-Dienst.',
                'refNoun' => 'Titel-Nummer',
            ],
            'streaming_filme' => [
                'papers' => ['streaming_filme'],
                'window_days' => self::MEDIA_WINDOW_DAYS,
                'limit' => 80,
                'heading' => 'Streaming-Filme',
                'subtitle' => 'Netflix · Prime Video · Apple TV+ · Disney+',
                'intro' => 'Du erhältst Filme, die zuletzt neu auf Netflix, Prime Video, Apple TV+ oder Disney+ erschienen sind ' .
                    '(Rotten-Tomatoes-Daten mit Kritiker- und Publikums-Score in Prozent). ' .
                    'Wähle die 3 bis 4 SEHENSWERTESTEN aus und verdichte sie in einem prägnanten Fließtext (2 bis 4 Sätze).',
                'requirements' => "- Bevorzuge relevante Neuerscheinungen mit hohem Kritiker-Score; " .
                    "  ignoriere Katalog-Ware, Stand-up-Mitschnitte und B-Movies.\n" .
                    '- Nenne zu jeder Empfehlung organisch den Streaming-Dienst.',
                'refNoun' => 'Titel-Nummer',
            ],
            'kino' => [
                'papers' => ['kino'],
                'window_days' => self::MEDIA_WINDOW_DAYS,
                'limit' => 60,
                'heading' => 'Kino',
                'subtitle' => 'neu angelaufen',
                'intro' => 'Du erhältst Filme, die zuletzt im Kino angelaufen sind (Rotten-Tomatoes-Daten mit Kritiker- und ' .
                    'Publikums-Score in Prozent). Wähle die 3 bis 4 SEHENSWERTESTEN aktuellen Kinofilme aus und verdichte sie in einem ' .
                    'prägnanten Fließtext (2 bis 4 Sätze).',
                'requirements' => '- Bevorzuge die größten und bestbewerteten Kinostarts; ignoriere Nischen-Dokus.',
                'refNoun' => 'Titel-Nummer',
            ],
            'musik' => [
                'papers' => ['alben', 'alben_metacritic'],
                'window_days' => 45,
                'limit' => 60,
                'heading' => 'Musik',
                'subtitle' => 'neue Alben',
                'intro' => 'Du erhältst die am besten bewerteten Album-Neuerscheinungen: laut.de-Rezensionen (Skala 1-5 plus das ' .
                    'aktuelle Album der Woche) und Metacritic-Alben (Metascore 0-100). Dasselbe Album kann doppelt vorkommen. ' .
                    'Wähle die 3 bis 4 BESTEN Alben aus und verdichte sie in einem prägnanten Fließtext (2 bis 4 Sätze).',
                'requirements' => '- Bevorzuge die höchsten Wertungen und das Album der Woche; nenne Künstler und Albumtitel organisch; nenne jedes Album höchstens einmal.',
                'refNoun' => 'Titel-Nummer',
            ],
            'gaming' => [
                'papers' => ['spiele'],
                'window_days' => self::MEDIA_WINDOW_DAYS,
                'limit' => 40,
                'heading' => 'Gaming',
                'subtitle' => 'neue PC-Spiele',
                'intro' => 'Du erhältst die bestbewerteten neuen PC-Spiele der letzten drei Monate (Metacritic-Metascore). ' .
                    'Wähle die 3 bis 4 BESTEN aus und verdichte sie in einem prägnanten Fließtext (2 bis 4 Sätze).',
                'requirements' => '- Bevorzuge die höchsten Metascores und nennenswerte Releases; erwähne kurz, was für ein Spiel es jeweils ist.',
                'refNoun' => 'Titel-Nummer',
            ],
        ];
    }

    /**
     * Shared prose-block builder behind the talk-show and media digest
     * sections: pull the recent articles of the given papers, have the LLM
     * verdichten them into one short paragraph with inline links plus source
     * indices, then resolve those to {url, paper} entries. Returns null when
     * no rows exist or no prose could be produced — the digest then omits
     * the block.
     *
     * @param array<int, string> $paperKeys
     * @param array<string, mixed> $aiConfig
     * @return array{paragraph: string, sources: array<int, array{url: string, paper: string}>, count: int}|null
     */
    private function buildProseBlock(PDO $db, array $paperKeys, int $since, int $limit, string $intro, string $requirements, string $refNoun, array $aiConfig, string $apiKey): ?array
    {
        if ($paperKeys === []) {
            return null;
        }
        $list = implode(separator: ',', array: array_map(callback: fn(string $p): string => "'" . str_replace(search: "'", replace: "''", subject: $p) . "'", array: $paperKeys));
        $stmt = $db->prepare(query: "
            SELECT url, paper, title, published_at
            FROM articles
            WHERE paper IN ({$list})
              AND published_at >= :since
              AND duplicate_of IS NULL
              AND title IS NOT NULL AND title <> ''
            ORDER BY published_at DESC
            LIMIT " . (int) $limit . '
        ');
        $stmt->execute(params: [':since' => $since]);
        $rows = (array) $stmt->fetchAll(mode: PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        $papers = $this->papers();
        $numbered = [];
        $lines = [];
        foreach ($rows as $i => $r) {
            $paperKey = (string) ($r['paper'] ?? '');
            $label = (string) ($papers[$paperKey]['label'] ?? $paperKey);
            $title = (string) ($r['title'] ?? '');
            $publishedAt = (int) ($r['published_at'] ?? 0);
            $numbered[] = [
                'url' => (string) ($r['url'] ?? ''),
                'paper' => $label,
            ];
            $lines[] = sprintf('%d. [%s, %s] %s', $i + 1, $label, date(format: 'd.m.', timestamp: $publishedAt), mb_substr(string: $title, start: 0, length: 220));
        }
        $generated = $this->generateProseFromLines(lines: $lines, intro: $intro, requirements: $requirements, refNoun: $refNoun, aiConfig: $aiConfig, apiKey: $apiKey);
        if ($generated === null) {
            return null;
        }
        // Rewrite inline references, then resolve the explicit source indices
        // (1-based) to {url, paper} entries; dedup by URL and ignore
        // out-of-range numbers the model might hallucinate.
        $sources = [];
        $paragraph = $this->linkifyDigestParagraph(paragraph: $generated['paragraph'], numbered: $numbered, sources: $sources);
        $seenUrls = [];
        foreach ($sources as $s) {
            $seenUrls[$s['url']] = true;
        }
        foreach ($generated['sources'] as $idx) {
            $i = (int) $idx - 1;
            if (!isset($numbered[$i])) {
                continue;
            }
            $url = (string) ($numbered[$i]['url'] ?? '');
            if ($url === '' || isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;
            $sources[] = $numbered[$i];
        }
        return [
            'paragraph' => $paragraph,
            'sources' => $sources,
            'count' => count(value: $rows),
        ];
    }

    /**
     * Shared LLM prose generation for the digest blocks: the intro and the
     * task-specific requirement bullets come from the caller, the inline-link
     * and JSON-format instructions are identical for every block.
     *
     * @param array<int, string> $lines
     * @param array<string, mixed> $aiConfig
     * @return array{paragraph: string, sources: array<int, int>}|null
     */
    private function generateProseFromLines(array $lines, string $intro, string $requirements, string $refNoun, array $aiConfig, string $apiKey): ?array
    {
        if (!class_exists(class: 'vielhuber\\aihelper\\aihelper') || $lines === []) {
            return null;
        }
        $provider = (string) ($aiConfig['provider'] ?? '');
        $model = (string) ($aiConfig['model'] ?? '');
        if ($provider === '' || $model === '' || $apiKey === '') {
            return null;
        }
        $prompt = $intro . "\n\n" .
            "Liste:\n" . implode(separator: "\n", array: $lines) . "\n\n" .
            "Anforderungen:\n" .
            rtrim(string: $requirements) . "\n" .
            "- INLINE-LINKS: Verlinke die zentralen Begriffe, die sich auf einen konkreten Eintrag beziehen, " .
            "  inline im Markdown-Format [Begriff](N) — N ist die {$refNoun} " .
            "  aus der Liste oben, dieselbe, die du in \"sources\" nennst. 2 bis 4 solcher Links; jede Nummer aus " .
            "  \"sources\" soll möglichst einmal als Inline-Link vorkommen. Verschachtele Links nicht.\n" .
            "- Zusätzlich darfst du sparsam weitere Kernbegriffe OHNE Link mit Markdown-Bold (**…**) hervorheben.\n" .
            "- Keine Aufzählung, kein Datum.\n" .
            "- WICHTIG: {$refNoun}n dürfen im \"paragraph\"-Text NUR in der Inline-Link-Form [Begriff](N) vorkommen. " .
            "  KEINE nackten Zahlen oder Index-Listen im Fließtext.\n\n" .
            "Antworte AUSSCHLIESSLICH mit gültigem JSON, kein Markdown-Codeblock:\n" .
            "{\"paragraph\":\"...\",\"sources\":[1,4]}\n" .
            "Die Zahlen in \"sources\" sind die Indizes der gewählten Einträge aus der Liste oben.";
        try {
            $aiClass = 'vielhuber\\aihelper\\aihelper';
            $aiUrl = (string) ($aiConfig['url'] ?? '');
            $ai = $aiClass::create(
                provider: $provider,
                model: $model,
                temperature: (float) ($aiConfig['temperature'] ?? 0.4),
                api_key: $apiKey,
                max_tries: (int) ($aiConfig['max_tries'] ?? 2),
                timeout: (int) ($aiConfig['timeout'] ?? 60),
                url: $aiUrl !== '' ? $aiUrl : null
            );
            $resp = $ai->ask(prompt: $prompt)['response'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
        if (is_object(value: $resp) || is_array(value: $resp)) {
            $parsed = json_decode(json: (string) json_encode(value: $resp), associative: true);
        } else {
            $raw = trim(string: (string) $resp);
            $raw = (string) preg_replace(pattern: '~^\s*```(?:json)?\s*|\s*```\s*$~i', replacement: '', subject: $raw);
            $parsed = json_decode(json: $raw, associative: true);
        }
        if (!is_array(value: $parsed) || !isset($parsed['paragraph']) || !is_string($parsed['paragraph'])) {
            return null;
        }
        $paragraph = trim(string: $parsed['paragraph']);
        // Strip trailing index lists "(5, 12)" the model sometimes leaks
        // into the prose despite the prompt. Lookbehind spares inline links
        // ending in "…](3)" so they survive linkification later.
        $paragraph = trim(string: (string) preg_replace(pattern: '/\s*(?<!\])[\(\[][\d,\s]+[\)\]]\s*$/u', replacement: '', subject: $paragraph));
        if ($paragraph === '') {
            return null;
        }
        $sources = [];
        foreach ((array) ($parsed['sources'] ?? []) as $idx) {
            $n = (int) $idx;
            if ($n > 0) {
                $sources[] = $n;
            }
        }
        return ['paragraph' => $paragraph, 'sources' => $sources];
    }

    /**
     * Fetch current weather plus an 8-day daily forecast (today + 7) for
     * the given city via Open-Meteo's free, key-less APIs. Returns null on
     * any failure so the digest still renders without a weather block.
     *
     * @return array{location: string, temp_current: float, days: array<int, array{date: string, temp_min: float, temp_max: float, description: string}>}|null
     */
    private function fetchWeather(string $location): ?array
    {
        if ($location === '') {
            return null;
        }
        $geoUrl = 'https://geocoding-api.open-meteo.com/v1/search?'
            . http_build_query(data: ['name' => $location, 'count' => 1, 'language' => 'de', 'format' => 'json']);
        $geoRaw = $this->httpGet(url: $geoUrl, timeout: 10);
        if ($geoRaw === null) {
            return null;
        }
        $geo = json_decode(json: $geoRaw, associative: true);
        $hit = is_array(value: $geo) && isset($geo['results'][0]) ? $geo['results'][0] : null;
        if (!is_array(value: $hit) || !isset($hit['latitude'], $hit['longitude'])) {
            return null;
        }
        $lat = (float) $hit['latitude'];
        $lon = (float) $hit['longitude'];
        $resolvedName = (string) ($hit['name'] ?? $location);

        $forecastUrl = 'https://api.open-meteo.com/v1/forecast?' . http_build_query(data: [
            'latitude' => $lat,
            'longitude' => $lon,
            'current' => 'temperature_2m,weather_code',
            'daily' => 'temperature_2m_max,temperature_2m_min,weather_code',
            'timezone' => 'auto',
            'forecast_days' => 8,
        ]);
        $forecastRaw = $this->httpGet(url: $forecastUrl, timeout: 10);
        if ($forecastRaw === null) {
            return null;
        }
        $forecast = json_decode(json: $forecastRaw, associative: true);
        if (!is_array(value: $forecast)) {
            return null;
        }
        $current = $forecast['current'] ?? null;
        $daily = $forecast['daily'] ?? null;
        if (!is_array(value: $current) || !is_array(value: $daily)) {
            return null;
        }
        $tempCurrent = isset($current['temperature_2m']) ? (float) $current['temperature_2m'] : null;
        if ($tempCurrent === null) {
            return null;
        }
        $dates = (array) ($daily['time'] ?? []);
        $mins = (array) ($daily['temperature_2m_min'] ?? []);
        $maxs = (array) ($daily['temperature_2m_max'] ?? []);
        $codes = (array) ($daily['weather_code'] ?? []);
        $days = [];
        foreach ($dates as $i => $date) {
            if (!isset($mins[$i], $maxs[$i])) {
                continue;
            }
            $days[] = [
                'date' => (string) $date,
                'temp_min' => (float) $mins[$i],
                'temp_max' => (float) $maxs[$i],
                'description' => $this->wmoWeatherDescription(code: (int) ($codes[$i] ?? -1)),
            ];
        }
        if ($days === []) {
            return null;
        }
        return [
            'location' => $resolvedName,
            'temp_current' => $tempCurrent,
            'days' => $days,
        ];
    }

    /**
     * Turn the raw 8-day forecast into a 2-3-sentence prose paragraph via
     * the LLM. Returns null on any failure so the renderer can fall back
     * to a minimal "today" line.
     */
    private function generateWeatherProse(array $weather, array $aiConfig, string $apiKey): ?string
    {
        if (!class_exists(class: 'vielhuber\\aihelper\\aihelper')) {
            return null;
        }
        $provider = (string) ($aiConfig['provider'] ?? '');
        $model = (string) ($aiConfig['model'] ?? '');
        if ($provider === '' || $model === '' || $apiKey === '') {
            return null;
        }
        $location = (string) ($weather['location'] ?? '');
        $days = (array) ($weather['days'] ?? []);
        if ($location === '' || $days === []) {
            return null;
        }
        $lines = [];
        foreach ($days as $day) {
            if (!is_array(value: $day) || !isset($day['date'], $day['temp_min'], $day['temp_max'])) {
                continue;
            }
            $ts = (int) strtotime(datetime: (string) $day['date']);
            $weekday = $this->germanWeekday(timestamp: $ts);
            $lines[] = sprintf(
                '%s: %.0f bis %.0f °C, %s',
                $weekday,
                (float) $day['temp_min'],
                (float) $day['temp_max'],
                (string) ($day['description'] ?? '')
            );
        }
        $current = isset($weather['temp_current']) ? sprintf('%.0f °C', (float) $weather['temp_current']) : null;
        $prompt = "Schreibe einen flüssigen, prägnanten Wetterabsatz auf Deutsch (2 bis 3 Sätze) " .
            "für " . $location . ", basierend auf folgendem 8-Tage-Trend " .
            ($current !== null ? "(aktuell " . $current . ")" : '') . ":\n\n" .
            implode(separator: "\n", array: $lines) . "\n\n" .
            "Anforderungen:\n" .
            "- Keine Aufzählung, kein Datum für jeden Tag, keine Wochentags-Liste.\n" .
            "- Interpretiere den Trend: warm/kühl, sonnig/regnerisch, stabil/wechselhaft, " .
            "verbessert sich / verschlechtert sich im Laufe der Woche.\n" .
            "- Nenne die heutige Lage als Einstieg und ordne danach den Wochenverlauf ein.\n" .
            "- Hebe 1 bis 2 zentrale Begriffe (z. B. Höchstwerte, Wettercharakter) mit Markdown-Bold (**…**) hervor.\n" .
            "- Antworte AUSSCHLIESSLICH mit dem Fließtext, ohne Anführungszeichen, ohne Codeblock, ohne Vorrede.";
        try {
            $aiClass = 'vielhuber\\aihelper\\aihelper';
            $aiUrl = (string) ($aiConfig['url'] ?? '');
            $ai = $aiClass::create(
                provider: $provider,
                model: $model,
                temperature: (float) ($aiConfig['temperature'] ?? 0.4),
                api_key: $apiKey,
                max_tries: (int) ($aiConfig['max_tries'] ?? 2),
                timeout: (int) ($aiConfig['timeout'] ?? 60),
                url: $aiUrl !== '' ? $aiUrl : null
            );
            $resp = $ai->ask(prompt: $prompt)['response'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
        if (is_object(value: $resp) || is_array(value: $resp)) {
            $resp = json_encode(value: $resp);
        }
        $text = trim(string: (string) $resp);
        $text = (string) preg_replace(pattern: '~^\s*```(?:\w+)?\s*|\s*```\s*$~i', replacement: '', subject: $text);
        $text = trim(string: $text, characters: " \t\n\r\0\x0B\"'");
        return $text !== '' ? $text : null;
    }

    /**
     * Plain HTTP GET that returns the response body or null on any non-2xx
     * / network failure. Local helper for weather lookups — kept private
     * because it deliberately swallows errors.
     */
    private function httpGet(string $url, int $timeout = 10): ?string
    {
        $ch = curl_init(url: $url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array(handle: $ch, options: [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'extrablatt/1.0',
        ]);
        $raw = curl_exec(handle: $ch);
        $http = (int) curl_getinfo(handle: $ch, option: CURLINFO_HTTP_CODE);
        if (!is_string(value: $raw) || $http < 200 || $http >= 300) {
            return null;
        }
        return $raw;
    }

    /**
     * Map a WMO weather code (Open-Meteo daily forecast) to a short German
     * label. Unknown codes fall back to a neutral phrase.
     */
    private function wmoWeatherDescription(int $code): string
    {
        $map = [
            0 => 'klar',
            1 => 'überwiegend klar',
            2 => 'teils bewölkt',
            3 => 'bedeckt',
            45 => 'nebelig',
            48 => 'gefrierender Nebel',
            51 => 'leichter Nieselregen',
            53 => 'Nieselregen',
            55 => 'starker Nieselregen',
            56 => 'leichter gefrierender Niesel',
            57 => 'gefrierender Niesel',
            61 => 'leichter Regen',
            63 => 'Regen',
            65 => 'starker Regen',
            66 => 'leichter gefrierender Regen',
            67 => 'gefrierender Regen',
            71 => 'leichter Schneefall',
            73 => 'Schneefall',
            75 => 'starker Schneefall',
            77 => 'Schneegriesel',
            80 => 'leichte Regenschauer',
            81 => 'Regenschauer',
            82 => 'kräftige Regenschauer',
            85 => 'leichte Schneeschauer',
            86 => 'Schneeschauer',
            95 => 'Gewitter',
            96 => 'Gewitter mit leichtem Hagel',
            99 => 'Gewitter mit Hagel',
        ];
        return $map[$code] ?? 'wechselhaft';
    }

    /**
     * Rewrite inline article references [Begriff](N) in a digest paragraph so
     * N points at the paragraph's OWN (1-based) source list instead of the
     * volatile generation-time article index — that keeps the cached digest
     * self-contained (the renderer never needs the original article list).
     * Every referenced article is appended to $sources (by reference, deduped
     * by URL) so the source footer stays complete. Unresolvable references are
     * unwrapped to plain term text.
     *
     * @param array<int, array<string, mixed>> $numbered generation-time index → row with url/paper
     * @param array<int, array{url: string, paper: string}> $sources in/out accumulator
     */
    private function linkifyDigestParagraph(string $paragraph, array $numbered, array &$sources): string
    {
        $posByUrl = [];
        foreach ($sources as $i => $s) {
            $posByUrl[(string) ($s['url'] ?? '')] = $i + 1;
        }
        return (string) preg_replace_callback(
            pattern: '/\[([^\[\]]+)\]\((\d+)\)/',
            callback: function (array $m) use ($numbered, &$sources, &$posByUrl): string {
                $term = $m[1];
                $idx = (int) $m[2] - 1;
                $url = (string) ($numbered[$idx]['url'] ?? '');
                if ($url === '') {
                    return $term;
                }
                if (!isset($posByUrl[$url])) {
                    $sources[] = ['url' => $url, 'paper' => (string) ($numbered[$idx]['paper'] ?? '')];
                    $posByUrl[$url] = count(value: $sources);
                }
                return '[' . $term . '](' . $posByUrl[$url] . ')';
            },
            subject: $paragraph
        );
    }

    /**
     * Validate one LLM-emitted digest item: trim the paragraph, rewrite inline
     * article references and map each source index back to the corresponding
     * article (URL + paper), drop out-of-range / duplicate indices. Returns
     * null if the paragraph is empty or no source survives mapping.
     */
    private function mapDigestSources(array $rawItem, array $articles): ?array
    {
        $paragraph = trim(string: (string) ($rawItem['paragraph'] ?? ''));
        if ($paragraph === '') {
            return null;
        }
        $sources = [];
        $paragraph = $this->linkifyDigestParagraph(paragraph: $paragraph, numbered: $articles, sources: $sources);
        $seenUrls = [];
        foreach ($sources as $s) {
            $seenUrls[$s['url']] = true;
        }
        foreach ((array) ($rawItem['sources'] ?? []) as $src) {
            $idx = (int) $src - 1;
            if ($idx < 0 || $idx >= count(value: $articles)) {
                continue;
            }
            $url = (string) ($articles[$idx]['url'] ?? '');
            if ($url === '' || isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;
            $sources[] = [
                'url' => $url,
                'paper' => (string) ($articles[$idx]['paper'] ?? ''),
            ];
        }
        if ($sources === []) {
            return null;
        }
        return ['paragraph' => $paragraph, 'sources' => $sources];
    }

    /**
     * Render the persisted daily digest as an HTML <section>, or '' if
     * no digest exists for today.
     */
    private function renderDigestHtml(): string
    {
        $raw = $this->cacheGet(key: 'daily_digest');
        if ($raw === null) {
            return '';
        }
        $data = json_decode(json: $raw, associative: true);
        if (!is_array(value: $data)) {
            return '';
        }
        // Window is rolling 7 days; only hide if the digest itself is older
        // than ~36h (would lag the week meaningfully). Otherwise trust it.
        $generatedAt = (int) ($data['generated_at'] ?? 0);
        if ($generatedAt > 0 && $generatedAt < time() - 36 * 3600) {
            return '';
        }
        $stamp = $generatedAt > 0 ? $generatedAt : time();
        $dateLabel = htmlspecialchars(string: (string) date(format: 'd.m.Y', timestamp: $stamp), flags: ENT_QUOTES);
        $windowStart = (int) ($data['window_start'] ?? ($stamp - 7 * 86400));
        $rangeLabel = htmlspecialchars(
            string: date(format: 'd.m.Y', timestamp: $windowStart) . ' – ' . date(format: 'd.m.Y', timestamp: $stamp),
            flags: ENT_QUOTES
        );

        $topToday = isset($data['top_today']) && is_array(value: $data['top_today']) ? $data['top_today'] : null;
        $leadHtml = '';
        if ($topToday !== null) {
            $inner = $this->buildDigestParagraph(item: $topToday);
            if ($inner !== '') {
                $leadHtml = '<div class="digest__lead">'
                    . '<h2 class="digest__title digest__title--lead">Meldung des Tages <span class="digest__date">' . $dateLabel . '</span></h2>'
                    . $inner
                    . '</div>';
            }
        }

        $items = (array) ($data['items'] ?? []);
        $paragraphs = '';
        foreach ($items as $item) {
            if (!is_array(value: $item)) {
                continue;
            }
            $paragraphs .= $this->buildDigestParagraph(item: $item);
        }

        $weatherHtml = '';
        $weather = isset($data['weather']) && is_array(value: $data['weather']) ? $data['weather'] : null;
        if ($weather !== null) {
            $weatherHtml = $this->buildWeatherBlock(weather: $weather);
        }

        $tvHtml = '';
        $tv = isset($data['tv']) && is_array(value: $data['tv']) ? $data['tv'] : null;
        if ($tv !== null) {
            $tvHtml = $this->buildProseSection(block: $tv, heading: 'TV-Talkshows', subtitle: 'letzte 7 Tage');
        }

        $mediaHtml = '';
        $media = isset($data['media']) && is_array(value: $data['media']) ? $data['media'] : [];
        foreach ($this->mediaSections() as $sectionKey => $section) {
            $block = $media[$sectionKey] ?? null;
            if (!is_array(value: $block)) {
                continue;
            }
            $mediaHtml .= $this->buildProseSection(block: $block, heading: $section['heading'], subtitle: $section['subtitle']);
        }

        if ($leadHtml === '' && $paragraphs === '' && $weatherHtml === '' && $tvHtml === '' && $mediaHtml === '') {
            return '';
        }

        $weeklyHtml = $paragraphs !== ''
            ? '<h2 class="digest__title">Wochenübersicht <span class="digest__date">' . $rangeLabel . '</span></h2>' . $paragraphs
            : '';

        return '<section class="digest">' . $leadHtml . $weeklyHtml . $tvHtml . $mediaHtml . $weatherHtml . '</section>';
    }

    /**
     * Render a prose digest section (Polit-Talk recap, media blocks) as a
     * single LLM-written paragraph plus inline source links — same shape as
     * a Wochenübersicht item, so we reuse buildDigestParagraph() for the
     * body. Heading and subtitle are trusted internal strings.
     *
     * @param array{paragraph?: string, prose?: string, sources?: array<int, array<string, mixed>>} $block
     */
    private function buildProseSection(array $block, string $heading, string $subtitle): string
    {
        // Accept the legacy "prose" key from older cached digests so a stale
        // cache still renders during the changeover scrape.
        $paragraph = trim(string: (string) ($block['paragraph'] ?? $block['prose'] ?? ''));
        if ($paragraph === '') {
            return '';
        }
        $body = $this->buildDigestParagraph(item: [
            'paragraph' => $paragraph,
            'sources' => $block['sources'] ?? [],
        ]);
        if ($body === '') {
            return '';
        }
        return '<div class="digest__tv">'
            . '<h2 class="digest__title">' . $heading . ' <span class="digest__date">' . $subtitle . '</span></h2>'
            . $body
            . '</div>';
    }

    /**
     * Mirror the current BILD.de front page as a tile grid: same teasers,
     * same images, same Kicker/Headline split, every tile linking straight
     * to the original article in a new window. The homepage HTML is cached
     * for 15 minutes so page loads stay instant; on fetch failure the stale
     * copy keeps serving.
     */
    private function buildBildBlock(): string
    {
        $stmt = $this->openDatabase()->prepare(query: 'SELECT value, updated_at FROM cache WHERE key = :k');
        $stmt->execute(params: [':k' => 'bild_home']);
        $row = $stmt->fetch(mode: PDO::FETCH_ASSOC);
        $html = is_array(value: $row) && (int) $row['updated_at'] > time() - 900 ? (string) $row['value'] : null;
        if ($html === null) {
            $result = $this->fetchViaImpersonate(url: 'https://www.bild.de/');
            if ($result->body !== null) {
                $html = $result->body;
                $this->cacheSet(key: 'bild_home', value: $html);
            }
            if ($html === null && is_array(value: $row)) {
                $html = (string) $row['value'];
            }
        }
        if ($html === null || trim(string: $html) === '') {
            return '<p class="viewnav__empty">BILD.de ist gerade nicht erreichbar.</p>';
        }
        $count = preg_match_all(pattern: '~<article[^>]*>(.*?)</article>~s', subject: $html, matches: $blocks);
        if ($count === false || $count === 0 || !isset($blocks[1])) {
            return '<p class="viewnav__empty">Keine Kacheln gefunden.</p>';
        }
        $tiles = '';
        $seenHrefs = [];
        $rendered = 0;
        foreach ($blocks[1] as $block) {
            if (preg_match(pattern: '~<a[^>]+href="([^"]+)"~', subject: $block, matches: $hrefMatch) !== 1) {
                continue;
            }
            $href = html_entity_decode(string: $hrefMatch[1], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');
            if (!str_starts_with(haystack: $href, needle: 'http')) {
                $href = 'https://www.bild.de' . $href;
            }
            if (!str_starts_with(haystack: $href, needle: 'https://www.bild.de/') || isset($seenHrefs[$href])) {
                continue;
            }
            if (preg_match(pattern: '~<img[^>]+src="(https://images\.bild\.de/[^"]+)"~', subject: $block, matches: $imgMatch) !== 1) {
                continue;
            }
            $headline = '';
            if (preg_match(pattern: '~teaser__title__headline">([^<]*)<~', subject: $block, matches: $headlineMatch) === 1) {
                $headline = trim(string: html_entity_decode(string: $headlineMatch[1], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
            }
            if ($headline === '') {
                continue;
            }
            $kicker = '';
            if (preg_match(pattern: '~teaser__title__kicker">([^<]*)<~', subject: $block, matches: $kickerMatch) === 1) {
                $kicker = trim(string: html_entity_decode(string: $kickerMatch[1], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
            }
            $seenHrefs[$href] = true;
            $imageUrl = html_entity_decode(string: $imgMatch[1], flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');
            $leadClass = $rendered === 0 ? ' bild__tile--lead' : '';
            $tiles .= '<a class="bild__tile' . $leadClass . '" href="' . htmlspecialchars(string: $href, flags: ENT_QUOTES) . '" target="_blank" rel="noreferrer noopener">'
                . '<img class="bild__img" src="' . htmlspecialchars(string: $imageUrl, flags: ENT_QUOTES) . '" alt="" loading="lazy">'
                . '<span class="bild__text">'
                . ($kicker !== '' ? '<span class="bild__kicker">' . htmlspecialchars(string: $kicker, flags: ENT_QUOTES) . ':</span> ' : '')
                . '<span class="bild__headline">' . htmlspecialchars(string: $headline, flags: ENT_QUOTES) . '</span>'
                . '</span>'
                . '</a>';
            $rendered++;
        }
        if ($tiles === '') {
            return '<p class="viewnav__empty">Keine Kacheln gefunden.</p>';
        }
        return '<section class="bild"><div class="bild__grid">' . $tiles . '</div></section>';
    }

    /**
     * Render the Quick Fact-Check shell: input form + result placeholder.
     * The actual LLM call happens asynchronously — the placeholder is
     * swapped in-place once a fetch of /?view=factcheck&factcheck_fragment=1
     * returns the result HTML. This keeps the initial page load instant
     * (< 1 s) even though the high-effort reasoning call takes 30-90 s.
     */
    private function buildFactCheckBlock(string $statement, bool $pending): string
    {
        // Wenn der Nutzer noch nichts eingegeben hat, eine Beispiel-These
        // direkt als value vorbelegen (per Pageload zufällig aus dem Pool).
        // Vier Themenfelder vertreten: Verschwörung, Wissenschaftsmythos,
        // Ernährungs-Folklore, medizinische Fehlinformation.
        $examples = [
            'Gibt es Reptiloide?',
            'Hat die Mondlandung 1969 wirklich stattgefunden?',
            'Nutzen wir nur 10 % unseres Gehirns?',
            'Sind Bio-Lebensmittel nachweislich gesünder als konventionelle?',
            'Verursachen Impfungen Autismus?',
        ];
        $inputValue = $statement !== '' ? $statement : $examples[array_rand(array: $examples)];
        $valueEsc = htmlspecialchars(string: $inputValue, flags: ENT_QUOTES);
        $form = '<form class="factcheck__form" method="get" action="/">'
            . '<input type="hidden" name="view" value="factcheck">'
            . '<input type="text" name="statement" value="' . $valueEsc . '" autocomplete="off" autofocus maxlength="1000">'
            . '<button type="submit">Prüfen</button>'
            . '</form>';
        $loadingHint = 'Das stärkste verfügbare Modell auf dem Endpoint analysiert die Aussage mit dem höchsten Reasoning-Effort. '
            . 'Es zerlegt die Behauptung in prüfbare Bestandteile, gleicht jede Komponente mit dem Trainingswissen ab und sucht '
            . 'passende Quellen aus seriösen Medien, peer-reviewed Studien und offiziellen Stellen (Behörden, Gerichte, Statistik-Ämter). '
            . 'Das Ergebnis ist eine prozentuale Einschätzung, eine ausführliche Begründung in mehreren Absätzen und 5–8 belastbare Quellen. '
            . 'Die Prüfung dauert üblicherweise 30 bis 90 Sekunden — das Ergebnis erscheint hier automatisch, sobald die Analyse abgeschlossen ist.';
        $emptyHint = 'Gib eine konkrete Tatsachenbehauptung ein – etwa eine Behauptung aus den Nachrichten, ein Statistik-Schnipsel oder eine politische Aussage, die du überprüfen willst. '
            . 'Das stärkste verfügbare Modell prüft sie mit dem höchsten Reasoning-Effort und liefert eine prozentuale Einschätzung, '
            . 'eine ausführliche Begründung mit historischem Kontext sowie 5–8 belastbare Quellen aus Medien, Studien und offiziellen Stellen. '
            . 'Die Prüfung dauert in der Regel 30 bis 90 Sekunden – du musst die Seite nicht offen halten und kannst zwischendurch wegschauen, das Ergebnis lädt sich automatisch nach.';
        $resultSlot = $pending
            ? '<div id="factcheck-result" class="factcheck__loading">'
                . '<div class="factcheck__spinner" aria-hidden="true"></div>'
                . '<p class="factcheck__hint">' . $loadingHint . '</p>'
                . '</div>'
            : '<div id="factcheck-result">'
                . '<p class="factcheck__hint">' . $emptyHint . '</p>'
                . '</div>';
        $script = '';
        if ($pending) {
            $statementJs = (string) json_encode(value: $statement, flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $script = '<script>(function(){'
                . 'var stmt = ' . $statementJs . ';'
                . 'var url = "/?view=factcheck&factcheck_fragment=1&statement=" + encodeURIComponent(stmt);'
                . 'fetch(url, {credentials: "same-origin"})'
                . '.then(function(r){ if (!r.ok) throw new Error("HTTP " + r.status); return r.text(); })'
                . '.then(function(html){'
                . '  var slot = document.getElementById("factcheck-result");'
                . '  if (slot) slot.outerHTML = html;'
                . '})'
                . '.catch(function(){'
                . '  var slot = document.getElementById("factcheck-result");'
                . '  if (slot) slot.innerHTML = "<p class=\"factcheck__hint\">Anfrage fehlgeschlagen – bitte erneut versuchen.</p>";'
                . '});'
                . '})();</script>';
        }
        return '<section class="factcheck">' . $form . $resultSlot . $script . '</section>';
    }

    /**
     * Render just the inner result block (verdict bar + prose + sources) or
     * a hint paragraph when no result is available. Used by the
     * factcheck_fragment endpoint — the response replaces the
     * #factcheck-result placeholder via outerHTML, so the wrapping div is
     * what JS swaps into the DOM.
     *
     * @param array{verdict: string, percentage: int, confidence: int, explanation: string, sources: array<int, array{label: string, url: string}>}|null $result
     */
    private function buildFactCheckResult(string $statement, ?array $result): string
    {
        if ($result === null) {
            $hint = $statement !== ''
                ? 'Konnte keine Bewertung erzeugen – der Endpoint war möglicherweise nicht erreichbar, das Modell hat das Reasoning-Budget aufgebraucht oder die Antwort war kein gültiges JSON. Versuch es bitte erneut.'
                : 'Gib eine konkrete Tatsachenbehauptung ein – etwa eine Behauptung aus den Nachrichten, ein Statistik-Schnipsel oder eine politische Aussage, die du überprüfen willst. '
                . 'Das stärkste verfügbare Modell prüft sie mit dem höchsten Reasoning-Effort und liefert eine prozentuale Einschätzung, '
                . 'eine ausführliche Begründung mit historischem Kontext sowie 5–8 belastbare Quellen aus Medien, Studien und offiziellen Stellen. '
                . 'Die Prüfung dauert in der Regel 30 bis 90 Sekunden – du musst die Seite nicht offen halten und kannst zwischendurch wegschauen, das Ergebnis lädt sich automatisch nach.';
            return '<div id="factcheck-result"><p class="factcheck__hint">' . $hint . '</p></div>';
        }
        $percentage = (int) $result['percentage'];
        $verdict = (string) $result['verdict'];
        $verdictKey = match (true) {
            $verdict === 'stimmt' => 'true',
            $verdict === 'stimmt nicht' => 'false',
            default => 'partial',
        };
        $verdictLabel = htmlspecialchars(string: $verdict, flags: ENT_QUOTES);
        $confidence = (int) $result['confidence'];
        $explanationEscaped = htmlspecialchars(string: trim(string: $result['explanation']), flags: ENT_QUOTES);
        $explanationEscaped = (string) preg_replace(
            pattern: '/\*\*(.+?)\*\*/s',
            replacement: '<strong>$1</strong>',
            subject: $explanationEscaped
        );
        // Split prose on paragraph breaks (double newlines or stand-alone
        // <br> chains) so longer explanations render as multiple paragraphs.
        $proseHtml = '';
        foreach (preg_split(pattern: '/\n{2,}/', subject: $explanationEscaped) ?: [$explanationEscaped] as $paragraph) {
            $paragraph = trim(string: (string) $paragraph);
            if ($paragraph === '') {
                continue;
            }
            $proseHtml .= '<p class="factcheck__prose">' . $paragraph . '</p>';
        }
        $sourcesHtml = '';
        foreach ($result['sources'] as $src) {
            $label = htmlspecialchars(string: $src['label'], flags: ENT_QUOTES);
            $url = (string) $src['url'];
            // filter_var(FILTER_VALIDATE_URL) accepts javascript: / data: URIs
            // as syntactically valid — gate the anchor on an explicit http/
            // https scheme to keep the href XSS-safe even if a manipulated
            // prompt convinces the LLM to emit a scheme-loaded URL.
            $scheme = $url !== '' ? strtolower(string: (string) parse_url(url: $url, component: PHP_URL_SCHEME)) : '';
            $isSafeUrl = in_array(needle: $scheme, haystack: ['http', 'https'], strict: true)
                && filter_var(value: $url, filter: FILTER_VALIDATE_URL) !== false;
            if ($isSafeUrl) {
                $sourcesHtml .= '<a href="' . htmlspecialchars(string: $url, flags: ENT_QUOTES) . '" target="_blank" rel="noreferrer noopener">' . $label . '</a>';
            } else {
                $sourcesHtml .= '<span>' . $label . '</span>';
            }
        }
        $sourcesBlock = $sourcesHtml !== '' ? '<div class="factcheck__sources">' . $sourcesHtml . '</div>' : '';
        $bar = '<div class="factcheck__bar factcheck__bar--' . $verdictKey . '">'
            . '<div class="factcheck__bar-fill" style="width: ' . $percentage . '%;"></div>'
            . '<span class="factcheck__bar-label">' . $percentage . '% – ' . $verdictLabel . '</span>'
            . '</div>';
        $confidenceHtml = '<div class="factcheck__meta">Sicherheit der Bewertung: <strong>' . $confidence . '%</strong></div>';
        return '<div id="factcheck-result">'
            . $bar
            . $confidenceHtml
            . $proseHtml
            . $sourcesBlock
            . '</div>';
    }

    /**
     * Render the weather footer block from a persisted weather payload.
     * Prefers the LLM-generated prose paragraph; falls back to a minimal
     * "today" sentence built from the raw forecast.
     */
    private function buildWeatherBlock(array $weather): string
    {
        $location = trim(string: (string) ($weather['location'] ?? ''));
        if ($location === '') {
            return '';
        }
        $locationHtml = htmlspecialchars(string: $location, flags: ENT_QUOTES);
        $prose = trim(string: (string) ($weather['prose'] ?? ''));
        if ($prose !== '') {
            $escaped = htmlspecialchars(string: $prose, flags: ENT_QUOTES);
            $escaped = (string) preg_replace(pattern: '/\*\*(.+?)\*\*/s', replacement: '<strong>$1</strong>', subject: $escaped);
            $body = '<p>' . $escaped . '</p>';
        } else {
            $days = (array) ($weather['days'] ?? []);
            $today = is_array(value: $days[0] ?? null) ? $days[0] : null;
            if ($today === null || !isset($today['temp_min'], $today['temp_max'])) {
                return '';
            }
            $current = isset($weather['temp_current']) ? (float) $weather['temp_current'] : null;
            $desc = htmlspecialchars(string: (string) ($today['description'] ?? ''), flags: ENT_QUOTES);
            $sentence = 'Heute in <strong>' . $locationHtml . '</strong>'
                . ($current !== null ? ' aktuell <strong>' . $this->formatTemperature(value: $current) . '</strong>' : '')
                . ($desc !== '' ? ', ' . $desc : '')
                . ', im Tagesverlauf zwischen <strong>' . $this->formatTemperature(value: (float) $today['temp_min']) . '</strong>'
                . ' und <strong>' . $this->formatTemperature(value: (float) $today['temp_max']) . '</strong>.';
            $body = '<p>' . $sentence . '</p>';
        }
        return '<div class="digest__weather">'
            . '<h2 class="digest__title">Wetter <span class="digest__date">' . $locationHtml . ' · 7-Tage-Trend</span></h2>'
            . $body
            . '</div>';
    }

    /**
     * Localised long weekday name. PHP's strftime is deprecated and
     * IntlDateFormatter requires the intl extension; a tiny lookup keeps
     * this independent of locale config.
     */
    private function germanWeekday(int $timestamp): string
    {
        $map = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        return $map[(int) date(format: 'w', timestamp: $timestamp)];
    }

    /**
     * Format a temperature as integer °C — fractional degrees add no value
     * in a forecast summary and look noisy in serif body text.
     */
    private function formatTemperature(float $value): string
    {
        return ((int) round(num: $value)) . ' °C';
    }

    /**
     * Render one digest item as a <p> with **bold** → <strong> upgrade and
     * a per-paper-deduped source link list. Returns '' if the paragraph
     * has no content after defensive index-list stripping.
     */
    private function buildDigestParagraph(array $item): string
    {
        $paragraph = trim(string: (string) ($item['paragraph'] ?? ''));
        // Strip trailing index lists like " (5, 12, 27)" or " [5, 12]" that
        // some models keep appending despite the prompt. The negative
        // lookbehind spares legit inline links ending in "…](3)" — those are
        // preceded by "]" and must survive for linkification below.
        $paragraph = (string) preg_replace(
            pattern: '/\s*(?<!\])[\(\[][\d,\s]+[\)\]]\s*$/u',
            replacement: '',
            subject: $paragraph
        );
        // Strip a leading date marker like "25.06. (heute) ", "25.06.2026 "
        // or "(heute) " — the model sometimes echoes the dated input-line
        // format into the prose. The paragraph must be pure text, no stamp.
        $paragraph = (string) preg_replace(
            pattern: '/^\s*(?:\d{1,2}\.\d{1,2}\.(?:\d{2,4})?\.?\s*)?(?:\(\s*heute\s*\)\s*)?/iu',
            replacement: '',
            subject: $paragraph
        );
        $paragraph = trim(string: $paragraph);
        if ($paragraph === '') {
            return '';
        }
        // Show ALL distinct cited sources (dedup by URL, not by paper) so the
        // full provenance is visible. Repeated outlets get a counter suffix
        // (e.g. "FT", "FT 2") so identical labels don't look like a bug.
        $sourceHtml = '';
        $seenUrls = [];
        $labelCounts = [];
        $papers = $this->papers();
        // Positional URL list (1-based via array index) that inline
        // references [Begriff](N) resolve against — order matches the stored
        // sources array, which is what linkifyDigestParagraph() indexed into.
        $sourceUrls = [];
        foreach ((array) ($item['sources'] ?? []) as $src) {
            $sourceUrls[] = is_array(value: $src) ? (string) ($src['url'] ?? '') : '';
        }
        foreach ((array) ($item['sources'] ?? []) as $src) {
            if (!is_array(value: $src)) {
                continue;
            }
            $url = (string) ($src['url'] ?? '');
            if ($url === '' || isset($seenUrls[$url]) || preg_match(pattern: '~^https?://~i', subject: $url) !== 1) {
                continue;
            }
            $seenUrls[$url] = true;
            $paper = (string) ($src['paper'] ?? '');
            $label = $paper !== ''
                ? (string) ($papers[$paper]['label'] ?? $paper)
                : (string) parse_url(url: $url, component: PHP_URL_HOST);
            $labelCounts[$label] = ($labelCounts[$label] ?? 0) + 1;
            $shown = $labelCounts[$label] > 1 ? $label . ' ' . $labelCounts[$label] : $label;
            $sourceHtml .= '<a href="' . htmlspecialchars(string: $url, flags: ENT_QUOTES)
                . '" target="_blank" rel="noreferrer noopener">'
                . htmlspecialchars(string: $shown, flags: ENT_QUOTES)
                . '</a>';
        }
        // Escape first, then upgrade markdown **bold** to <strong>. Doing
        // it in this order keeps the path XSS-safe: any HTML/script that
        // sneaks into the LLM output is neutralised by htmlspecialchars
        // before the regex runs, so only the literal ** markers can
        // produce real tags.
        $escaped = htmlspecialchars(string: $paragraph, flags: ENT_QUOTES);
        // Inline article links [Begriff](N) → anchor to the N-th source. The
        // term text is already escaped; the URL comes from our own article DB
        // and is re-escaped and scheme-gated, so this stays XSS-safe. Runs
        // BEFORE the bold upgrade so **…** inside the link text still applies.
        $escaped = (string) preg_replace_callback(
            pattern: '/\[([^\[\]]+)\]\((\d+)\)/',
            callback: function (array $m) use ($sourceUrls): string {
                $term = $m[1];
                $url = (string) ($sourceUrls[(int) $m[2] - 1] ?? '');
                if ($url === '' || preg_match(pattern: '~^https?://~i', subject: $url) !== 1) {
                    return $term;
                }
                return '<a href="' . htmlspecialchars(string: $url, flags: ENT_QUOTES)
                    . '" target="_blank" rel="noreferrer noopener" class="digest__ref">'
                    . $term
                    . '</a>';
            },
            subject: $escaped
        );
        $escaped = (string) preg_replace(
            pattern: '/\*\*(.+?)\*\*/s',
            replacement: '<strong>$1</strong>',
            subject: $escaped
        );
        return '<p>'
            . $escaped
            . ($sourceHtml !== '' ? '<span class="digest__sources">' . $sourceHtml . '</span>' : '')
            . '</p>';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchArticlesForDashboard(
        string $paperFilter,
        string $tvFilter,
        string $mediaFilter,
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
        // TV-Filter has precedence over the paper dropdown — picking "Alle
        // TV-Sendungen" expands to paper IN (<talkshowPapers>), picking a
        // single show narrows to that paper. The media tabs expand the same
        // way to their configured paper set.
        if ($tvFilter === 'all') {
            $tvPapers = $this->talkshowPapers();
            if ($tvPapers !== []) {
                $list = implode(separator: ',', array: array_map(
                    callback: fn(string $p): string => "'" . str_replace(search: "'", replace: "''", subject: $p) . "'",
                    array: $tvPapers
                ));
                $where[] = 'paper IN (' . $list . ')';
            }
        } elseif ($tvFilter !== '') {
            $where[] = 'paper = :paper';
            $params[':paper'] = $tvFilter;
        } elseif ($mediaFilter !== '' && isset($this->mediaTabs()[$mediaFilter])) {
            $list = implode(separator: ',', array: array_map(
                callback: fn(string $p): string => "'" . str_replace(search: "'", replace: "''", subject: $p) . "'",
                array: $this->mediaTabs()[$mediaFilter]['papers']
            ));
            $where[] = 'paper IN (' . $list . ')';
        } elseif ($paperFilter !== '') {
            $siblings = $this->paperDomainSiblings(paper: $paperFilter);
            $placeholders = [];
            foreach ($siblings as $i => $sibling) {
                $placeholders[] = ':paper' . $i;
                $params[':paper' . $i] = $sibling;
            }
            $where[] = 'paper IN (' . implode(separator: ',', array: $placeholders) . ')';
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

    /**
     * Full-text search across the whole archive. Only the title is stored as
     * text (article bodies are never persisted), so this matches anywhere in
     * the title, tokenised: every whitespace-separated term must occur (AND).
     * Unlike the dashboard list it ignores the read/duplicate/magic filters —
     * a search spans everything that was ever scraped. SQLite LIKE is
     * ASCII-case-insensitive, which covers the common case.
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchArticles(string $query): array
    {
        $tokens = preg_split(pattern: '/\s+/', subject: $query, limit: -1, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_slice(array: $tokens, offset: 0, length: 8);
        if ($tokens === []) {
            return [];
        }
        $where = [];
        $params = [];
        foreach ($tokens as $i => $token) {
            $where[] = 'title LIKE :q' . $i . " ESCAPE '\\'";
            // Escape LIKE wildcards so a literal % or _ in the query matches itself.
            $escaped = str_replace(search: ['\\', '%', '_'], replace: ['\\\\', '\\%', '\\_'], subject: (string) $token);
            $params[':q' . $i] = '%' . $escaped . '%';
        }
        $sql = 'SELECT url, paper, title, published_at, status, paywall, thumbnail, category, rating, read_at, vote
                FROM articles
                WHERE ' . implode(separator: ' AND ', array: $where) . '
                ORDER BY published_at DESC
                LIMIT 300';
        $stmt = $this->openDatabase()->prepare(query: $sql);
        $stmt->execute(params: $params);
        return $stmt->fetchAll(mode: PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Quick-Fact-Check: ask the strongest model on the configured endpoint
     * with the highest reasoning effort to verify a statement and return a
     * structured verdict. Bypasses the aihelper wrapper because aihelper's
     * enable_thinking parameter only reaches effort=medium; we want "high"
     * here so the model takes its time and reasons through the claim.
     *
     * @return array{verdict: string, percentage: int, confidence: int, explanation: string, sources: array<int, array{label: string, url: string}>}|null
     */
    private function runFactCheck(string $statement): ?array
    {
        $env = $this->loadEnv();
        $apiKey = (string) ($env['AI_API_KEY'] ?? '');
        $baseUrl = rtrim(string: (string) ($env['AI_BASE_URL'] ?? ''), characters: '/');
        if ($apiKey === '' || $baseUrl === '') {
            return null;
        }
        // gpt-5.5 is the strongest general model on the rebuhleiv endpoint;
        // "reasoning.effort=high" routes through the long-thinking path.
        // Prompt-Aufbau orientiert sich an etablierten Fact-Check-Skills
        // (petar-nauka/SKILL.md, dia browser, daymade, awesomeskill): Claim-
        // Zerlegung als Backbone, mehrstufige Quellen-Hierarchie mit
        // Triangulation, CRAAP-Check, Red-Flag-Taxonomie, adversarial
        // review, transparente Confidence-Kalibrierung, sauberes Verdict-
        // Mapping vom internen 6-Punkte-Schema auf das 3-Punkte-Output.
        $prompt = "Du bist ein methodisch arbeitender, nüchterner Fact-Checker. Prüfe die folgende Aussage gründlich nach dem unten beschriebenen Verfahren.\n\n" .
            "AUSSAGE: \"" . $statement . "\"\n\n" .

            "===== VERFAHREN (intern anwenden, NICHT im Output erwähnen) =====\n\n" .

            "(1) CLAIM-ZERLEGUNG\n" .
            "Zerlege die Aussage in einzelne überprüfbare Teil-Behauptungen. Klassifiziere jede:\n" .
            "- F = Faktische Behauptung (überprüfbar)\n" .
            "- S = Statistische Behauptung (Zahlen — extra prüfen: Kontext, Bezugsgröße, Zeitraum, Methodik, absolut vs. relativ)\n" .
            "- I = Implizierte Behauptung (nicht direkt gesagt, aber stark suggeriert — z. B. durch Framing, Reihenfolge, Andeutung)\n" .
            "- O = Meinung / Werturteil (nicht prüfbar)\n" .
            "- P = Prognose / Zukunftsaussage (nicht prüfbar)\n" .
            "- U = Vage / nicht falsifizierbar\n" .
            "Bewerte JEDE Teil-Behauptung einzeln, dann aggregiere zur Gesamtbewertung. Wichtig: Desinformation ist selten 100 % falsch — sie mischt Wahres mit Falschem. Ein pauschales \"stimmt nicht\" für eine Aussage, die einen korrekten und einen falschen Teil enthält, ist ebenso problematisch wie ein pauschales \"stimmt\".\n\n" .

            "(2) QUELLEN-TRIANGULATION\n" .
            "Für jede F- oder S-Teil-Behauptung: mindestens **3 unabhängige Belege aus Tier 1-4**. Quellen gelten als unabhängig, wenn sie nicht voneinander zitieren oder dieselbe Pressemitteilung wiedergeben. Bei nur 1-2 belastbaren Belegen → confidence reduzieren.\n\n" .

            "(3) QUELLEN-HIERARCHIE (höchste → niedrigste Verlässlichkeit)\n" .
            "- Tier 1 — Fact-Check-Organisationen (IFCN-zertifiziert): Correctiv, dpa-Faktencheck, ARD Faktenfinder, AFP Fact Check, Snopes, PolitiFact, Full Fact, FactCheck.org, EUvsDisinfo\n" .
            "- Tier 2 — Offizielle Institutionen: WHO, CDC, RKI, EMA, ECDC, Eurostat, Destatis, Bundesregierung, EU-Kommission, Bundesbank, BVerfG, OECD, IPCC\n" .
            "- Tier 3 — Wire-Services & Qualitätsjournalismus: Reuters, AP, dpa, BBC, Tagesschau, FAZ, Süddeutsche, Zeit, Spiegel, Handelsblatt, NYT, Guardian, Economist\n" .
            "- Tier 4 — Peer-reviewed Journals & Forschungsdatenbanken: PubMed, Cochrane Reviews, Nature, Science, The Lancet, NEJM, Google Scholar, arXiv (mit Vorsicht: Preprint ≠ peer-reviewed)\n" .
            "- Tier 5 — Fachorganisationen / Universitäten: Max-Planck-Institute, Helmholtz, Leibniz, IAEA, ITU, Universitäts-Publikationen\n" .
            "- Tier 6 — Etablierte Regional- oder Online-Medien mit Redaktion (z. B. heise, golem, t3n für Tech; PNP, RP-Online für regional)\n" .
            "- Tier 7 — Blogs, persönliche Sites, Social Media — nur ergänzend, nie als alleiniger Beleg\n" .
            "- Tier 8 — Anonyme oder unzuschreibbare Quellen — nicht verwenden\n" .
            "Mische PRIMÄRQUELLEN (Originaldokumente, Studien, Gesetzestexte, Statistik-Originale) UND SEKUNDÄRQUELLEN (journalistische Aufbereitung). Primärquellen bevorzugt.\n\n" .

            "(4) CRAAP-CHECK pro Quelle\n" .
            "- Currency — Aktualität: passt der Zeitstand zur Behauptung?\n" .
            "- Relevance — Adressiert die Quelle die Behauptung direkt?\n" .
            "- Authority — Wer ist Autor/Herausgeber? Qualifikation im richtigen Fachgebiet?\n" .
            "- Accuracy — Werden Primärquellen zitiert? Sind Zahlen mit Methodik unterlegt?\n" .
            "- Purpose — Information vs. Persuasion vs. Werbung vs. Satire?\n\n" .

            "(5) RED-FLAG-ERKENNUNG (auf Manipulationsmarker achten)\n" .
            "- Cherry-picking — selektive Daten ohne Kontext oder Vergleichszeitraum\n" .
            "- False dichotomy / Strohmann / Whataboutism / Slippery Slope\n" .
            "- Korrelation als Kausalität präsentiert (post hoc ergo propter hoc)\n" .
            "- Relative vs. absolute Prozent (\"100 % mehr Risiko\" ohne Basisrate)\n" .
            "- Veraltete Daten als aktuell präsentiert; Zahlen aus anderem Land/Kontext übernommen\n" .
            "- Aus dem Kontext gerissene Zitate; selektive Zitierung von Studien\n" .
            "- Fehlattribuierte Studien (\"Studie zeigt\" ohne Beleg, oder Studie sagt das Gegenteil des Behaupteten)\n" .
            "- Appeal to false authority (Experte spricht außerhalb seines Fachgebiets)\n" .
            "- Naturalistic Fallacy (\"natürlich = gut\", \"chemisch = böse\")\n" .
            "- Anekdoten als Beleg für allgemeine Trends (n=1 → Verallgemeinerung)\n\n" .

            "(6) ADVERSARIAL REVIEW\n" .
            "Bevor du das Verdict festschreibst: Argumentiere intern aus der Gegenposition. Was würde ein kritischer Reviewer einwenden? Hat die Aussage einen Kern, der korrekt sein könnte, auch wenn das Framing schief ist? Wenn ja → eher \"teilweise\" statt \"stimmt nicht\".\n\n" .

            "(7) CONFIDENCE-KALIBRIERUNG\n" .
            "- HOCH (80-100): ≥3 unabhängige Tier-1-2-Quellen stimmen überein; offizielle Daten belegen direkt; keine wesentlichen Restunsicherheiten.\n" .
            "- MITTEL (50-79): nur 1-2 belastbare Quellen; Sekundärquellen dominieren; kleine Diskrepanzen zwischen Quellen; Spezialgebiet, das du nur grob abdeckst.\n" .
            "- NIEDRIG (0-49): Behauptung betrifft Zeitraum nach deinem Trainings-Wissensstand; Quellen widersprechen sich erheblich; ausschließlich Sekundärquellen ohne Primärbeleg; Behauptung schwer falsifizierbar; technisch komplexes Spezialgebiet außerhalb deiner Kernkompetenz.\n\n" .

            "===== OUTPUT =====\n\n" .

            "Antworte AUSSCHLIESSLICH mit gültigem JSON, kein Markdown-Codeblock, keine Vorrede:\n\n" .
            "{\n" .
            "  \"verdict\": \"stimmt\" | \"teilweise\" | \"stimmt nicht\",\n" .
            "  \"percentage\": <Ganze Zahl 0-100, wieviel Prozent der Aussage faktisch korrekt ist>,\n" .
            "  \"confidence\": <Ganze Zahl 0-100, wie sicher du bei dieser Bewertung bist>,\n" .
            "  \"explanation\": \"<8-14 Sätze in 3-4 Absätzen, Absätze durch \\n\\n getrennt>\",\n" .
            "  \"sources\": [ {\"label\": \"<Quellenname mit Kontext>\", \"url\": \"https://...\"}, ... ]\n" .
            "}\n\n" .

            "VERDICT-MAPPING (intern 6-stufig, gibst 3 aus):\n" .
            "- \"stimmt\" = CONFIRMED (alle Teil-Behauptungen unabhängig belegt) oder MOSTLY TRUE (Kernaussage korrekt, Details unpräzise) → percentage 75-100\n" .
            "- \"teilweise\" = MIXED (Wahres mit Falschem gemischt), UNVERIFIED (mit verfügbaren Belegen nicht entscheidbar) oder MISLEADING (irreführend gerahmt trotz korrekter Einzel-Fakten) → percentage 25-74\n" .
            "- \"stimmt nicht\" = FALSE (durch belastbare Belege direkt widerlegt) → percentage 0-24\n\n" .

            "EXPLANATION — Aufbau:\n" .
            "- Absatz 1: Verdict + die entscheidenden Fakten/Zahlen, die es tragen.\n" .
            "- Absatz 2: Historischer/fachlicher Kontext (warum die Behauptung in dieser Form kursiert, welcher Konsens herrscht).\n" .
            "- Absatz 3: Welche Teil-Behauptungen stimmen, welche nicht — jeweils kurz begründet. Bei \"teilweise\": die Mischung ausführlich erklären.\n" .
            "- Absatz 4 (optional): Confidence-Faktoren (was hebt/senkt die Sicherheit), Restunsicherheiten, zeitlicher Kontext (\"Stand 2025...\", \"frühere Daten lauteten...\").\n" .
            "Sprachliche Vorgaben:\n" .
            "- Sachlich, ohne Floskeln, ohne moralische Wertung, ohne rhetorische Fragen.\n" .
            "- Numerische Präzision an der Quelle ausrichten (\"etwa 1,2 Mio.\" wenn Quelle exakter ist; nicht \"ungefähr\" verwenden, wenn Originalwert bekannt).\n" .
            "- Datums-sensitive Aussagen mit Stand kennzeichnen.\n" .
            "- Hebe 5-10 Schlüsselbegriffe und Zahlen mit **doppelten Sternchen** als Markdown-Bold hervor.\n\n" .

            "SOURCES — Regeln:\n" .
            "- 5 bis 8 Quellen, möglichst aus Tier 1-4, gemischt aus Primär- und Sekundärquellen.\n" .
            "- Mindestens 3 müssen unabhängig die Bewertung stützen (Triangulation).\n" .
            "- Bei Statistik-Behauptungen: mindestens 1 Tier-2-Quelle (offizielles Statistik-Amt / Behörde).\n" .
            "- Bei medizinischen Behauptungen: mindestens 1 Tier-2-Quelle (WHO, RKI, EMA o. ä.) UND 1 Tier-4-Quelle (peer-reviewed).\n" .
            "- KEINE Quellen erfinden. Bei Unsicherheit über die exakte URL: NUR Label angeben, \"url\" leer (\"\") lassen.\n" .
            "- Label sollte aussagekräftig sein (z. B. \"Destatis — Pressemitteilung Nr. 384/2024 vom 09.10.2024\" statt nur \"Destatis\").\n\n" .

            "EDGE CASES:\n" .
            "- Aussage betrifft Ereignis nach Trainings-Wissensstand → verdict \"teilweise\", confidence niedrig, in Absatz 4 explizit erwähnen.\n" .
            "- Aussage ist im Kern eine Meinung/Wertung → verdict \"teilweise\", erkläre, dass es keine Tatsachenbehauptung ist, prüfe etwaige eingebettete Tatsachenkomponenten separat.\n" .
            "- Aussage ist mehrdeutig/vage → verdict \"teilweise\", erkläre die Mehrdeutigkeit, prüfe die plausibelste Lesart.\n" .
            "- Aussage enthält veraltete Zahlen, die zum damaligen Zeitpunkt korrekt waren → verdict \"teilweise\" mit erklärtem Zeitkontext.\n" .
            "- Aussage ist statistisch korrekt, aber irreführend gerahmt (\"100 % korrekt zitierte Studie zu falschem Kontext\") → verdict \"teilweise\" oder \"stimmt nicht\", explizit als MISLEADING erklären.\n\n" .

            "ANTI-HALLUZINATION:\n" .
            "- Bei Statistiken: niemals exakte Zahlen erfinden. Bandbreite oder \"in der Größenordnung von\" wenn unsicher.\n" .
            "- Bei Studien: lieber \"mehrere Reviews zeigen\" als eine erfundene Einzelstudie nennen.\n" .
            "- Bei URLs: nur die, bei denen du mit hoher Sicherheit weißt, dass sie existieren. Im Zweifel Label-only ohne URL.\n" .
            "- Wenn du den Sachverhalt nicht belegen kannst: verdict \"teilweise\" mit niedriger confidence statt geraten.\n\n" .

            "WICHTIG: Schreibe nicht \"Tier 1\", \"CRAAP\", \"F/S/I/O/P/U\" oder \"adversarial review\" in den Output — die Methode ist nur interne Denkstruktur. Im Explanation-Text fliesst die Bewertung als natürlicher Fachtext.";

        $body = json_encode(value: [
            'model' => 'gpt-5.5',
            'input' => $prompt,
            'reasoning' => ['effort' => 'high'],
            'max_output_tokens' => 12000,
        ], flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return null;
        }

        $ch = curl_init();
        curl_setopt_array(handle: $ch, options: [
            CURLOPT_URL => $baseUrl . '/responses',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
        ]);
        $resp = curl_exec(handle: $ch);
        $httpCode = (int) curl_getinfo(handle: $ch, option: CURLINFO_HTTP_CODE);
        if (!is_string(value: $resp) || $httpCode !== 200) {
            return null;
        }
        $decoded = json_decode(json: $resp, associative: true);
        if (!is_array(value: $decoded)) {
            return null;
        }
        // Responses API: output[*].content[*].text holds the model reply.
        $text = '';
        foreach ((array) ($decoded['output'] ?? []) as $node) {
            foreach ((array) ($node['content'] ?? []) as $c) {
                if (($c['type'] ?? '') === 'output_text' && isset($c['text']) && is_string($c['text'])) {
                    $text .= $c['text'];
                }
            }
        }
        $text = trim(string: $text);
        $text = (string) preg_replace(pattern: '~^\s*```(?:json)?\s*|\s*```\s*$~i', replacement: '', subject: $text);
        $parsed = json_decode(json: $text, associative: true);
        if (!is_array(value: $parsed)) {
            return null;
        }
        $verdict = (string) ($parsed['verdict'] ?? 'teilweise');
        if (!in_array(needle: $verdict, haystack: ['stimmt', 'teilweise', 'stimmt nicht'], strict: true)) {
            $verdict = 'teilweise';
        }
        $percentage = max(0, min(100, (int) ($parsed['percentage'] ?? 0)));
        $confidence = max(0, min(100, (int) ($parsed['confidence'] ?? 0)));
        $explanation = trim(string: (string) ($parsed['explanation'] ?? ''));
        if ($explanation === '') {
            return null;
        }
        $sources = [];
        foreach ((array) ($parsed['sources'] ?? []) as $src) {
            if (!is_array(value: $src)) {
                continue;
            }
            $label = trim(string: (string) ($src['label'] ?? ''));
            $url = trim(string: (string) ($src['url'] ?? ''));
            if ($label === '') {
                continue;
            }
            $sources[] = ['label' => $label, 'url' => $url];
        }
        return [
            'verdict' => $verdict,
            'percentage' => $percentage,
            'confidence' => $confidence,
            'explanation' => $explanation,
            'sources' => $sources,
        ];
    }

    private function renderDashboard(
        string $paperFilter,
        string $tvFilter,
        string $mediaFilter,
        string $statusFilter,
        string $paywallFilter,
        string $categoryFilter,
        string $readFilter,
        string $sortFilter,
        string $magicFilter,
        string $thumbFilter,
        string $viewFilter,
        string $factcheckStatement = '',
        bool $factcheckPending = false,
        string $searchQuery = ''
    ): string {
        // Search mode short-circuits the normal filtered/magic list: it scans
        // the whole archive (read, duplicates and all) by title and shows the
        // matches in place of the tab content.
        $isSearch = $searchQuery !== '';
        $articles = $isSearch
            ? $this->searchArticles(query: $searchQuery)
            : $this->fetchArticlesForDashboard(
                paperFilter: $paperFilter,
                tvFilter: $tvFilter,
                mediaFilter: $mediaFilter,
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
        // Hide the talk-show papers from the news-source dropdown — they get
        // their own "TV" dropdown right next to it. Talk-show papers all
        // share ardmediathek.de / zdf.de domains and would otherwise show
        // up here as visually identical duplicate entries.
        $tvPaperConfig = array_intersect_key(
            $paperList,
            array_flip($this->talkshowPapers())
        );
        $paperList = array_diff_key($paperList, $tvPaperConfig);
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
        // Papers sharing a domain (e.g. the two Medium tag feeds) collapse
        // into one option; the filter expands to all siblings via
        // paperDomainSiblings().
        $domainPapers = [];
        foreach ($paperList as $key => $info) {
            $domainPapers[$info['domain']][] = (string) $key;
        }
        $paperOptions = '<option value="">Quelle</option>';
        foreach ($domainPapers as $domain => $domainKeys) {
            $escapedKey = htmlspecialchars(string: $domainKeys[0], flags: ENT_QUOTES);
            $escapedDomain = htmlspecialchars(string: (string) $domain, flags: ENT_QUOTES);
            $sel = in_array(needle: $paperFilter, haystack: $domainKeys, strict: true) ? ' selected' : '';
            $paperOptions .= '<option value="' . $escapedKey . '"' . $sel . '>' . $escapedDomain . '</option>';
        }

        // TV-Dropdown: "TV" placeholder, "Alle TV-Sendungen" (sentinel "all"),
        // then one option per configured talkshow paper sorted by label.
        $tvOptions = '<option value="">TV</option>';
        if ($tvPaperConfig !== []) {
            $allSel = $tvFilter === 'all' ? ' selected' : '';
            $tvOptions .= '<option value="all"' . $allSel . '>Alle TV-Sendungen</option>';
            uasort(
                array: $tvPaperConfig,
                callback: fn(array $a, array $b): int => strcasecmp(string1: (string) ($a['label'] ?? ''), string2: (string) ($b['label'] ?? ''))
            );
            foreach ($tvPaperConfig as $key => $info) {
                $escapedKey = htmlspecialchars(string: (string) $key, flags: ENT_QUOTES);
                $escapedLabel = htmlspecialchars(string: (string) ($info['label'] ?? $key), flags: ENT_QUOTES);
                $sel = $tvFilter === $key ? ' selected' : '';
                $tvOptions .= '<option value="' . $escapedKey . '"' . $sel . '>' . $escapedLabel . '</option>';
            }
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
        foreach (['' => 'Lesen', 'unread' => 'ungelesen', 'read' => 'gelesen'] as $value => $label) {
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

        $categoryOptions = '<option value="">Kat.</option>';
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
                        htmlspecialchars(string: date(format: 'd.m.Y H:i', timestamp: $publishedAt), flags: ENT_QUOTES) .
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
        // Only show a filtered article count when the user actually narrowed
        // the result set. Without any filter the global total (excluding
        // dupes) is shown, so the header reflects the real archive size and
        // not just the magic/unread default slice.
        $isFiltered = $paperFilter !== '' || $tvFilter !== '' || $mediaFilter !== '' || $statusFilter !== '' || $paywallFilter !== ''
            || $categoryFilter !== '' || $readFilter !== '' || $magicFilter !== '' || $thumbFilter !== '';
        if ($isFiltered) {
            $displayCount = $count;
        } else {
            $displayCount = (int) $this->openDatabase()
                ->query(query: 'SELECT COUNT(*) FROM articles WHERE duplicate_of IS NULL')
                ->fetchColumn();
        }
        $countLabel = htmlspecialchars(string: $displayCount . ' Artikel', flags: ENT_QUOTES);

        $digestHtml = $this->renderDigestHtml();

        // Tab toggle: "zeitung" shows only the textual digest, "meldungen"
        // shows the classic filter form + list. Filter form carries the
        // view in a hidden input so submitting a filter preserves the tab.
        // Search mode suppresses all tab content (no tab highlighted) and
        // renders the result list instead.
        $isZeitung = !$isSearch && $viewFilter === 'zeitung';
        $isFactcheck = !$isSearch && $viewFilter === 'factcheck';
        $isBild = !$isSearch && $viewFilter === 'bild';
        // "Talk-Shows" tab is active when the meldungen view is showing the
        // tv=all preset, the media tabs when media=<tab> is set; "Meldungen"
        // covers every other meldungen state.
        $isTalkshowView = !$isSearch && !$isZeitung && !$isFactcheck && !$isBild && $tvFilter === 'all';
        $isMediaView = !$isSearch && !$isZeitung && !$isFactcheck && !$isBild && !$isTalkshowView && $mediaFilter !== '';
        $isMeldungenView = !$isSearch && !$isZeitung && !$isFactcheck && !$isBild && !$isTalkshowView && !$isMediaView;
        $zeitungActive = $isZeitung ? ' viewnav__tab--active' : '';
        $meldungenActive = $isMeldungenView ? ' viewnav__tab--active' : '';
        $talkshowActive = $isTalkshowView ? ' viewnav__tab--active' : '';
        $factcheckActive = $isFactcheck ? ' viewnav__tab--active' : '';
        $bildActive = $isBild ? ' viewnav__tab--active' : '';
        $mediaTabsHtml = '';
        foreach ($this->mediaTabs() as $tabKey => $tab) {
            $mediaActive = $isMediaView && $mediaFilter === $tabKey ? ' viewnav__tab--active' : '';
            $mediaTabsHtml .= '<a class="viewnav__tab' . $mediaActive . '" href="/?view=' . $tabKey . '">' . $tab['label'] . '</a>';
        }
        $zeitungBlock = $isZeitung ? ($digestHtml !== '' ? $digestHtml : '<p class="viewnav__empty">Noch keine Zeitung verfügbar – beim nächsten Scrape wird sie erzeugt.</p>') : '';
        // Keep the active media preset when the user submits another filter —
        // the tv preset persists via its own dropdown, media has no dropdown.
        $mediaHidden = $mediaFilter !== ''
            ? '<input type="hidden" name="media" value="' . htmlspecialchars(string: $mediaFilter, flags: ENT_QUOTES) . '">'
            : '';
        // onchange="filterChange(this)" auto-flips Magisch → "Alle" when the
        // user picks a single filter on an otherwise empty form. See the
        // <script> at the bottom of the template.
        $meldungenBlock = $isMeldungenView || $isTalkshowView || $isMediaView ? <<<HTML
                <form class="filters" method="get" action="/">
                    <input type="hidden" name="view" value="meldungen">{$mediaHidden}
                    <select name="paper" onchange="filterChange(this)">{$paperOptions}</select>
                    <select name="tv" onchange="filterChange(this)">{$tvOptions}</select>
                    <select name="status" onchange="filterChange(this)">{$statusOptions}</select>
                    <select name="paywall" onchange="filterChange(this)">{$paywallOptions}</select>
                    <select name="thumb" onchange="filterChange(this)">{$thumbOptions}</select>
                    <select name="category" onchange="filterChange(this)">{$categoryOptions}</select>
                    <select name="read" onchange="filterChange(this)">{$readOptions}</select>
                    <select name="magic" onchange="this.form.submit()">{$magicOptions}</select>
                    <select name="sort" onchange="this.form.submit()">{$sortDropdown}</select>
                </form>
                <ul class="items">{$rows}</ul>
HTML : '';
        $factcheckBlock = $isFactcheck ? $this->buildFactCheckBlock(statement: $factcheckStatement, pending: $factcheckPending) : '';
        $bildBlock = $isBild ? $this->buildBildBlock() : '';

        $searchValue = htmlspecialchars(string: $searchQuery, flags: ENT_QUOTES);
        $searchBlock = '';
        if ($isSearch) {
            $hits = count(value: $articles);
            $heading = '<h2 class="search__title">Suchergebnisse für „'
                . htmlspecialchars(string: $searchQuery, flags: ENT_QUOTES)
                . '" <span class="search__count">' . $hits . ' Treffer</span></h2>';
            $list = $hits > 0
                ? '<ul class="items">' . $rows . '</ul>'
                : '<p class="viewnav__empty">Keine Treffer.</p>';
            $searchBlock = '<section class="search-results">' . $heading . $list . '</section>';
        }

        // Last scrape timestamp via mtime of scrape.log (truncated at scrape
        // start, appended throughout — mtime tracks the most recent emit).
        $scrapeLogFile = $this->logDir . '/scrape.log';
        $lastScrapeLabel = '';
        if (is_file(filename: $scrapeLogFile)) {
            $ts = (int) @filemtime(filename: $scrapeLogFile);
            if ($ts > 0) {
                $lastScrapeLabel = htmlspecialchars(
                    string: date(format: 'd.m.Y H:i', timestamp: $ts),
                    flags: ENT_QUOTES
                );
            }
        }

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
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lora:wght@400;500;700&display=swap">
            {$pwa}
            {$prerenderTag}
            <script>
                // Pre-paint theme switch: read localStorage and apply before
                // <body> renders to avoid a light-to-dark flash on dark users.
                try { if (localStorage.getItem('theme') === 'dark') { document.documentElement.setAttribute('data-theme', 'dark'); } } catch (e) {}
            </script>
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
                header.top .last-scrape { font: 500 12px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: #a1a1aa; }
                header.top .scrape-link { font: 600 12px/1 system-ui, sans-serif; text-decoration: none; background: #18181b; color: #fff; padding: 8px 12px; border-radius: 6px; }
                header.top .scrape-link:hover { background: #3f3f46; }
                header.top .search { margin: 0; flex: 1 1 140px; min-width: 120px; }
                header.top .search input { font: 500 13px/1 system-ui, sans-serif; background: #fff; color: #18181b; padding: 8px 12px; border-radius: 6px; border: 1px solid #d4d4d8; width: 100%; box-sizing: border-box; }
                header.top .search input:focus { outline: none; border-color: #18181b; }
                section.search-results { margin-top: 0.5rem; }
                .search__title { font: 700 16px/1.3 system-ui, sans-serif; color: #18181b; margin: 0 0 0.8rem; }
                .search__count { font-weight: 400; color: #a1a1aa; letter-spacing: 0.02em; margin-left: 0.4em; }
                nav.viewnav { display: flex; gap: 0; margin: 0 0 1rem; border-bottom: 1px solid #d4d4d8; }
                nav.viewnav .viewnav__tab { font: 600 13px/1 system-ui, sans-serif; color: #71717a; text-decoration: none; padding: 10px 14px; border-bottom: 2px solid transparent; margin-bottom: -1px; letter-spacing: 0.02em; }
                nav.viewnav .viewnav__tab:hover { color: #18181b; }
                nav.viewnav .viewnav__tab--active { color: #18181b; border-bottom-color: #18181b; }
                .viewnav__empty { font: 500 13px/1.5 system-ui, sans-serif; color: #71717a; padding: 1.2rem 0; margin: 0; }
                form.filters { display: grid; grid-template-columns: repeat(9, minmax(0, 1fr)); gap: 4px; margin: 0 0 1rem; }
                form.filters select { min-width: 0; width: 100%; font: 600 11px/1 system-ui, sans-serif; color: #18181b; background: #fff; border: 1px solid #d4d4d8; padding: 7px 18px 7px 7px; border-radius: 6px; cursor: pointer; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8'><path fill='%2371717a' d='M6 8 0 0h12z'/></svg>"); background-repeat: no-repeat; background-position: right 5px center; background-size: 7px 4px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; }
                form.filters select:hover { border-color: #71717a; }
                form.filters select:focus { outline: none; border-color: #18181b; }
                .digest { font-family: 'Lora', Georgia, 'Times New Roman', Times, serif; font-size: 16px; line-height: 1.6; color: #27272a; margin: 0 0 1.2rem; padding: 1.1rem 1.4rem 1.2rem; background: #fdfcf8; border: 1px solid #e7e5e0; border-left: 3px solid #18181b; border-radius: 4px; box-shadow: 0 1px 0 rgba(0,0,0,0.02); }
                .digest__title { font-family: 'Lora', Georgia, serif; font-size: 11px; font-weight: 700; letter-spacing: 0.18em; text-transform: uppercase; color: #71717a; margin: 0 0 0.9rem; padding-bottom: 0.55rem; border-bottom: 1px solid #e7e5e0; }
                .digest__date { font-weight: 400; color: #a1a1aa; letter-spacing: 0.1em; }
                .digest p { margin: 0 0 0.7rem; text-align: justify; hyphens: auto; }
                .digest p:last-child { margin-bottom: 0; }
                .digest strong { font-weight: 700; color: #18181b; }
                .digest__ref { color: inherit; text-decoration: none; background-image: linear-gradient(#c7bda6, #c7bda6); background-repeat: no-repeat; background-position: 0 100%; background-size: 100% 1px; transition: background-size 0.15s, color 0.15s; }
                .digest__ref:hover { color: #18181b; background-image: linear-gradient(#18181b, #18181b); background-size: 100% 1.5px; }
                .digest__lead { margin: 0 0 1.3rem; padding-bottom: 1.2rem; border-bottom: 1px solid #d4d4d8; }
                .digest__lead p { font-size: 18px; line-height: 1.55; color: #18181b; }
                .digest__title--lead { color: #18181b; }
                .digest__sources { display: block; font-family: system-ui, sans-serif; font-size: 10.5px; color: #a1a1aa; letter-spacing: 0.02em; margin-top: 0.25rem; }
                .digest__sources a { color: #a1a1aa; text-decoration: none; margin: 0 8px 0 0; display: inline-block; }
                .digest__sources a:last-child { margin-right: 0; }
                .digest__sources a:hover { color: #18181b; text-decoration: underline; }
                .digest__weather { margin-top: 1.3rem; padding-top: 1.2rem; border-top: 1px solid #d4d4d8; }
                .digest__weather p { font-size: 15px; color: #3f3f46; margin: 0; }
                .digest__tv { margin-top: 1.3rem; padding-top: 1.2rem; border-top: 1px solid #d4d4d8; }
                .digest__tv p { font-size: 15px; color: #3f3f46; margin: 0; }
                .bild__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
                .bild__tile { display: flex; flex-direction: column; background: #fff; border: 1px solid #e4e4e7; border-radius: 8px; overflow: hidden; text-decoration: none; color: #18181b; transition: transform 0.12s, box-shadow 0.12s; }
                .bild__tile:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,0.12); }
                .bild__tile--lead { grid-column: 1 / -1; }
                .bild__img { width: 100%; height: auto; display: block; background: #e4e4e7; }
                .bild__text { padding: 10px 12px 12px; font-size: 15px; line-height: 1.35; }
                .bild__kicker { color: #dd0000; font-weight: 700; }
                .bild__headline { font-weight: 700; }
                section.factcheck { margin: 1.5rem 0; font-family: Lora, Georgia, "Times New Roman", serif; }
                .factcheck__form { display: flex; gap: 8px; margin-bottom: 1.3rem; }
                .factcheck__form input[type="text"] { flex: 1; min-width: 0; font: 500 16px/1.3 system-ui, sans-serif; padding: 10px 12px; border: 1px solid #d4d4d8; border-radius: 6px; background: #fff; color: #18181b; }
                .factcheck__form input[type="text"]:focus { outline: none; border-color: #18181b; }
                .factcheck__form button { font: 700 13px/1 system-ui, sans-serif; padding: 10px 18px; background: #18181b; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
                .factcheck__form button:hover { background: #3f3f46; }
                .factcheck__hint { font: 500 14px/1.6 system-ui, sans-serif; color: #71717a; margin: 2.5rem 0; text-align: justify; hyphens: auto; }
                .factcheck__loading { display: flex; flex-direction: column; align-items: stretch; gap: 56px; padding: 40px 0; }
                .factcheck__loading .factcheck__spinner { align-self: center; }
                .factcheck__spinner { width: 32px; height: 32px; border: 3px solid #e4e4e7; border-top-color: #18181b; border-radius: 50%; animation: factcheck-spin 0.8s linear infinite; }
                @keyframes factcheck-spin { to { transform: rotate(360deg); } }
                .factcheck__loading .factcheck__hint { margin: 0; }
                .factcheck__prose + .factcheck__prose { margin-top: 0.7rem; }
                .factcheck__bar { position: relative; height: 28px; background: #f4f4f5; border: 1px solid #e4e4e7; border-radius: 6px; overflow: hidden; margin-bottom: 0.6rem; }
                .factcheck__bar-fill { height: 100%; transition: width 0.4s ease; }
                .factcheck__bar--true .factcheck__bar-fill { background: linear-gradient(90deg, #86efac, #16a34a); }
                .factcheck__bar--false .factcheck__bar-fill { background: linear-gradient(90deg, #fca5a5, #dc2626); }
                .factcheck__bar--partial .factcheck__bar-fill { background: linear-gradient(90deg, #fde68a, #ca8a04); }
                .factcheck__bar-label { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font: 700 13px/1 system-ui, sans-serif; letter-spacing: 0.02em; color: #18181b; text-shadow: 0 0 4px rgba(255,255,255,0.6); }
                .factcheck__meta { font: 500 12px/1.4 system-ui, sans-serif; color: #71717a; margin-bottom: 1rem; }
                .factcheck__meta strong { color: #18181b; }
                .factcheck__prose { font-size: 16px; line-height: 1.55; color: #18181b; margin: 0 0 0.8rem; text-align: justify; hyphens: auto; }
                .factcheck__sources { display: flex; flex-direction: column; gap: 4px; margin-top: 1rem; font: 500 12.5px/1.4 system-ui, sans-serif; color: #a1a1aa; letter-spacing: 0.02em; }
                .factcheck__sources a, .factcheck__sources span { color: #71717a; text-decoration: none; display: block; }
                .factcheck__sources a:hover { color: #18181b; text-decoration: underline; }
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
                .theme-toggle { background: transparent; border: 0; padding: 4px 6px; cursor: pointer; line-height: 0; color: #71717a; opacity: 0.8; display: inline-flex; align-items: center; transform: translateY(3px); }
                .theme-toggle:hover { opacity: 1; color: #18181b; }
                html[data-theme="dark"] .theme-toggle { color: #a1a1aa; }
                html[data-theme="dark"] .theme-toggle:hover { color: #e4e4e7; }
                html[data-theme="dark"] { color-scheme: dark; }
                html[data-theme="dark"] body { background: #18181b; color: #e4e4e7; }
                html[data-theme="dark"] .item { border-color: #3f3f46; }
                html[data-theme="dark"] .item__swipe { background: #27272a; }
                html[data-theme="dark"] .item__link { color: #e4e4e7; }
                html[data-theme="dark"] .item__thumb { background: #3f3f46; }
                html[data-theme="dark"] .item__thumb--empty { background: linear-gradient(135deg,#3f3f46,#52525b); }
                html[data-theme="dark"] .item--empty { background: #27272a; color: #a1a1aa; }
                html[data-theme="dark"] .item--empty a { color: #e4e4e7; }
                html[data-theme="dark"] header.top h1 a { color: #e4e4e7; }
                html[data-theme="dark"] header.top .search input { background: #27272a; color: #e4e4e7; border-color: #3f3f46; }
                html[data-theme="dark"] header.top .search input:focus { border-color: #a1a1aa; }
                html[data-theme="dark"] .search__title { color: #e4e4e7; }
                html[data-theme="dark"] .search__count { color: #71717a; }
                html[data-theme="dark"] nav.viewnav { border-bottom-color: #3f3f46; }
                html[data-theme="dark"] nav.viewnav .viewnav__tab { color: #a1a1aa; }
                html[data-theme="dark"] nav.viewnav .viewnav__tab:hover { color: #e4e4e7; }
                html[data-theme="dark"] nav.viewnav .viewnav__tab--active { color: #e4e4e7; border-bottom-color: #e4e4e7; }
                html[data-theme="dark"] .viewnav__empty { color: #a1a1aa; }
                html[data-theme="dark"] form.filters select { background-color: #27272a; color: #e4e4e7; border-color: #3f3f46; }
                html[data-theme="dark"] form.filters select:hover { border-color: #71717a; }
                html[data-theme="dark"] form.filters select:focus { border-color: #a1a1aa; }
                html[data-theme="dark"] .digest { background: #1f1f22; color: #d4d4d8; border-color: #3f3f46; border-left-color: #e4e4e7; box-shadow: none; }
                html[data-theme="dark"] .digest__title { color: #a1a1aa; border-bottom-color: #3f3f46; }
                html[data-theme="dark"] .digest__date { color: #71717a; }
                html[data-theme="dark"] .digest strong { color: #fafafa; }
                html[data-theme="dark"] .digest__ref { background-image: linear-gradient(#5c5546, #5c5546); }
                html[data-theme="dark"] .digest__ref:hover { color: #fafafa; background-image: linear-gradient(#fafafa, #fafafa); }
                html[data-theme="dark"] .digest__lead { border-bottom-color: #3f3f46; }
                html[data-theme="dark"] .digest__lead p { color: #fafafa; }
                html[data-theme="dark"] .digest__title--lead { color: #e4e4e7; }
                html[data-theme="dark"] .digest__sources { color: #71717a; }
                html[data-theme="dark"] .digest__sources a { color: #71717a; }
                html[data-theme="dark"] .digest__sources a:hover { color: #e4e4e7; }
                html[data-theme="dark"] .digest__weather { border-top-color: #3f3f46; }
                html[data-theme="dark"] .digest__weather p { color: #d4d4d8; }
                html[data-theme="dark"] .digest__tv { border-top-color: #3f3f46; }
                html[data-theme="dark"] .digest__tv p { color: #d4d4d8; }
                html[data-theme="dark"] .bild__tile { background: #18181b; border-color: #3f3f46; color: #fafafa; }
                html[data-theme="dark"] .bild__tile:hover { box-shadow: 0 6px 18px rgba(0,0,0,0.5); }
                html[data-theme="dark"] .bild__img { background: #27272a; }
                html[data-theme="dark"] .bild__kicker { color: #f87171; }
                html[data-theme="dark"] .factcheck__form input[type="text"] { background: #27272a; color: #e4e4e7; border-color: #3f3f46; }
                html[data-theme="dark"] .factcheck__form input[type="text"]:focus { border-color: #a1a1aa; }
                html[data-theme="dark"] .factcheck__form button { background: #e4e4e7; color: #18181b; }
                html[data-theme="dark"] .factcheck__form button:hover { background: #fafafa; }
                html[data-theme="dark"] .factcheck__hint { color: #a1a1aa; }
                html[data-theme="dark"] .factcheck__spinner { border-color: #3f3f46; border-top-color: #e4e4e7; }
                html[data-theme="dark"] .factcheck__bar { background: #27272a; border-color: #3f3f46; }
                html[data-theme="dark"] .factcheck__bar-label { color: #fafafa; text-shadow: 0 0 4px rgba(0,0,0,0.7); }
                html[data-theme="dark"] .factcheck__meta { color: #a1a1aa; }
                html[data-theme="dark"] .factcheck__meta strong { color: #fafafa; }
                html[data-theme="dark"] .factcheck__prose { color: #e4e4e7; }
                html[data-theme="dark"] .factcheck__sources, html[data-theme="dark"] .factcheck__sources a, html[data-theme="dark"] .factcheck__sources span { color: #a1a1aa; }
                html[data-theme="dark"] .factcheck__sources a:hover { color: #fafafa; }
                html[data-theme="dark"] .meta__paper { background: #3f3f46; color: #e4e4e7; }
                html[data-theme="dark"] .meta__paper:hover { background: #52525b; }
                html[data-theme="dark"] .vote__btn { background: #27272a; border-color: #3f3f46; color: #a1a1aa; }
                html[data-theme="dark"] .vote__btn:hover { background: #3f3f46; color: #e4e4e7; border-color: #71717a; }
            </style>
        </head>
        <body>
            <main>
                <header class="top">
                    <h1><a href="/">extrablatt!</a></h1>
                    <span class="count" id="count" data-suffix=" Artikel">{$countLabel}</span>
                    <span class="last-scrape" title="Letzter Scrape">{$lastScrapeLabel}</span>
                    <button type="button" class="theme-toggle" id="themeToggle" title="Theme umschalten" aria-label="Theme umschalten"></button>
                    <form class="search" method="get" action="/" role="search">
                        <input type="search" name="q" value="{$searchValue}" placeholder="🔍 Volltextsuche…" autocomplete="off" aria-label="Volltextsuche">
                    </form>
                    <a class="scrape-link" href="/?scrape=1" target="_blank" rel="noopener">Scrape ▶</a>
                </header>
                <nav class="viewnav">
                    <a class="viewnav__tab{$zeitungActive}" href="/?view=zeitung">Zeitung</a>
                    <a class="viewnav__tab{$meldungenActive}" href="/?view=meldungen">Meldungen</a>
                    <a class="viewnav__tab{$talkshowActive}" href="/?view=talkshows">Talk-Shows</a>
                    {$mediaTabsHtml}
                    <a class="viewnav__tab{$bildActive}" href="/?view=bild">BILD</a>
                    <a class="viewnav__tab{$factcheckActive}" href="/?view=factcheck">Faktencheck</a>
                </nav>
                {$zeitungBlock}
                {$meldungenBlock}
                {$factcheckBlock}
                {$bildBlock}
                {$searchBlock}
            </main>
            <a href="#" class="top-btn" onclick="window.scrollTo({top:0,behavior:'smooth'});return false;">↑ Top</a>
            <script>
                // Filter change handler: auto-flips Magisch → "Alle" when
                // the picked filter ends up being the ONLY active one (i.e.
                // every other filter dropdown is still on its default).
                // Prevents the common 0-results case where the Magisch
                // bucket (top-10 frozen items) misses the chosen filter.
                window.filterChange = function (sel) {
                    var form = sel.form;
                    var FILTER_FIELDS = ['paper','tv','status','paywall','thumb','category','read'];
                    var activeCount = 0;
                    for (var i = 0; i < FILTER_FIELDS.length; i++) {
                        var el = form.elements[FILTER_FIELDS[i]];
                        if (el && el.value !== '') activeCount++;
                    }
                    if (activeCount === 1 && sel.value !== '') {
                        var mg = form.elements['magic'];
                        if (mg && mg.value !== 'all') mg.value = 'all';
                    }
                    form.submit();
                };
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

                (function () {
                    var \$btn = document.getElementById('themeToggle');
                    if (!\$btn) { return; }
                    var SUN = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><line x1="12" y1="2" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="6.34" y2="6.34"/><line x1="17.66" y1="17.66" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="6.34" y2="17.66"/><line x1="17.66" y1="6.34" x2="19.07" y2="4.93"/></svg>';
                    var MOON = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
                    var apply = function (mode) {
                        if (mode === 'dark') {
                            document.documentElement.setAttribute('data-theme', 'dark');
                            \$btn.innerHTML = SUN;
                        } else {
                            document.documentElement.removeAttribute('data-theme');
                            \$btn.innerHTML = MOON;
                        }
                    };
                    try { apply(localStorage.getItem('theme') === 'dark' ? 'dark' : 'light'); } catch (e) {}
                    \$btn.addEventListener('click', function () {
                        var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                        try { localStorage.setItem('theme', next); } catch (e) {}
                        apply(next);
                    });
                })();

                // Android only: rewrite cross-origin taps to intent:// URIs
                // pinned to Chrome's package. The explicit package= bypasses
                // App Links so the Reddit / X / YouTube app can't intercept;
                // browser_fallback_url covers devices without Chrome.
                (function () {
                    if (!/android/i.test(navigator.userAgent)) { return; }
                    document.addEventListener('click', function (e) {
                        var \$a = e.target.closest ? e.target.closest('a') : null;
                        if (!\$a) { return; }
                        var href = \$a.getAttribute('href');
                        if (!href) { return; }
                        var m = /^(https?):\/\/([^/?#]+)(.*)\$/i.exec(href);
                        if (!m) { return; }
                        if (m[2].toLowerCase() === location.host.toLowerCase()) { return; }
                        e.preventDefault();
                        var intent = 'intent://' + m[2] + m[3]
                            + '#Intent;scheme=' + m[1]
                            + ';package=com.android.chrome'
                            + ';S.browser_fallback_url=' + encodeURIComponent(href)
                            + ';end';
                        location.href = intent;
                    }, true);
                })();
            </script>
        </body>
        </html>
        HTML;
    }
}

