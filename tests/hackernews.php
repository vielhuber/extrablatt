<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Extrablatt.php';

use vielhuber\extrablatt\Extrablatt;

$rootDirectory = sys_get_temp_dir() . '/extrablatt-hackernews-' . bin2hex(string: random_bytes(length: 8));
mkdir(directory: $rootDirectory . '/.data', permissions: 0755, recursive: true);
file_put_contents(
    filename: $rootDirectory . '/.data/config.json',
    data: '{"papers":{"hackernews":{"url":"https://news.ycombinator.com","label":"Hacker News","rss":"hackernews://best"}}}'
);
$application = new Extrablatt(rootDir: $rootDirectory);
$invoke = static function (string $method, mixed ...$arguments) use ($application): mixed {
    return (new ReflectionMethod(objectOrMethod: $application, method: $method))->invoke($application, ...$arguments);
};
$assertSame = static function (mixed $expected, mixed $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            message: 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
};
$story = static function (int $id, string $age, ?int $score = 100): string {
    $scoreHtml = $score === null ? '' : '<span class="score" id="score_' . $id . '">' . $score . ' points</span>';
    return '<tr class="athing submission" id="' .
        $id .
        '"><td><span class="titleline">' .
        '<a href="https://example.com/story">Story &amp; ' .
        $id .
        '</a></span></td></tr>' .
        '<tr><td class="subtext">' .
        $scoreHtml .
        $age .
        '</td></tr>';
};

try {
    foreach (
        [
            ['2026-09-08T05:42:28.000000Z', 1788846148],
            ['2026-09-08T05:42:28Z', 1788846148],
            ['2026-09-08T07:42:28+02:00', 1788846148],
            ['2026-09-08T05:42:28 1788846148', 1788846148],
            ['invalid', null],
            ['', null]
        ]
        as [$age, $expected]
    ) {
        $items = $invoke('parseHackerNewsBest', $story(49605915, '<span class="age" title="' . $age . '"></span>'));
        $assertSame(1, count($items));
        $assertSame($expected, $items[0]->publishedAt);
        $assertSame('Story & 49605915', $items[0]->title);
        $assertSame('https://news.ycombinator.com/item?id=49605915', $items[0]->link);
        $assertSame(100, $items[0]->rating);
    }
    $assertSame(null, $invoke('parseHackerNewsBest', $story(1, ''))[0]->publishedAt);
    $assertSame([], $invoke('parseHackerNewsBest', $story(1, '', null)));
    $assertSame([], $invoke('parseHackerNewsBest', '<html></html>'));

    $html = '';
    for ($id = 1; $id <= 12; $id++) {
        $published = time() - ($id === 12 ? 8 * 86400 : 3600);
        $html .= $story(
            $id,
            '<span class="age" title="' . gmdate('Y-m-d\TH:i:s', $published) . '.000000Z"></span>',
            $id
        );
    }
    $invoke('cacheSet', 'feed:hackernews:p1', $html);
    $invoke('cacheSet', 'feed:hackernews:p2', '<html></html>');
    $items = $invoke('fetchFeedItems', 'hackernews');
    $assertSame(12, count($items));
    $database = $invoke('openDatabase');
    $statement = $database->prepare(
        'INSERT INTO articles (url, paper, title, status, rating, published_at, created_at, updated_at)
        VALUES (?, "hackernews", ?, "original", ?, ?, ?, ?)'
    );
    foreach ($items as $item) {
        $statement->execute([$item->link, $item->title, $item->rating, $item->publishedAt, time(), time()]);
    }
    $_GET = ['view' => 'hackernews'];
    ob_start();
    $application->run();
    $dashboard = (string) ob_get_clean();
    foreach (range(2, 11) as $id) {
        $assertSame(true, str_contains($dashboard, '>Story &amp; ' . $id . '</a>'));
    }
    foreach ([1, 12] as $id) {
        $assertSame(false, str_contains($dashboard, '>Story &amp; ' . $id . '</a>'));
    }
    $assertSame(true, strpos($dashboard, '>Story &amp; 11</a>') < strpos($dashboard, '>Story &amp; 2</a>'));
} finally {
    $_GET = [];
    foreach (glob(pattern: $rootDirectory . '/.data/*') as $file) {
        unlink(filename: $file);
    }
    rmdir(directory: $rootDirectory . '/.data');
    rmdir(directory: $rootDirectory);
}

echo "Hacker News tests passed\n";
