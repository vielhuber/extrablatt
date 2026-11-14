<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Extrablatt.php';

use vielhuber\extrablatt\Extrablatt;
use vielhuber\extrablatt\FeedItem;

$rootDirectory = sys_get_temp_dir() . '/extrablatt-hn-images-' . bin2hex(string: random_bytes(length: 8));
mkdir(directory: $rootDirectory . '/.data', permissions: 0o755, recursive: true);
mkdir(directory: $rootDirectory . '/.bin', permissions: 0o755);
$threadUrl = 'https://news.ycombinator.com/item?id=1';
$selfUrl = 'https://news.ycombinator.com/item?id=2';
$freshUrl = 'https://news.ycombinator.com/item?id=3';
$imageUrl = 'https://example.com/cover.jpg?width=600&height=400';
$faviconUrl = 'https://www.google.com/s2/favicons?domain=example.com&sz=128';
$hackerNewsFavicon = 'https://www.google.com/s2/favicons?domain=news.ycombinator.com&sz=128';
$fallbackUrl = 'https://news.ycombinator.com/item?id=4';
$brokenUrl = 'https://news.ycombinator.com/item?id=5';
$blockedUrl = 'https://news.ycombinator.com/item?id=6';
$image = imagecreatetruecolor(width: 320, height: 240);
ob_start();
imagejpeg(image: $image);
$imageBytes = (string) ob_get_clean();
$fixtures = [
    $threadUrl => '<span class="titleline"><a href="https://example.com/story">Story</a></span><img src="y18.gif">',
    $freshUrl => '<span class="titleline"><a href="https://example.com/story">Fresh</a></span>',
    $selfUrl => '<span class="titleline"><a href="item?id=2">Ask HN</a></span><img src="y18.gif">',
    $fallbackUrl => '<span class="titleline"><a href="https://example.com/no-image">No image</a></span>',
    $brokenUrl => '<span class="titleline"><a href="https://example.com/unavailable.jpg">Broken image</a></span>',
    $blockedUrl => '<span class="titleline"><a href="https://example.com/blocked">Blocked article</a></span>',
    'https://example.com/story' => '<meta content="/cover.jpg?width=600&amp;height=400" property="og:image">',
    'https://example.com/no-image' => '<html><body>No preview image.</body></html>',
    'https://example.com/twitter' => '<meta content="//images.example.com/twitter.png" name="twitter:image">',
    'https://example.com/inline' => '<body><img src="images/photo.webp"></body>',
    'https://example.com/unsafe' => '<meta property="og:image" content="javascript:alert(1)">',
    $imageUrl => 'data:image/jpeg;base64,' . base64_encode(string: $imageBytes),
    $faviconUrl => 'data:image/jpeg;base64,' . base64_encode(string: $imageBytes),
    $hackerNewsFavicon => 'data:image/jpeg;base64,' . base64_encode(string: $imageBytes)
];
$expectedImages = [$threadUrl => $imageUrl];
$feed = '<rss version="2.0"><channel>';
foreach (range(start: 2, end: 10) as $id) {
    $url = 'https://news.ycombinator.com/item?id=' . $id;
    $fixtures[$url] ??= '<span class="titleline"><a href="https://example.com/story">Story ' . $id . '</a></span>';
    $expectedImages[$url] = match ($id) {
        2 => $hackerNewsFavicon,
        4, 5, 6 => $faviconUrl,
        default => $imageUrl
    };
    $feed .=
        '<item><title>Story ' .
        $id .
        '</title><link>' .
        $url .
        '</link><pubDate>' .
        gmdate(format: DATE_RSS) .
        '</pubDate></item>';
}
$fixtures['https://example.com/feed'] = $feed . '</channel></rss>';
file_put_contents(filename: $rootDirectory . '/.data/fixtures.json', data: (string) json_encode(value: $fixtures));
file_put_contents(
    filename: $rootDirectory . '/.bin/curl_chrome123',
    data: <<<'PHP'
    #!/usr/bin/env php
    <?php
    declare(strict_types=1);
    $fixtures = json_decode(file_get_contents(__DIR__ . '/../.data/fixtures.json'), true);
    $url = '';
    $destination = null;
    foreach ($argv as $index => $argument) {
        if (str_starts_with($argument, 'https://')) {
            $url = $argument;
        }
        if ($argument === '-o') {
            $destination = $argv[$index + 1];
        }
    }
    $body = $fixtures[$url] ?? '';
    if (str_starts_with($url, 'https://news.ycombinator.com/') && in_array('-w', $argv, true)) {
        $body = '';
    }
    if (str_starts_with($body, 'data:image/jpeg;base64,')) {
        $body = base64_decode(substr($body, 23), true);
    }
    if ($destination !== null) {
        file_put_contents($destination, $body);
        if (in_array('-w', $argv, true)) {
            echo ($body === '' ? '404' : '200') . '|' . $url;
        }
        exit;
    }
    echo $body;
    PHP
);
chmod(filename: $rootDirectory . '/.bin/curl_chrome123', permissions: 0o755);
$application = new Extrablatt(rootDir: $rootDirectory);
$invoke = static fn(string $method, mixed ...$arguments): mixed => (new ReflectionMethod(
    objectOrMethod: $application,
    method: $method
))->invoke($application, ...$arguments);
$assertSame = static function (mixed $expected, mixed $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException(message: 'Unexpected Hacker News image result.');
    }
};
$server = null;

try {
    $socket = stream_socket_server(address: 'tcp://127.0.0.1:0');
    $address = stream_socket_get_name(socket: $socket, remote: false);
    fclose(stream: $socket);
    file_put_contents(filename: $rootDirectory . '/.data/models', data: '{}');
    file_put_contents(
        filename: $rootDirectory . '/.data/router.php',
        data: '<?php declare(strict_types=1); $url = "https://news.ycombinator.com" . $_SERVER["REQUEST_URI"];' .
            'if (str_starts_with($_SERVER["REQUEST_URI"], "/item?")) {' .
            '$fixtures = json_decode(file_get_contents(__DIR__ . "/fixtures.json"), true);' .
            'echo $fixtures[$url] ?? ""; return;} return false;'
    );
    $server = proc_open(
        command: [PHP_BINARY, '-S', $address, '-t', $rootDirectory . '/.data', $rootDirectory . '/.data/router.php'],
        descriptor_spec: [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        pipes: $pipes
    );
    fclose(stream: $pipes[0]);
    $probe = curl_init(url: 'http://' . $address . '/models');
    curl_setopt_array(handle: $probe, options: [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 100]);
    for ($attempt = 0; $attempt < 100; $attempt++) {
        if (curl_exec(handle: $probe) !== false) {
            break;
        }
        usleep(microseconds: 10_000);
    }
    $assertSame(200, curl_getinfo(handle: $probe, option: CURLINFO_HTTP_CODE));
    putenv(assignment: 'EXTRABLATT_HN_FIXTURE_URL=http://' . $address);
    require __DIR__ . '/fixtures/hackernews_http.php';
    $assertSame($imageUrl, $invoke('extractImageFromPage', $threadUrl));
    $assertSame($hackerNewsFavicon, $invoke('extractImageFromPage', $selfUrl));
    $assertSame($faviconUrl, $invoke('extractImageFromPage', $fallbackUrl));
    $assertSame($faviconUrl, $invoke('extractImageFromPage', $blockedUrl));
    $assertSame(
        'https://images.example.com/twitter.png',
        $invoke('extractImageFromPage', pageUrl: 'https://example.com/twitter')
    );
    $assertSame(
        'https://example.com/images/photo.webp',
        $invoke('extractImageFromPage', pageUrl: 'https://example.com/inline')
    );
    $assertSame(null, $invoke('extractImageFromPage', pageUrl: 'https://example.com/unsafe'));
    $invoke('cacheSet', key: 'bodyimg:' . md5(string: $threadUrl), value: '{"url":""}');
    $invoke('cacheSet', key: 'bodyimg:v2:' . md5(string: $threadUrl), value: '{"url":""}');
    $invoke('cacheSet', key: 'bodyimg:v3:' . md5(string: $threadUrl), value: '{"url":""}');
    $assertSame(false, $invoke('bodyImgCacheExists', $threadUrl));
    $invoke('writeBodyImgCache', $threadUrl, $imageUrl);
    $assertSame($imageUrl, $invoke('readBodyImgCache', $threadUrl));
    $thumbnails = $invoke(
        'downloadThumbnailsStreaming',
        [['paper' => 'hackernews', 'item' => new FeedItem('Story', $threadUrl, time(), $imageUrl)]],
        [$threadUrl => $imageUrl],
        static function (string $message): void {}
    );
    $thumbnail = $thumbnails[$threadUrl] ?? '';
    $assertSame(true, str_starts_with(haystack: $thumbnail, needle: 'data:image/jpeg;base64,'));
    $dimensions = getimagesizefromstring(
        string: base64_decode(string: substr(string: $thumbnail, offset: 23), strict: true)
    );
    $assertSame(160, $dimensions[0]);
    $assertSame(160, $dimensions[1]);

    file_put_contents(
        filename: $rootDirectory . '/.data/.env',
        data: "AI_PROVIDER=fixture\nAI_MODEL=fixture\nAI_BASE_URL=http://" . $address . "\n"
    );
    file_put_contents(
        filename: $rootDirectory . '/.data/config.json',
        data: '{"papers":{"hackernews":{"url":"https://news.ycombinator.com","label":"Hacker News","rss":"https://example.com/feed"}}}'
    );
    $database = $invoke('openDatabase');
    $statement = $database->prepare(
        query: 'INSERT INTO articles (url, paper, title, status, image_url, thumbnail, created_at, updated_at)
            VALUES (?, "hackernews", "Old", "original", ?, "old-favicon", ?, ?)'
    );
    $statement->execute(
        params: [$threadUrl, 'https://www.google.com/s2/favicons?domain=news.ycombinator.com', time(), time()]
    );
    file_put_contents(
        filename: $rootDirectory . '/.data/scrape.php',
        data: '<?php declare(strict_types=1); require ' .
            var_export(value: dirname(__DIR__) . '/src/Extrablatt.php', return: true) .
            '; require ' .
            var_export(value: __DIR__ . '/fixtures/hackernews_http.php', return: true) .
            '; $app = new vielhuber\\extrablatt\\Extrablatt(dirname(__DIR__));' .
            '(new ReflectionMethod($app, "runScrape"))->invoke($app);'
    );
    for ($scrape = 0; $scrape < 2; $scrape++) {
        exec(
            command: escapeshellarg(arg: PHP_BINARY) . ' ' . escapeshellarg(arg: $rootDirectory . '/.data/scrape.php'),
            output: $scrapeOutput,
            result_code: $exitCode
        );
        $assertSame(0, $exitCode);
        $rows = $database
            ->query(query: 'SELECT url, image_url, thumbnail FROM articles')
            ->fetchAll(mode: PDO::FETCH_ASSOC);
        $assertSame(10, count(value: $rows));
        foreach ($rows as $row) {
            $assertSame($expectedImages[$row['url']], $row['image_url']);
            $assertSame(true, str_starts_with(haystack: $row['thumbnail'], needle: 'data:image/jpeg;base64,'));
        }
    }
    $priorityStatement = $database->prepare(
        query: 'UPDATE articles SET published_at=?, rating=5000, image_url=?, thumbnail="old-favicon" WHERE url=?'
    );
    $priorityStatement->execute(params: [time() - 86_400, $hackerNewsFavicon, $threadUrl]);
    $insert = $database->prepare(
        query: 'INSERT INTO articles (url, paper, title, status, rating, published_at, image_url, thumbnail, created_at, updated_at)
            VALUES (?, "hackernews", ?, "original", 1, ?, ?, "old-favicon", ?, ?)'
    );
    foreach (range(start: 100, end: 160) as $id) {
        $url = 'https://news.ycombinator.com/item?id=' . $id;
        $insert->execute(params: [$url, 'Low-ranked ' . $id, time(), $hackerNewsFavicon, time(), time()]);
        $invoke('writeBodyImgCache', $url, $imageUrl);
    }
    exec(
        command: escapeshellarg(arg: PHP_BINARY) . ' ' . escapeshellarg(arg: $rootDirectory . '/.data/scrape.php'),
        output: $scrapeOutput,
        result_code: $exitCode
    );
    $assertSame(0, $exitCode);
    $check = $database->prepare(query: 'SELECT image_url FROM articles WHERE url=?');
    $check->execute(params: [$threadUrl]);
    $assertSame($imageUrl, $check->fetchColumn());
} finally {
    putenv(assignment: 'EXTRABLATT_HN_FIXTURE_URL');
    if (is_resource(value: $server)) {
        proc_terminate(process: $server);
        proc_close(process: $server);
    }
    if (is_file(filename: $rootDirectory . '/.data/.env')) {
        unlink(filename: $rootDirectory . '/.data/.env');
    }
    foreach (glob(pattern: $rootDirectory . '/.data/*') as $file) {
        unlink(filename: $file);
    }
    unlink(filename: $rootDirectory . '/.bin/curl_chrome123');
    rmdir(directory: $rootDirectory . '/.bin');
    rmdir(directory: $rootDirectory . '/.data');
    foreach (glob(pattern: $rootDirectory . '/.logs/*') ?: [] as $file) {
        unlink(filename: $file);
    }
    if (is_dir(filename: $rootDirectory . '/.logs')) {
        rmdir(directory: $rootDirectory . '/.logs');
    }
    rmdir(directory: $rootDirectory);
}

echo "Hacker News image tests passed\n";
