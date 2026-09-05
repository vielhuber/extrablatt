<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Extrablatt.php';

use vielhuber\extrablatt\Extrablatt;

$rootDirectory = sys_get_temp_dir() . '/extrablatt-pnp-' . bin2hex(string: random_bytes(length: 8));
mkdir(directory: $rootDirectory . '/.data', permissions: 0755, recursive: true);
file_put_contents(
    filename: $rootDirectory . '/.data/config.json',
    data: (string) json_encode(
        value: [
            'papers' => [
                'pnp' => [
                    'url' => 'https://www.pnp.de',
                    'label' => 'Passauer Neue Presse',
                    'rss' => 'https://www.pnp.de/sitemap-1.xml'
                ],
                'other' => ['url' => 'https://example.com', 'label' => 'Other', 'rss' => 'https://example.com/rss'],
                'tv' => ['url' => 'https://www.zdf.de', 'label' => 'Talk', 'rss' => 'zdfmediathek://talk']
            ]
        ]
    )
);
$application = new Extrablatt(rootDir: $rootDirectory);
$invoke = static function (string $method, mixed ...$arguments) use ($application): mixed {
    return (new ReflectionMethod(objectOrMethod: $application, method: $method))->invoke($application, ...$arguments);
};
$assertContains = static function (string $needle, string $haystack): void {
    if (!str_contains(haystack: $haystack, needle: $needle)) {
        throw new RuntimeException(message: 'Expected output to contain: ' . $needle);
    }
};
$assertNotContains = static function (string $needle, string $haystack): void {
    if (str_contains(haystack: $haystack, needle: $needle)) {
        throw new RuntimeException(message: 'Expected output not to contain: ' . $needle);
    }
};
$route = static function (array $parameters) use ($application): string {
    $_GET = $parameters;
    ob_start();
    $application->run();
    $html = (string) ob_get_clean();
    $_GET = [];
    return $html;
};

try {
    $database = $invoke('openDatabase');
    $statement = $database->prepare(
        query: 'INSERT INTO articles
        (url, paper, title, category, status, published_at, created_at, updated_at, read_at)
        VALUES (?, ?, ?, ?, "original", ?, ?, ?, ?)'
    );
    foreach (
        [
            ['https://www.pnp.de/lokales/passau/verein', 'pnp', 'Local football fixture', 'Fußball', null],
            ['https://www.pnp.de/nachrichten/bayern/gemeinde', 'pnp', 'Regional fixture', 'Bayern', null],
            ['https://www.pnp.de/lokales/passau/neu', 'pnp', 'Uncategorized local fixture', null, null],
            ['https://www.pnp.de/nachrichten/politik/bundestag', 'pnp', 'National fixture', 'Innenpolitik', null],
            [
                'https://www.pnp.de/nachrichten/wirtschaft/bahn',
                'pnp',
                'National traffic fixture',
                'Verkehr & Infrastruktur',
                null
            ],
            ['https://www.pnp.de/verlag/lokales', 'pnp', 'Publisher fixture', 'Bayern', null],
            ['https://example.com/lokales/passau/verein', 'other', 'Other source fixture', 'Bayern', null],
            ['https://www.pnp.de/lokales/passau/gelesen', 'pnp', 'Read local fixture', 'Bayern', time()],
            ['https://www.zdf.de/talk/episode', 'tv', 'Talk fixture', 'TV', null]
        ]
        as [$url, $paper, $title, $category, $readAt]
    ) {
        $statement->execute(params: [$url, $paper, $title, $category, time(), time(), time(), $readAt]);
    }

    $invoke('cacheSet', 'bild_home', '<html></html>');
    $bild = $route(['view' => 'bild']);
    $assertContains('href="/?view=pnp" aria-label="Nächster Tab"', $bild);
    $assertContains('Seite 3 / 10', $bild);

    $dashboard = $route(['view' => 'pnp']);
    $assertContains(
        'href="/?view=bild">BILD</a><a class="viewnav__tab viewnav__tab--active" href="/?view=pnp">PNP</a>',
        (string) preg_replace(pattern: '/>\s+</', replacement: '><', subject: $dashboard)
    );
    $assertContains('<span>PNP</span>', $dashboard);
    $assertContains('Seite 4 / 10', $dashboard);
    $assertContains('href="/?view=bild" aria-label="Vorheriger Tab"', $dashboard);
    $assertContains('href="/?view=reddit" aria-label="Nächster Tab"', $dashboard);
    $assertContains('name="view" value="pnp"', $dashboard);
    foreach (['Local football fixture', 'Regional fixture', 'Uncategorized local fixture'] as $title) {
        $assertContains($title, $dashboard);
    }
    foreach (
        [
            'National fixture',
            'National traffic fixture',
            'Publisher fixture',
            'Other source fixture',
            'Read local fixture',
            'Talk fixture'
        ]
        as $title
    ) {
        $assertNotContains($title, $dashboard);
    }
    $assertNotContains('name="paper"', $dashboard);
    $assertNotContains('name="tv"', $dashboard);

    $filtered = $route([
        'view' => 'pnp',
        'category' => 'Fußball',
        'paper' => 'other',
        'tv' => 'all',
        'media' => 'reddit'
    ]);
    $assertContains('Local football fixture', $filtered);
    $assertNotContains('Regional fixture', $filtered);
    $assertNotContains('Other source fixture', $filtered);
    $assertNotContains('Talk fixture', $filtered);
    $magic = $route(['view' => 'pnp', 'magic' => '']);
    $assertNotContains('Local football fixture', $magic);
    $assertContains('<option value="" selected>', $magic);
    $read = $route(['view' => 'pnp', 'read' => 'read']);
    $assertContains('Read local fixture', $read);
    $assertNotContains('Local football fixture', $read);

    $allNews = $route(['view' => 'meldungen', 'magic' => 'all']);
    $assertContains('National fixture', $allNews);
    $assertContains('Other source fixture', $allNews);
    $assertContains('Seite 8 / 10', $allNews);
    $talkshows = $route(['view' => 'talkshows']);
    $assertContains('Talk fixture', $talkshows);
    $assertNotContains('Local football fixture', $talkshows);
    $reddit = $route(['view' => 'reddit']);
    $assertContains('href="/?view=pnp" aria-label="Vorheriger Tab"', $reddit);
    $assertContains('Seite 5 / 10', $reddit);
} finally {
    $_GET = [];
    foreach (glob(pattern: $rootDirectory . '/.data/*') as $file) {
        unlink(filename: $file);
    }
    rmdir(directory: $rootDirectory . '/.data');
    rmdir(directory: $rootDirectory);
}

echo "PNP regional news tests passed\n";
