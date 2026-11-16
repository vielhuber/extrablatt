<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Extrablatt.php';
require dirname(__DIR__) . '/src/Podcast.php';

use vielhuber\extrablatt\Extrablatt;
use vielhuber\extrablatt\Podcast;

$directory = sys_get_temp_dir() . '/extrablatt-podcast-' . bin2hex(random_bytes(8));
mkdir(directory: $directory . '/.data', permissions: 0o700, recursive: true);
$application = new Extrablatt(rootDir: $directory);
$invoke = static fn(string $method, mixed ...$arguments): mixed => (new ReflectionMethod(
    $application,
    $method
))->invoke($application, ...$arguments);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $dashboard = $invoke(
        method: 'renderDashboard',
        paperFilter: '',
        tvFilter: '',
        mediaFilter: '',
        statusFilter: '',
        paywallFilter: '',
        categoryFilter: '',
        readFilter: '',
        sortFilter: '',
        magicFilter: '',
        thumbFilter: '',
        viewFilter: 'podcast'
    );
    $assert(
        condition: str_contains($dashboard, 'viewnav__tab--active" href="/?view=podcast">Podcast</a>'),
        message: 'Podcast must be an active menu item.'
    );
    $assert(
        condition: str_contains($dashboard, 'Seite 1 / 10'),
        message: 'Podcast must participate in page navigation.'
    );
    $assert(
        condition: str_contains($dashboard, 'href="/?view=zeitung" aria-label="Nächster Tab"'),
        message: 'Podcast must precede Zeitung.'
    );
    $assert(
        condition: !str_contains($dashboard, '<form class="filters"'),
        message: 'Podcast must not display news filters.'
    );
    $assert(
        condition: !str_contains($dashboard, 'Tägliche Zeitung zum Anhören') &&
            !str_contains($dashboard, 'Einmal täglich nach dem Zeitungs-Scrape'),
        message: 'Podcast must not display introductory prose.'
    );
    $assert(
        condition: str_contains($dashboard, 'NOTEBOOKLM_AUTH_JSON'),
        message: 'Missing configuration must be explained.'
    );
    $assert(condition: !str_contains($dashboard, '<audio '), message: 'Missing audio must not render a broken player.');
    $assert(
        condition: preg_match('~src="/\?asset=js/podcast\.js&amp;v=[a-f0-9]+"~', $dashboard) === 1,
        message: 'The archive must not reuse the cached script URL of the old test UI.'
    );

    $_GET = ['view' => 'podcast'];
    ob_start();
    $application->run();
    $routed = (string) ob_get_clean();
    $_GET = [];
    $assert(
        condition: str_contains($routed, '<section class="podcast"'),
        message: 'The public router must recognize the Podcast view.'
    );

    ob_start();
    $application->run();
    $home = (string) ob_get_clean();
    $assert(
        condition: str_contains($home, 'viewnav__tab--active" href="/?view=podcast">Podcast</a>') &&
            str_contains($home, '<section class="podcast"'),
        message: 'The homepage must open Podcast.'
    );

    $podcast = new Podcast(rootDirectory: $directory, python: '', authentication: '');
    $assert(condition: !$podcast->configured(), message: 'Missing configuration must disable generation.');
    $assert(condition: $podcast->episodes() === [], message: 'A fresh installation must have an empty episode list.');
    ob_start();
    $podcast->serveAudio('2026-11-15');
    $assert(condition: ob_get_clean() === '', message: 'Missing audio must not leak a filesystem error.');
    $assert(condition: http_response_code() === 404, message: 'Missing audio must return 404.');
    $podcast = new Podcast(
        rootDirectory: $directory,
        python: __DIR__ . '/fixtures/podcast_worker.py',
        authentication: 'private-test-session'
    );
    $stamp = (new DateTimeImmutable('2026-11-15 00:30:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $html =
        '<section><h2>Meldung des Tages</h2><p>Lead fixture</p><h2>Wochenübersicht</h2><p>Story fixture</p><h2>Lokales &amp; Regionales</h2><p>Local fixture</p><h2>Kryptowährungen</h2><p>Crypto fixture</p><h2>Wetter</h2><p>Weather fixture</p><h2>TV-Talkshows</h2><p>TV fixture</p><h2>Streaming-Serien</h2><p>Series fixture</p><h2>Streaming-Filme</h2><p>Film fixture</p><h2>Kino</h2><p>Cinema fixture</p><h2>Musik</h2><p>Music fixture</p><h2>Gaming</h2><p>Game fixture</p><a href="https://example.org/article?a=1&amp;b=2">Source</a></section>';
    $podcast->generateDaily(newspaperHtml: $html, generatedAt: $stamp);
    $day = $directory . '/.data/podcast/2026-11-15';
    for ($attempt = 0; $attempt < 100 && $podcast->status('2026-11-15')->status === 'running'; $attempt++) {
        usleep(50_000);
    }
    $assert(
        condition: $podcast->status('2026-11-15')->hasAudio,
        message: 'The background worker must finish the dated episode.'
    );
    $source = file_get_contents($day . '/source.txt');
    foreach (
        [
            'Lead fixture',
            'Story fixture',
            'Local fixture',
            'Crypto fixture',
            'Weather fixture',
            'TV fixture',
            'Series fixture',
            'Film fixture',
            'Cinema fixture',
            'Music fixture',
            'Game fixture',
            'https://example.org/article?a=1&b=2'
        ]
        as $content
    ) {
        $assert(
            condition: str_contains($source, $content),
            message: 'Every newspaper section and source URL must reach the briefing.'
        );
    }
    $assert(condition: !str_contains($source, '<p>'), message: 'The source must be plain text.');
    $podcast->generateDaily(newspaperHtml: '<p>Changed evening edition</p>', generatedAt: $stamp + 12 * 3600);
    $assert(
        condition: file_get_contents($day . '/source.txt') === $source,
        message: 'A second scrape must preserve the first edition of the same Berlin day.'
    );
    $assert(
        condition: file_get_contents($day . '/invocations') === '1',
        message: 'A second scrape must not start another generation.'
    );
    $podcast->generateDaily(newspaperHtml: $html, generatedAt: $stamp + 86_400);
    for ($attempt = 0; $attempt < 100 && $podcast->status('2026-11-16')->status === 'running'; $attempt++) {
        usleep(50_000);
    }
    $episodes = $podcast->episodes();
    $assert(
        condition: count($episodes) === 2 && $episodes[0]->date === '2026-11-16',
        message: 'Episodes must be retained and sorted newest first.'
    );
    $assert(
        condition: substr_count(haystack: $podcast->render(), needle: '<audio ') === 2,
        message: 'Each completed daily episode must be playable in the list.'
    );
    $assert(
        condition: !str_contains($podcast->render(), 'Die Tagesfolge ist bereit.'),
        message: 'Completed episodes must show their player without a ready message.'
    );
    $assert(
        condition: !str_contains($podcast->render(), 'private-test-session'),
        message: 'The list must not expose authentication.'
    );
    file_put_contents(filename: $day . '/status.json', data: '{"stage":"auth_error","message":"private-test-session"}');
    $assert(
        condition: $podcast->status('2026-11-15')->status === 'error',
        message: 'Authentication failures must be visible.'
    );
    $assert(
        condition: str_contains($podcast->render(), 'Google-Anmeldung fehlgeschlagen.'),
        message: 'Removing successful status prose must preserve actionable errors.'
    );
    $assert(
        condition: !str_contains($podcast->render(), 'private-test-session'),
        message: 'Raw provider errors must not reach the page.'
    );
    $podcast->generateDaily(newspaperHtml: $html, generatedAt: $stamp);
    $assert(
        condition: file_get_contents($day . '/invocations') === '1',
        message: 'Uncertain or failed generations must not be retried blindly.'
    );
    file_put_contents(filename: $day . '/status.json', data: '{');
    $assert(
        condition: $podcast->status('2026-11-15')->status === 'error',
        message: 'Corrupt state must not enable another generation.'
    );
    $lock = fopen(filename: $directory . '/.data/podcast/generation.lock', mode: 'c+');
    flock($lock, LOCK_EX);
    $assert(
        condition: !$podcast->generateDaily(newspaperHtml: $html, generatedAt: $stamp + 4 * 86_400),
        message: 'A concurrent launcher must not create another job.'
    );
    $assert(
        condition: !is_dir($directory . '/.data/podcast/2026-11-19'),
        message: 'A refused launch must leave no dated job.'
    );
    flock($lock, LOCK_UN);
    fclose($lock);
    $podcast->generateDaily(newspaperHtml: '', generatedAt: $stamp + 2 * 86_400);
    $assert(
        condition: !is_dir($directory . '/.data/podcast/2026-11-17'),
        message: 'An empty newspaper must not consume quota.'
    );

    $httpDirectory = $directory . '/http';
    mkdir(directory: $httpDirectory . '/.data', permissions: 0o700, recursive: true);
    $password = bin2hex(random_bytes(16));
    file_put_contents(
        $httpDirectory . '/.data/.env',
        "AUTH_PASSWORD={$password}\nNOTEBOOKLM_PYTHON=" .
            __DIR__ .
            "/fixtures/podcast_worker.py\nNOTEBOOKLM_AUTH_JSON=private-test-session\n"
    );
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name(socket: $socket, remote: false);
    fclose($socket);
    $server = proc_open(
        command: [PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/podcast_http.php'],
        descriptor_spec: [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
        pipes: $pipes,
        cwd: null,
        env_vars: array_merge(getenv(), ['PODCAST_TEST_ROOT' => $httpDirectory])
    );
    try {
        stream_set_timeout(stream: $pipes[2], seconds: 5);
        $started = fgets($pipes[2]);
        $assert(
            condition: is_string($started) && str_contains($started, 'started'),
            message: 'The isolated HTTP server must start.'
        );
        $request = static function (string $path, string $cookie = '', ?array $data = null, string $range = '') use (
            $address
        ): array {
            $headers = $cookie !== '' ? "Cookie: extrablatt_auth={$cookie}\r\n" : '';
            $context = stream_context_create([
                'http' => [
                    'method' => $data === null ? 'GET' : 'POST',
                    'header' =>
                        $headers .
                        ($range === '' ? '' : "Range: {$range}\r\n") .
                        "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $data === null ? '' : http_build_query($data),
                    'ignore_errors' => true
                ]
            ]);
            $stream = fopen(
                filename: 'http://' . $address . $path,
                mode: 'r',
                use_include_path: false,
                context: $context
            );
            $body = stream_get_contents($stream);
            $metadata = stream_get_meta_data($stream);
            fclose($stream);
            return [(int) explode(' ', $metadata['wrapper_data'][0])[1], $body];
        };
        $expiry = (string) (time() + 3600);
        $cookie = $expiry . '.' . hash_hmac('sha256', $expiry, $invoke(method: 'authCookieKey', password: $password));
        [, $anonymous] = $request('/?podcast_audio=1&date=2026-11-15');
        $assert(
            condition: str_contains($anonymous, 'name="auth_password"'),
            message: 'Audio must be behind the login gate.'
        );
        $httpPodcast = new Podcast(
            rootDirectory: $httpDirectory,
            python: __DIR__ . '/fixtures/podcast_worker.py',
            authentication: 'private-test-session'
        );
        $httpPodcast->generateDaily(newspaperHtml: $html, generatedAt: $stamp);
        for ($attempt = 0; $attempt < 100 && $httpPodcast->status('2026-11-15')->status === 'running'; $attempt++) {
            usleep(50_000);
        }
        [, $page] = $request('/?view=podcast', $cookie);
        $assert(
            condition: str_contains($page, '15.11.2026'),
            message: 'The authenticated list must show the episode date.'
        );
        [, $home] = $request('/', $cookie);
        $assert(
            condition: str_contains($home, 'viewnav__tab--active" href="/?view=podcast">Podcast</a>') &&
                str_contains($home, '15.11.2026'),
            message: 'The authenticated homepage must show the Podcast archive.'
        );
        [, $newspaper] = $request('/?view=zeitung', $cookie);
        $assert(
            condition: str_contains($newspaper, 'viewnav__tab--active" href="/?view=zeitung">Zeitung</a>') &&
                !str_contains($newspaper, '<section class="podcast"'),
            message: 'The explicit Zeitung route must remain available.'
        );
        $assert(
            condition: !str_contains($page, 'podcast_test'),
            message: 'The daily list must not offer a quota-consuming test POST.'
        );
        [, $status] = $request('/?podcast_status=1', $cookie);
        $assert(
            condition: json_decode(json: $status, associative: true)[0]['status'] === 'completed',
            message: 'The status API must expose daily episodes.'
        );
        [$code, $audio] = $request('/?podcast_audio=1&date=2026-11-15', $cookie);
        $assert(
            condition: $code === 200 && $audio === 'fixture audio',
            message: 'The authenticated audio route must return the stored bytes.'
        );
        [$code] = $request('/?podcast_audio=1&date=../2026-11-15', $cookie);
        $assert(condition: $code === 404, message: 'Audio dates must not permit directory traversal.');
        [$code, $part] = $request('/?podcast_audio=1&date=2026-11-15', $cookie, range: 'bytes=0-6');
        $assert(
            condition: $code === 206 && $part === 'fixture',
            message: 'Audio seeking must return only the requested byte range.'
        );
        [$code, $part] = $request('/?podcast_audio=1&date=2026-11-15', $cookie, range: 'bytes=-5');
        $assert(condition: $code === 206 && $part === 'audio', message: 'Audio suffix ranges must be supported.');
        [$code, $part] = $request('/?podcast_audio=1&date=2026-11-15', $cookie, range: 'bytes=1000-');
        $assert(
            condition: $code === 416 && $part === '',
            message: 'Unsatisfiable audio ranges must not return the entire file.'
        );
        $assert(
            condition: !str_contains($page . $status, 'private-test-session'),
            message: 'HTTP responses must not leak Google authentication.'
        );
    } finally {
        proc_terminate($server);
        fclose($pipes[2]);
        proc_close($server);
    }
} finally {
    $_GET = [];
    foreach (
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        )
        as $file
    ) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}

echo "podcast feature tests passed\n";
