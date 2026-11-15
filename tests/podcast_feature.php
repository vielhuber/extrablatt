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
        condition: str_contains($dashboard, 'NOTEBOOKLM_AUTH_JSON'),
        message: 'Missing configuration must be explained.'
    );
    $assert(condition: !str_contains($dashboard, '<audio '), message: 'Missing audio must not render a broken player.');

    $_GET = ['view' => 'podcast'];
    ob_start();
    $application->run();
    $routed = (string) ob_get_clean();
    $_GET = [];
    $assert(
        condition: str_contains($routed, 'Podcast-Test'),
        message: 'The public router must recognize the Podcast view.'
    );

    $podcast = new Podcast(rootDirectory: $directory, python: '', authentication: '');
    $assert(condition: !$podcast->configured(), message: 'Missing configuration must disable generation.');
    $assert(condition: $podcast->status()->status === 'idle', message: 'A fresh installation must be idle.');
    ob_start();
    $podcast->serveAudio();
    $assert(condition: ob_get_clean() === '', message: 'Missing audio must not leak a filesystem error.');
    $assert(condition: http_response_code() === 404, message: 'Missing audio must return 404.');

    $podcast = new Podcast(
        rootDirectory: $directory,
        python: __DIR__ . '/fixtures/podcast_worker.py',
        authentication: 'private-test-session'
    );
    $assert(condition: $podcast->configured(), message: 'A configured executable must enable generation.');
    $assert(
        condition: !str_contains($podcast->render('csrf-token'), 'private-test-session'),
        message: 'Authentication must never reach the page.'
    );
    $assert(
        condition: str_contains($podcast->render('csrf-token'), 'name="podcast_token" value="csrf-token"'),
        message: 'The form must carry a CSRF token.'
    );
    $assert(
        condition: str_contains($podcast->render(''), 'disabled'),
        message: 'Generation must require application authentication.'
    );

    mkdir(directory: $directory . '/.data/podcast', permissions: 0o700);
    $lock = fopen(filename: $directory . '/.data/podcast/generation.lock', mode: 'c+');
    flock($lock, LOCK_EX);
    $podcast->generate();
    $assert(condition: http_response_code() === 409, message: 'A concurrent generation must be refused.');
    $assert(
        condition: !is_file($directory . '/.data/podcast/invocations'),
        message: 'A refused generation must not invoke Python.'
    );
    flock($lock, LOCK_UN);
    fclose($lock);

    http_response_code(200);
    $podcast->generate();
    $assert(
        condition: $podcast->status()->status === 'completed',
        message: 'A successful worker must expose the finished audio.'
    );
    $assert(condition: $podcast->status()->hasAudio, message: 'Completed generation must have audio.');
    $assert(
        condition: str_contains($podcast->render('csrf-token'), '<audio controls preload="metadata"'),
        message: 'Completed audio must be playable.'
    );
    $assert(
        condition: str_contains($podcast->render('csrf-token'), '/?podcast_audio=1'),
        message: 'Audio must use an authenticated route.'
    );
    $podcast->generate();
    $assert(
        condition: file_get_contents($directory . '/.data/podcast/invocations') === '1',
        message: 'Reloads and repeated submissions must not create another podcast.'
    );
    $assert(
        condition: !str_contains(json_encode($podcast->status()), 'private-test-session'),
        message: 'Public status must not expose authentication.'
    );

    unlink($directory . '/.data/podcast/test.m4a');
    file_put_contents(
        filename: $directory . '/.data/podcast/status.json',
        data: '{"stage":"auth_error","message":"private-test-session"}'
    );
    $assert(condition: $podcast->status()->status === 'error', message: 'Authentication failures must be visible.');
    $assert(
        condition: !str_contains($podcast->render('csrf-token'), 'private-test-session'),
        message: 'Raw worker errors must not be rendered.'
    );
    $podcast->generate();
    $assert(
        condition: file_get_contents($directory . '/.data/podcast/invocations') === '1',
        message: 'Failed or uncertain jobs must not be repeated blindly.'
    );

    file_put_contents(filename: $directory . '/.data/podcast/status.json', data: '{');
    $assert(
        condition: $podcast->status()->status === 'error',
        message: 'Corrupt state must not allow a second generation.'
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
        [, $anonymous] = $request('/?podcast_audio=1');
        $assert(
            condition: str_contains($anonymous, 'name="auth_password"'),
            message: 'Audio must be behind the login gate.'
        );
        [, $page] = $request('/?view=podcast', $cookie);
        preg_match('~name="podcast_token" value="([^"]+)"~', $page, $match);
        $assert(condition: isset($match[1]), message: 'An authenticated page must expose its form token.');
        [$code] = $request('/?view=podcast', $cookie, ['podcast_test' => '1']);
        $assert(condition: $code === 403, message: 'A POST without a CSRF token must be rejected.');
        $assert(
            condition: !is_dir($httpDirectory . '/.data/podcast'),
            message: 'A rejected POST must not start the worker.'
        );
        [$code, $result] = $request('/?view=podcast', $cookie, ['podcast_test' => '1', 'podcast_token' => $match[1]]);
        $assert(
            condition: $code === 200 && json_decode(json: $result, associative: true)['hasAudio'],
            message: 'An authenticated POST must complete the test.'
        );
        [, $status] = $request('/?podcast_status=1', $cookie);
        $assert(
            condition: json_decode(json: $status, associative: true)['status'] === 'completed',
            message: 'Reloads must recover the persisted status.'
        );
        [$code, $audio] = $request('/?podcast_audio=1', $cookie);
        $assert(
            condition: $code === 200 && $audio === 'fixture audio',
            message: 'The authenticated audio route must return the stored bytes.'
        );
        [$code, $part] = $request('/?podcast_audio=1', $cookie, range: 'bytes=0-6');
        $assert(
            condition: $code === 206 && $part === 'fixture',
            message: 'Audio seeking must return only the requested byte range.'
        );
        [$code, $part] = $request('/?podcast_audio=1', $cookie, range: 'bytes=-5');
        $assert(condition: $code === 206 && $part === 'audio', message: 'Audio suffix ranges must be supported.');
        [$code, $part] = $request('/?podcast_audio=1', $cookie, range: 'bytes=1000-');
        $assert(
            condition: $code === 416 && $part === '',
            message: 'Unsatisfiable audio ranges must not return the entire file.'
        );
        $assert(
            condition: !str_contains($page . $status . $result, 'private-test-session'),
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
