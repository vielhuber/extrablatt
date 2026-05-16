<?php
declare(strict_types=1);

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

    private const COOKIE_DIR = __DIR__ . '/.cookies';
    private const CSS_DIR = __DIR__ . '/css';
    // Pinned to chrome123 — newer Chrome variants (124+) trip Reddit's bot
    // detection (TLS/header fingerprint check), returning 403 even with valid
    // cookies and from a non-blocked IP. chrome123 stays under Reddit's radar
    // while still working against archive.ph and the publisher HTML probes.
    private const CURL_IMPERSONATE_BIN = '/opt/curl-impersonate/curl_chrome123';
    private const CACHE_DIR = __DIR__ . '/.cache';
    private const DATABASE_FILE = __DIR__ . '/.cache/articles.sqlite';
    private const CONFIG_FILE = __DIR__ . '/config.json';
    private const ENV_FILE = __DIR__ . '/.env';
    private const AIHELPER_AUTOLOAD = '/var/www/aihelper/vendor/autoload.php';
    // Caches never expire automatically — only the manual Reset button (which
    // runs DELETE FROM articles plus a sweep of .cache/) drops them. Subsequent
    // scrapes upsert on top, so existing cached entries are preserved unless the
    // user explicitly resets.
    private const FEED_MAX_ITEMS = 40;
    private const DASHBOARD_MAX_ITEMS = 10000;
    private const ARCHIVE_CHECK_CONCURRENCY = 8;
    private const THUMBNAIL_SIZE = 160;
    private const THUMBNAIL_JPEG_QUALITY = 78;
    private const THUMBNAIL_MAX_SOURCE_BYTES = 8_000_000;
    private const FETCH_CONNECT_TIMEOUT_SECONDS = 8;
    private const FETCH_MAX_TIME_SECONDS = 20;

    public function run(): void
    {
        $customUrl = (string) ($_GET['url'] ?? '');
        $paperFilter = (string) ($_GET['paper'] ?? '');
        $statusFilter = (string) ($_GET['status'] ?? '');
        $paywallFilter = (string) ($_GET['paywall'] ?? '');
        $categoryFilter = (string) ($_GET['category'] ?? '');
        $readFilter = (string) ($_GET['read'] ?? '');
        $sortFilter = (string) ($_GET['sort'] ?? '');
        $magicFilter = (string) ($_GET['magic'] ?? '');

        if (isset($_GET['scrape'])) {
            $this->runScrape();
            return;
        }

        if (isset($_POST['reset']) && $_POST['reset'] === '1') {
            $db = $this->openDatabase();
            $db->exec(statement: 'DELETE FROM articles');
            foreach ((array) glob(pattern: self::CACHE_DIR . '/*') as $file) {
                if (is_file(filename: (string) $file) && (string) $file !== self::DATABASE_FILE) {
                    @unlink(filename: (string) $file);
                }
            }
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
            if (!in_array(needle: $magicFilter, haystack: ['', 'magic'], strict: true)) {
                $magicFilter = '';
            }
            header(header: 'Content-Type: text/html; charset=utf-8');
            echo $this->renderDashboard(
                paperFilter: $paperFilter,
                statusFilter: $statusFilter,
                paywallFilter: $paywallFilter,
                categoryFilter: $categoryFilter,
                readFilter: $readFilter,
                sortFilter: $sortFilter,
                magicFilter: $magicFilter
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

    private function fetchWithCache(string $originalUrl, bool $forceRefresh = false): FetchResult
    {
        // Cache key is the original URL — independent of which mirror serves it.
        $cacheKey = md5(string: $originalUrl);
        $cacheFile = self::CACHE_DIR . '/' . $cacheKey . '.html';
        $metaFile = self::CACHE_DIR . '/' . $cacheKey . '.meta.json';

        if (
            !$forceRefresh
            && file_exists(filename: $cacheFile)
            && $this->isCachedSnapshotValid(metaFile: $metaFile)
        ) {
            $cached = (string) file_get_contents(filename: $cacheFile);
            if ($cached !== '') {
                return FetchResult::fresh(content: $cached);
            }
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
                if (!is_dir(filename: self::CACHE_DIR)) {
                    mkdir(directory: self::CACHE_DIR, permissions: 0755, recursive: true);
                }
                file_put_contents(filename: $cacheFile, data: $result->body);
                file_put_contents(
                    filename: $metaFile,
                    data: json_encode(
                        value: [
                            'original_url' => $originalUrl,
                            'mirror_tld' => $tld,
                            'final_url' => $result->finalUrl,
                            'archived_at' => $this->extractArchivedAt(finalUrl: $result->finalUrl),
                            'fetched_at' => time()
                        ],
                        flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                    )
                );
                return FetchResult::fresh(content: $result->body);
            }
        }

        $errorMessage = 'Kein archive.<tld> Spiegel hat einen brauchbaren Snapshot geliefert.';
        $debug = $lastResult?->debug() ?? [];
        $debug['mirror_attempts'] = implode(separator: "\n", array: $attemptLog);
        $debug['original_url'] = $originalUrl;

        if (file_exists(filename: $cacheFile) && $this->isCachedSnapshotValid(metaFile: $metaFile)) {
            $cached = (string) file_get_contents(filename: $cacheFile);
            if ($cached !== '') {
                return FetchResult::stale(content: $cached, errorMessage: $errorMessage, debug: $debug);
            }
        }

        return FetchResult::failed(errorMessage: $errorMessage, debug: $debug);
    }

    private function isCachedSnapshotValid(string $metaFile): bool
    {
        if (!file_exists(filename: $metaFile)) {
            return false;
        }
        $meta = json_decode(json: (string) file_get_contents(filename: $metaFile), associative: true);
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
            self::CURL_IMPERSONATE_BIN,
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

        $files = is_dir(filename: self::COOKIE_DIR) ? glob(pattern: self::COOKIE_DIR . '/*.json') : [];

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
            $links .= '<link rel="stylesheet" href="' . $origin . '/' . $relativePath . '?v=' . $version . '">';
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
        $assets = ['css/common.css' => self::CSS_DIR . '/common.css'];
        if ($paper !== '') {
            // Obfuscate the per-paper stylesheet filename so no paper name
            // leaks via the network log. The hash is deterministic so the
            // browser cache stays warm across requests.
            $hash = substr(string: md5(string: $paper), offset: 0, length: 12);
            $assets['css/' . $hash . '.css'] = self::CSS_DIR . '/' . $hash . '.css';
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
        return '<link rel="manifest" href="/pwa/manifest.json">' .
            '<meta name="theme-color" content="#111111">' .
            '<link rel="icon" type="image/svg+xml" href="/pwa/icon.svg">' .
            '<link rel="icon" type="image/png" sizes="192x192" href="/pwa/icon-192.png">' .
            '<link rel="apple-touch-icon" href="/pwa/apple-touch-icon.png">' .
            '<meta name="apple-mobile-web-app-capable" content="yes">' .
            '<meta name="mobile-web-app-capable" content="yes">' .
            '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' .
            '<meta name="apple-mobile-web-app-title" content="Extrablatt">';
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

        if (!is_dir(filename: self::CACHE_DIR)) {
            mkdir(directory: self::CACHE_DIR, permissions: 0755, recursive: true);
        }

        // Non-RSS feed sources (X, Reddit) — these need authenticated JSON
        // scraping rather than XML parsing. Each handler caches its own raw
        // response and returns a FeedItem[] directly.
        if (str_starts_with(haystack: $feedUrl, needle: 'x://')) {
            return $this->fetchXTimelineItems(paper: $paper);
        }
        if (str_starts_with(haystack: $feedUrl, needle: 'reddit://')) {
            return $this->fetchRedditHomeItems(paper: $paper);
        }

        $cacheFile = self::CACHE_DIR . '/feed-' . $paper . '.xml';
        $body = null;

        if (file_exists(filename: $cacheFile)) {
            $body = (string) file_get_contents(filename: $cacheFile);
        }

        if ($body === null || $body === '') {
            $result = $this->fetchViaImpersonate(url: $feedUrl);
            if ($result->body === null) {
                return [];
            }
            $body = $result->body;
            file_put_contents(filename: $cacheFile, data: $body);
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
        $cacheFile = self::CACHE_DIR . '/feed-' . $paper . '.json';
        $body = null;
        if (file_exists(filename: $cacheFile)) {
            $body = (string) file_get_contents(filename: $cacheFile);
        }
        if ($body === null || $body === '') {
            $cookieHeader = $this->buildCookieHeader(targetUrl: 'https://x.com/');
            $ct0 = $this->extractCookieValue(cookieHeader: $cookieHeader, name: 'ct0');
            if ($ct0 === '' || $cookieHeader === '') {
                return [];
            }
            $bearer = 'AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs%3D1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA';
            $result = $this->fetchWithHeaders(
                url: 'https://x.com/i/api/2/timeline/home.json?count=40',
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
            file_put_contents(filename: $cacheFile, data: $body);
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
            if (count(value: $items) >= self::FEED_MAX_ITEMS) {
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
        $cacheFile = self::CACHE_DIR . '/feed-' . $paper . '.json';
        $body = null;
        if (file_exists(filename: $cacheFile)) {
            $body = (string) file_get_contents(filename: $cacheFile);
        }
        if ($body === null || $body === '') {
            $result = $this->fetchViaImpersonate(url: 'https://www.reddit.com/.json?limit=' . self::FEED_MAX_ITEMS);
            if ($result->body === null) {
                return [];
            }
            $body = $result->body;
            file_put_contents(filename: $cacheFile, data: $body);
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
            if (count(value: $items) >= self::FEED_MAX_ITEMS) {
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
        $args = [self::CURL_IMPERSONATE_BIN, '-s', '--max-time', (string) self::FETCH_MAX_TIME_SECONDS, '-o', $bodyFile, '-w', '%{http_code}'];
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
                if (count(value: $items) >= self::FEED_MAX_ITEMS) {
                    break;
                }
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
                $items[] = new FeedItem(
                    title: $title,
                    link: $link,
                    publishedAt: $this->parseDate(input: $this->extractEntryDate(entry: $entry)),
                    imageUrl: $this->extractImageFromRssItem(entry: $entry),
                    rating: $rating
                );
                if (count(value: $items) >= self::FEED_MAX_ITEMS) {
                    break;
                }
            }
            return $items;
        }

        // Atom.
        if (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $href = '';
                foreach ($entry->link as $linkNode) {
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
                if (count(value: $items) >= self::FEED_MAX_ITEMS) {
                    break;
                }
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

        $contentEncoded = (string) ($entry->children(namespaceOrPrefix: $contentNs)->encoded ?? '');
        $description = (string) $entry->description;
        $haystack = $contentEncoded !== '' ? $contentEncoded : $description;
        if ($haystack !== '' && preg_match(pattern: '~<img[^>]+src=["\']([^"\']+)~i', subject: $haystack, matches: $m)) {
            $candidate = trim(string: $m[1]);
            if ($candidate !== '' && !str_ends_with(haystack: $candidate, needle: '/')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whitelist of sort options. Maps the user-facing dropdown value to a label
     * and an ORDER BY clause. Default (empty key) is newest first.
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
            escapeshellarg(arg: self::CURL_IMPERSONATE_BIN) .
            ' -sI -H "$1" --max-time 8 -o /dev/null ' .
            '-w "STATUS:%{http_code}|LOCATION:%header{location}|TARGET:$2\n" ' .
            '"https://archive.ph/newest/$2" 2>/dev/null';

        $cmd = sprintf(
            'cat %s | xargs -P %d -d "\n" -I {} sh -c %s _ %s {}',
            escapeshellarg(arg: $tmpIn),
            self::ARCHIVE_CHECK_CONCURRENCY,
            escapeshellarg(arg: $innerCmd),
            escapeshellarg(arg: 'Cookie: ' . $cookieHeader)
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
        $file = self::CACHE_DIR . '/archcheck-' . md5(string: $url) . '.json';
        if (!file_exists(filename: $file)) {
            return null;
        }
        $data = json_decode(json: (string) file_get_contents(filename: $file), associative: true);
        if (!is_array(value: $data) || !isset($data['available'])) {
            return null;
        }
        return (bool) $data['available'];
    }

    private function writeArchiveCheckCache(string $url, bool $available): void
    {
        if (!is_dir(filename: self::CACHE_DIR)) {
            mkdir(directory: self::CACHE_DIR, permissions: 0755, recursive: true);
        }
        $file = self::CACHE_DIR . '/archcheck-' . md5(string: $url) . '.json';
        file_put_contents(
            filename: $file,
            data: json_encode(value: ['available' => $available], flags: JSON_UNESCAPED_SLASHES)
        );
    }

    private function openDatabase(): PDO
    {
        static $db = null;
        if ($db !== null) {
            return $db;
        }
        if (!is_dir(filename: self::CACHE_DIR)) {
            mkdir(directory: self::CACHE_DIR, permissions: 0755, recursive: true);
        }
        $db = new PDO(dsn: 'sqlite:' . self::DATABASE_FILE);
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
        $this->runMigrations(db: $db);
        $db->exec(statement: 'CREATE INDEX IF NOT EXISTS idx_articles_category ON articles(category);');
        return $db;
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
    }

    /**
     * @return array{
     *   ai: array<string, mixed>,
     *   categories: array<string, array<int, string>>,
     *   papers: array<string, array{url: string, label: string, rss: string}>,
     *   archive_fulltext_check?: array<string, mixed>
     * }
     */
    private function loadConfig(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $raw = @file_get_contents(filename: self::CONFIG_FILE);
        $parsed = $raw !== false ? json_decode(json: $raw, associative: true) : null;
        $rawCats = $parsed['categories'] ?? [];
        $tree = [];
        if (is_array(value: $rawCats)) {
            if (array_is_list(array: $rawCats)) {
                foreach ($rawCats as $cat) {
                    $tree[(string) $cat] = [];
                }
            } else {
                foreach ($rawCats as $parent => $children) {
                    $tree[(string) $parent] = is_array(value: $children) ? array_values($children) : [];
                }
            }
        }
        $cached = [
            'ai' => is_array(value: $parsed['ai'] ?? null) ? $parsed['ai'] : [],
            'categories' => $tree,
            'papers' => is_array(value: $parsed['papers'] ?? null) ? $parsed['papers'] : [],
            'archive_fulltext_check' => is_array(value: $parsed['archive_fulltext_check'] ?? null)
                ? $parsed['archive_fulltext_check']
                : []
        ];
        return $cached;
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
        foreach ($this->loadConfig()['categories'] as $parent => $children) {
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
        foreach ($this->loadConfig()['categories'] as $parent => $children) {
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
        $tree = $this->loadConfig()['categories'];
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
        if (!file_exists(filename: self::ENV_FILE)) {
            return $env;
        }
        foreach (file(filename: self::ENV_FILE, flags: FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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

    private function runScrape(): void
    {
        // Synchronous, streaming. Phases: feeds → paywall → archive → fulltext
        // → thumbnails → AI categories → upsert.
        $this->setupStreamingOutput();

        // Pad every line with 8 KiB of trailing whitespace so browsers / proxy
        // buffers render each progress line immediately instead of batching.
        $padding = str_repeat(string: ' ', times: 8192);
        $emit = function (string $line) use ($padding): void {
            echo $line . $padding . "\n";
            @ob_flush();
            @flush();
        };

        $startedAt = microtime(as_float: true);
        $emit('=== extrablatt scrape — ' . date(format: 'Y-m-d H:i:s') . ' ===');
        $emit('');

        $db = $this->openDatabase();

        // A manual scrape always re-fetches every feed. Drop cached XML/JSON.
        // Article-level caches stay intact (tied to article URLs, not feed state).
        foreach ((array) glob(pattern: self::CACHE_DIR . '/feed-*') as $f) {
            if (is_file(filename: (string) $f)) {
                @unlink(filename: (string) $f);
            }
        }

        // Phase 1: feeds.
        $emit('Phase 1/7: RSS-Feeds einlesen');
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
        $emit('Phase 2/7: Paywall-Status prüfen (HTML-Probe der Originalseiten)');
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
        $emit('Phase 3/7: Archive-Verfügbarkeit prüfen (nur PLUS, parallel)');
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
        $emit('Phase 4/7: Volltext-Check der archivierten PLUS-Artikel');
        $fulltextCfg = $this->loadConfig()['archive_fulltext_check'] ?? [];
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
            : $this->checkArchiveFulltextStreaming(items: $fulltextCandidates, config: $fulltextCfg, emit: $emit);
        $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
        $archiveFull = $knownFulltext + $freshFulltext;
        $fullOk = count(value: array_filter(array: $archiveFull, callback: fn(?bool $v): bool => $v === true));
        $fullCut = count(value: array_filter(array: $archiveFull, callback: fn(?bool $v): bool => $v === false));
        $emit(sprintf('  → %d Volltext, %d gekürzt (%d ms)', $fullOk, $fullCut, $ms));
        $emit('');

        // Phase 5: thumbnails.
        $emit('Phase 5/7: Thumbnails herunterladen + skalieren');

        $redditItems = array_values(array_filter(
            array: $allItems,
            callback: fn(array $entry): bool => $entry['paper'] === 'reddit'
        ));
        $redditPending = array_values(array_filter(
            array: $redditItems,
            callback: fn(array $entry): bool => !$this->ogImageCacheExists(url: $entry['item']->link)
        ));
        if (!empty($redditPending)) {
            $emit(sprintf('  Reddit-Auflösung: %d Posts (JSON + og:image bzw. Community-Icon)', count(value: $redditPending)));
            $phaseStart = microtime(as_float: true);
            $this->resolveRedditImagesStreaming(items: $redditPending, emit: $emit);
            $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
            $emit(sprintf('  → Reddit-Auflösung fertig (%d ms)', $ms));
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
            if ($img !== null && $img !== '') {
                $effectiveImages[$url] = $img;
            }
        }
        $thumbCandidates = array_values(array: array_filter(
            array: $allItems,
            callback: fn(array $entry): bool =>
                isset($effectiveImages[$entry['item']->link])
                && !in_array(needle: $entry['item']->link, haystack: $knownThumbs, strict: true)
        ));
        $emit(sprintf(
            '  %d bereits gecached, %d neu, %d ohne Bild-URL',
            count(value: $knownThumbs),
            count(value: $thumbCandidates),
            count(value: $allItems) - count(value: $knownThumbs) - count(value: $thumbCandidates)
        ));
        $phaseStart = microtime(as_float: true);
        $thumbnails = $this->downloadThumbnailsStreaming(items: $thumbCandidates, imageUrls: $effectiveImages, emit: $emit);
        $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
        $okThumbs = count(value: array_filter(array: $thumbnails));
        $emit(sprintf('  → %d Thumbnails generiert (%d ms)', $okThumbs, $ms));
        $emit('');

        // Phase 6: AI categorisation.
        $emit('Phase 6/7: AI-Kategorisierung');
        $config = $this->loadConfig();
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
        $toCategorize = array_values(array: array_filter(
            array: $allItems,
            callback: fn(array $entry): bool => !array_key_exists(key: $entry['item']->link, array: $knownCategories)
        ));
        $emit(sprintf('  %d bereits kategorisiert, %d zu kategorisieren', count(value: $knownCategories), count(value: $toCategorize)));
        $freshCategories = [];
        if (empty($toCategorize)) {
            $emit('  → keine neuen Artikel');
        } elseif ($aiProvider === '' || $aiModel === '') {
            $emit('  ⚠️  AI_PROVIDER / AI_MODEL in .env nicht gesetzt — Phase übersprungen');
        } elseif (empty($config['categories'])) {
            $emit('  ⚠️  Keine Kategorien in config.json — Phase übersprungen');
        } else {
            $phaseStart = microtime(as_float: true);
            $freshCategories = $this->categorizeArticlesStreaming(
                items: $toCategorize,
                categories: $this->leafCategories(),
                aiConfig: $config['ai'] + ['provider' => $aiProvider, 'model' => $aiModel],
                apiKey: $apiKey,
                emit: $emit
            );
            $ms = (int) round(num: (microtime(as_float: true) - $phaseStart) * 1000);
            $emit(sprintf('  → %d kategorisiert (%d ms)', count(value: array_filter($freshCategories)), $ms));
        }
        $emit('');

        // Phase 7: upsert.
        $emit('Phase 7/7: In Datenbank schreiben');
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

        $existingByTitle = [];
        foreach ($db->query(query: 'SELECT title, url FROM articles')->fetchAll(mode: PDO::FETCH_ASSOC) ?: [] as $row) {
            $existingByTitle[$this->normalizeTitle(title: (string) $row['title'])] = (string) $row['url'];
        }

        $now = time();
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $skippedPaywalled = 0;
        foreach ($allItems as $entry) {
            $item = $entry['item'];
            $titleKey = $this->normalizeTitle(title: $item->title);
            $existingUrl = $existingByTitle[$titleKey] ?? null;
            if ($existingUrl !== null && $existingUrl !== $item->link) {
                $skipped++;
                continue;
            }
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
            if ($stmt->rowCount() === 1) {
                $inserted++;
            } else {
                $updated++;
            }
            $existingByTitle[$titleKey] = $item->link;
        }
        $emit(sprintf(
            '  → %d neu, %d aktualisiert, %d Dubletten, %d PLUS ohne Volltext-Archive übersprungen, %d in DB',
            $inserted,
            $updated,
            $skipped,
            $skippedPaywalled,
            $this->totalArticleCount(db: $db)
        ));
        $emit('');

        $totalMs = (int) round(num: (microtime(as_float: true) - $startedAt) * 1000);
        $emit(sprintf('=== Scrape beendet in %d.%03ds ===', intdiv($totalMs, 1000), $totalMs % 1000));
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
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, bool>
     */
    private function readKnownArchiveFulltext(array $urls): array
    {
        $result = [];
        foreach ($urls as $url) {
            $file = self::CACHE_DIR . '/archfull-' . md5(string: $url) . '.json';
            if (!file_exists(filename: $file)) {
                continue;
            }
            $data = json_decode(json: (string) file_get_contents(filename: $file), associative: true);
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
        if (!is_dir(filename: self::CACHE_DIR)) {
            mkdir(directory: self::CACHE_DIR, permissions: 0755, recursive: true);
        }
        $cacheKey = md5(string: $originalUrl);
        $cacheFile = self::CACHE_DIR . '/' . $cacheKey . '.html';
        $metaFile = self::CACHE_DIR . '/' . $cacheKey . '.meta.json';

        file_put_contents(filename: $cacheFile, data: $body);

        $tld = null;
        if (preg_match(pattern: '#archive\.([a-z]+)/\d{14}/#', subject: $finalUrl, matches: $m) === 1) {
            $tld = $m[1];
        }
        file_put_contents(
            filename: $metaFile,
            data: json_encode(
                value: [
                    'original_url' => $originalUrl,
                    'mirror_tld' => $tld,
                    'final_url' => $finalUrl,
                    'archived_at' => $this->extractArchivedAt(finalUrl: $finalUrl),
                    'fetched_at' => time()
                ],
                flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
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

        // (A) Crossposts to external sites → grab og:image from the linked page.
        $destUrl = (string) ($post['url_overridden_by_dest'] ?? '');
        $isInternal = $destUrl === ''
            || preg_match(
                pattern: '~^https?://(?:i|v|preview|external-preview)\.redd\.it/|^https?://(?:www|old|new)\.reddit\.com/~i',
                subject: $destUrl
            ) === 1;
        if (!$isInternal) {
            $og = $this->extractOgImageFromUrl(url: $destUrl);
            if ($og !== null) {
                return $og;
            }
        }

        // (B) Native i.redd.it / image-post → fall back to the subreddit icon.
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
        if (preg_match(
            pattern: '~property=["\']og:image["\'][^>]{0,200}content=["\']([^"\']+)~i',
            subject: $body,
            matches: $m
        ) === 1) {
            $candidate = html_entity_decode(string: $m[1], flags: ENT_QUOTES);
            return $candidate !== '' ? $candidate : null;
        }
        return null;
    }

    private function readOgImageCache(string $url): ?string
    {
        $file = self::CACHE_DIR . '/og-' . md5(string: $url) . '.json';
        if (!file_exists(filename: $file)) {
            return null;
        }
        $data = json_decode(json: (string) file_get_contents(filename: $file), associative: true);
        if (!is_array(value: $data) || !isset($data['url'])) {
            return null;
        }
        $url = (string) $data['url'];
        return $url === '' ? null : $url;
    }

    private function ogImageCacheExists(string $url): bool
    {
        return file_exists(filename: self::CACHE_DIR . '/og-' . md5(string: $url) . '.json');
    }

    private function writeOgImageCache(string $url, string $imageUrl): void
    {
        if (!is_dir(filename: self::CACHE_DIR)) {
            mkdir(directory: self::CACHE_DIR, permissions: 0755, recursive: true);
        }
        if ($imageUrl !== '') {
            $imageUrl = html_entity_decode(string: $imageUrl, flags: ENT_QUOTES);
        }
        $file = self::CACHE_DIR . '/og-' . md5(string: $url) . '.json';
        file_put_contents(
            filename: $file,
            data: json_encode(value: ['url' => $imageUrl], flags: JSON_UNESCAPED_SLASHES)
        );
    }

    private function writeArchiveFulltextCache(string $url, bool $full): void
    {
        if (!is_dir(filename: self::CACHE_DIR)) {
            mkdir(directory: self::CACHE_DIR, permissions: 0755, recursive: true);
        }
        $file = self::CACHE_DIR . '/archfull-' . md5(string: $url) . '.json';
        file_put_contents(
            filename: $file,
            data: json_encode(value: ['full' => $full], flags: JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param array<int, array{paper: string, item: FeedItem}> $items
     * @param array<string, mixed> $config
     * @return array<string, bool>
     */
    private function checkArchiveFulltextStreaming(array $items, array $config, callable $emit): array
    {
        if (empty($items)) {
            return [];
        }

        $minChars = (int) ($config['min_text_chars'] ?? 8000);
        $minCharsPerPaper = is_array(value: $config['min_text_chars_per_paper'] ?? null)
            ? $config['min_text_chars_per_paper']
            : [];
        $stubMarkersPerPaper = is_array(value: $config['stub_markers_per_paper'] ?? null)
            ? $config['stub_markers_per_paper']
            : [];

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
            'final=$(' . escapeshellarg(arg: self::CURL_IMPERSONATE_BIN) .
            ' -sL -H "$1" --max-time 30 --max-redirs 5 ' .
            '-w "%{url_effective}" -o "$dst" "https://archive.ph/newest/$src" 2>/dev/null); ' .
            'sz=$(stat -c%s "$dst" 2>/dev/null || echo 0); ' .
            'echo "SIZE:$sz|FINAL:$final|FILE:$dst|URL:$src"';

        $cmd = sprintf(
            'cat %s | xargs -P %d -d "\n" -I {} sh -c %s _ %s {}',
            escapeshellarg(arg: $tmpIn),
            self::ARCHIVE_CHECK_CONCURRENCY,
            escapeshellarg(arg: $innerCmd),
            escapeshellarg(arg: 'Cookie: ' . $cookieHeader)
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

            $isFull = $this->analyzeArchiveFulltext(
                html: $html,
                paper: $paper,
                minChars: (int) ($minCharsPerPaper[$paper] ?? $minChars),
                markers: is_array(value: $stubMarkersPerPaper[$paper] ?? null) ? $stubMarkersPerPaper[$paper] : []
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
        file_put_contents(filename: $tmpIn, data: implode(separator: "\n", array: $lines));

        $innerCmd =
            'src=$(echo "$1" | cut -f1); ' .
            'dst=$(echo "$1" | cut -f2); ' .
            'lnk=$(echo "$1" | cut -f3); ' .
            escapeshellarg(arg: self::CURL_IMPERSONATE_BIN) .
            ' -sL --max-redirs 5 --max-time 15 --max-filesize ' . self::THUMBNAIL_MAX_SOURCE_BYTES .
            ' "$src" -o "$dst" 2>/dev/null; ' .
            'sz=$(stat -c%s "$dst" 2>/dev/null || echo 0); ' .
            'echo "SIZE:$sz|DST:$dst|URL:$lnk"';

        $cmd = sprintf(
            'cat %s | xargs -P %d -d "\n" -I {} sh -c %s _ {}',
            escapeshellarg(arg: $tmpIn),
            self::ARCHIVE_CHECK_CONCURRENCY,
            escapeshellarg(arg: $innerCmd)
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
            if (!preg_match(pattern: '~^SIZE:(\d+)\|DST:(.+?)\|URL:(.+)$~', subject: $line, matches: $m)) {
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
        if (!file_exists(filename: self::AIHELPER_AUTOLOAD)) {
            $emit('  ⚠️  aihelper-Autoloader nicht gefunden: ' . self::AIHELPER_AUTOLOAD);
            return [];
        }
        require_once self::AIHELPER_AUTOLOAD;
        if (!class_exists(class: 'vielhuber\\aihelper\\aihelper')) {
            $emit('  ⚠️  vielhuber\\aihelper\\aihelper-Klasse nicht verfügbar');
            return [];
        }

        $aiClass = 'vielhuber\\aihelper\\aihelper';
        $logPath = self::CACHE_DIR . '/aihelper.log';
        $ai = $aiClass::create(
            provider: (string) ($aiConfig['provider'] ?? 'anthropic'),
            model: (string) ($aiConfig['model'] ?? 'claude-haiku-4-5-20251001'),
            temperature: (float) ($aiConfig['temperature'] ?? 0.0),
            api_key: $apiKey,
            log: $logPath,
            max_tries: (int) ($aiConfig['max_tries'] ?? 2),
            timeout: (int) ($aiConfig['timeout'] ?? 60)
        );
        $emit('  → aihelper log: ' . $logPath);

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
        $file = self::CACHE_DIR . '/category-' . md5(string: $this->normalizeTitle(title: $title)) . '.json';
        if (!file_exists(filename: $file)) {
            return null;
        }
        $data = json_decode(json: (string) file_get_contents(filename: $file), associative: true);
        if (!is_array(value: $data) || !array_key_exists(key: 'category', array: $data)) {
            return null;
        }
        $category = $data['category'];
        return $category === null ? null : (string) $category;
    }

    private function categoryCacheExists(string $title): bool
    {
        return file_exists(filename: self::CACHE_DIR . '/category-' . md5(string: $this->normalizeTitle(title: $title)) . '.json');
    }

    private function writeCategoryCache(string $title, ?string $category): void
    {
        if (!is_dir(filename: self::CACHE_DIR)) {
            mkdir(directory: self::CACHE_DIR, permissions: 0755, recursive: true);
        }
        $file = self::CACHE_DIR . '/category-' . md5(string: $this->normalizeTitle(title: $title)) . '.json';
        file_put_contents(
            filename: $file,
            data: json_encode(value: ['category' => $category], flags: JSON_UNESCAPED_SLASHES)
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
        $ogPattern = 'property=["\']og:image["\'][^>]{0,200}content=["\']\K[^"\']+';
        $innerCmd =
            'body=$(' . escapeshellarg(arg: self::CURL_IMPERSONATE_BIN) .
            ' -sL --max-redirs 5 --max-time 12 "$1" 2>/dev/null | head -c 300000); ' .
            'pw=$(printf "%s" "$body" | grep -ciE ' . escapeshellarg(arg: $paywallPattern) . ' || true); ' .
            'og=$(printf "%s" "$body" | grep -oP ' . escapeshellarg(arg: $ogPattern) . ' 2>/dev/null | head -1); ' .
            'echo "PAYWALL:${pw:-0}|OGIMG:${og}|URL:$1"';

        $cmd = sprintf(
            'cat %s | xargs -P %d -d "\n" -I {} sh -c %s _ {}',
            escapeshellarg(arg: $tmpIn),
            self::ARCHIVE_CHECK_CONCURRENCY,
            escapeshellarg(arg: $innerCmd)
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
            $this->writeOgImageCache(url: $url, imageUrl: $ogImage);
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
     * Aggregate user-behaviour signals into per-paper and per-category
     * weights. The vote sum captures the explicit curator signal (-3..+3
     * each); the read count captures the implicit "I cared enough to click"
     * signal. Together they describe what the user actually engages with.
     *
     * @return array{paper: array<string, float>, category: array<string, float>}
     */
    private function magicWeights(PDO $db): array
    {
        $paper = [];
        foreach ((array) $db->query(query: '
            SELECT paper,
                   COALESCE(SUM(vote), 0) AS votes,
                   SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) AS reads
            FROM articles
            GROUP BY paper
        ')->fetchAll(mode: PDO::FETCH_ASSOC) as $row) {
            $paper[(string) $row['paper']] = (float) $row['votes'] + 0.5 * (float) $row['reads'];
        }
        $category = [];
        foreach ((array) $db->query(query: '
            SELECT category,
                   COALESCE(SUM(vote), 0) AS votes,
                   SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) AS reads
            FROM articles
            WHERE category IS NOT NULL
            GROUP BY category
        ')->fetchAll(mode: PDO::FETCH_ASSOC) as $row) {
            $category[(string) $row['category']] = (float) $row['votes'] + 0.5 * (float) $row['reads'];
        }
        return ['paper' => $paper, 'category' => $category];
    }

    /**
     * @param array<string, float> $map
     */
    private function magicCaseExpression(PDO $db, string $column, array $map): string
    {
        if (empty($map)) {
            return '0';
        }
        $sql = 'CASE ' . $column;
        foreach ($map as $key => $value) {
            $sql .= ' WHEN ' . $db->quote(string: $key) . ' THEN ' . sprintf('%.4f', $value);
        }
        $sql .= ' ELSE 0 END';
        return $sql;
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
        string $magicFilter
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
        // Magic mode always operates on unread articles. Suppress the read
        // filter here so a stale `read=read` URL param does not produce an
        // impossible WHERE clause (read_at IS NOT NULL AND read_at IS NULL).
        if ($magicFilter !== 'magic') {
            if ($readFilter === 'read') {
                $where[] = 'read_at IS NOT NULL';
            }
            if ($readFilter === 'unread') {
                $where[] = 'read_at IS NULL';
            }
        }
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
        if ($magicFilter === 'magic') {
            // Magic mode: top 10 UNREAD articles ranked by a composite score
            // that blends explicit votes, implicit clicks per paper/category,
            // article rating (popularity) and a small recency boost. Other
            // filters the user picked still narrow the candidate set first.
            $where[] = 'read_at IS NULL';
            $weights = $this->magicWeights(db: $db);
            $paperExpr = $this->magicCaseExpression(db: $db, column: 'paper', map: $weights['paper']);
            $categoryExpr = $this->magicCaseExpression(db: $db, column: 'category', map: $weights['category']);
            // log10(rating + 1) compresses Reddit-style 10k scores to ~4 so a
            // viral post can't outweigh a positive curator vote. Recency
            // decays roughly one point per ten days.
            $recencyExpr = '(' . time() . ' - COALESCE(published_at, 0)) / 864000.0';
            $score =
                '(' . $paperExpr . ') + (' . $categoryExpr . ') + vote' .
                ' + COALESCE(log10(NULLIF(rating, 0) + 1), 0)' .
                ' - ' . $recencyExpr;
            $orderBy = '(' . $score . ') DESC, published_at DESC';
            $limit = 10;
        } else {
            $sortDef = $this->sortOptions()[$sortFilter] ?? $this->sortOptions()[''];
            $orderBy = $sortDef['orderBy'];
            $limit = self::DASHBOARD_MAX_ITEMS;
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
        string $magicFilter
    ): string {
        $articles = $this->fetchArticlesForDashboard(
            paperFilter: $paperFilter,
            statusFilter: $statusFilter,
            paywallFilter: $paywallFilter,
            categoryFilter: $categoryFilter,
            readFilter: $readFilter,
            sortFilter: $sortFilter,
            magicFilter: $magicFilter
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
        foreach (['' => 'Modus', 'magic' => 'Magisch'] as $value => $label) {
            $sel = $magicFilter === $value ? ' selected' : '';
            $magicOptions .= '<option value="' . $value . '"' . $sel . '>' . $label . '</option>';
        }

        $categoryOptions = '<option value="">Kategorie</option>';
        foreach ($this->loadConfig()['categories'] as $parent => $children) {
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
        foreach ($articles as $row) {
            $isArchived = $row['status'] === 'archive';
            $url = (string) $row['url'];
            $target = $isArchived ? $this->proxyUrl(originalUrl: $url) : $url;
            // Always open the article in a new tab; keep noopener for
            // external links so window.opener cannot be hijacked.
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
                '<li class="' . $itemClass . '">' .
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
                '</li>';
        }

        if ($rows === '') {
            $rows =
                '<li class="item item--empty">' .
                'Noch keine Artikel in der Datenbank — bitte <a href="/?scrape">Scrape starten</a>.' .
                '</li>';
        }

        $pwa = $this->pwaHeadTags();
        $count = count(value: $articles);
        $countLabel = htmlspecialchars(string: $count . ' Artikel', flags: ENT_QUOTES);

        return <<<HTML
        <!doctype html>
        <html lang="de">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1,viewport-fit=cover">
            <title>extrablatt!</title>
            {$pwa}
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
                form.filters { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 5px; margin: 0 0 1rem; }
                form.filters select { min-width: 0; width: 100%; font: 600 12px/1 system-ui, sans-serif; color: #18181b; background: #fff; border: 1px solid #d4d4d8; padding: 8px 22px 8px 8px; border-radius: 6px; cursor: pointer; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8'><path fill='%2371717a' d='M6 8 0 0h12z'/></svg>"); background-repeat: no-repeat; background-position: right 7px center; background-size: 8px 5px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; }
                form.filters select:hover { border-color: #71717a; }
                form.filters select:focus { outline: none; border-color: #18181b; }
                ul.items { list-style: none; padding: 0; margin: 0; }
                .item { position: relative; background: #fff; border: 1px solid #e4e4e7; border-radius: 8px; padding: 12px 14px; margin: 0 0 10px; display: flex; gap: 12px; align-items: flex-start; }
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
                .item--read { opacity: 0.45; }
                .item--read:hover { opacity: 0.6; }
                .item--empty { text-align: center; color: #71717a; padding: 24px 14px; display: block; }
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
                    <span class="count">{$countLabel}</span>
                    <a class="scrape-link" href="/?scrape=1" target="_blank" rel="noopener">Scrape ▶</a>
                    <form method="post" action="/" onsubmit="return confirm('Alles zurücksetzen: {$count} DB-Einträge plus alle Cache-Dateien löschen?');" style="margin:0">
                        <input type="hidden" name="reset" value="1">
                        <button type="submit" class="reset-btn">Reset</button>
                    </form>
                </header>
                <form class="filters" method="get" action="/">
                    <select name="paper" onchange="this.form.submit()">{$paperOptions}</select>
                    <select name="status" onchange="this.form.submit()">{$statusOptions}</select>
                    <select name="paywall" onchange="this.form.submit()">{$paywallOptions}</select>
                    <select name="category" onchange="this.form.submit()">{$categoryOptions}</select>
                    <select name="read" onchange="this.form.submit()">{$readOptions}</select>
                    <select name="magic" onchange="this.form.submit()">{$magicOptions}</select>
                    <select name="sort" onchange="this.form.submit()">{$sortDropdown}</select>
                </form>
                <ul class="items">{$rows}</ul>
            </main>
            <a href="#" class="top-btn" onclick="window.scrollTo({top:0,behavior:'smooth'});return false;">↑ Top</a>
            <script>
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
                // Persist read state on click. Uses sendBeacon so the
                // request survives navigation away from the dashboard. Also
                // dims the item instantly without waiting for a reload.
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
                        var \$item = \$link.closest('.item');
                        if (\$item) \$item.classList.add('item--read');
                    }, true);
                })();
            </script>
        </body>
        </html>
        HTML;
    }
}

(new Extrablatt())->run();
