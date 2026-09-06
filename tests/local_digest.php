<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Extrablatt.php';

use vielhuber\extrablatt\Extrablatt;

final class LocalDigestAiStub
{
    public static function create(mixed ...$arguments): self
    {
        return new self();
    }

    public function ask(string $prompt): array
    {
        if (str_contains(haystack: $prompt, needle: 'Zeitungs-Chefredakteur')) {
            return [
                'response' => [
                    'top_today' => null,
                    'items' => [['paragraph' => 'Allgemeine Wochenmeldungen.', 'sources' => [1]]]
                ]
            ];
        }
        foreach (['Local football fixture', 'Regional fixture', 'Read local fixture'] as $title) {
            if (!str_contains(haystack: $prompt, needle: $title)) {
                throw new RuntimeException(message: 'Missing regional headline: ' . $title);
            }
        }
        foreach (
            ['National fixture', 'National traffic fixture', 'Other source fixture', 'Old fixture', 'Duplicate fixture']
            as $title
        ) {
            if (str_contains(haystack: $prompt, needle: $title)) {
                throw new RuntimeException(message: 'Unexpected headline: ' . $title);
            }
        }
        if (
            preg_match(
                pattern: '/^(\d+)\. \[[^\n]+\] Local football fixture$/m',
                subject: $prompt,
                matches: $matches
            ) !== 1
        ) {
            throw new RuntimeException(message: 'Missing numbered source.');
        }
        $number = (int) $matches[1];
        return [
            'response' => [
                'paragraph' => 'In [Passau](' . $number . ') steht der lokale Sport im Mittelpunkt.',
                'sources' => [$number]
            ]
        ];
    }
}
class_alias(class: LocalDigestAiStub::class, alias: 'vielhuber\\aihelper\\aihelper');

$rootDirectory = sys_get_temp_dir() . '/extrablatt-local-digest-' . bin2hex(string: random_bytes(length: 8));
mkdir(directory: $rootDirectory . '/.data', permissions: 0755, recursive: true);
file_put_contents(
    filename: $rootDirectory . '/.data/config.json',
    data: '{"papers":{"pnp":{"url":"https://www.pnp.de","label":"Passauer Neue Presse","rss":"https://www.pnp.de/sitemap-1.xml"}}}'
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

try {
    $database = $invoke('openDatabase');
    $statement = $database->prepare(
        query: 'INSERT INTO articles
        (url, paper, title, published_at, status, created_at, updated_at, read_at, duplicate_of)
        VALUES (?, ?, ?, ?, "original", ?, ?, ?, ?)'
    );
    foreach (
        [
            ['https://www.pnp.de/lokales/passau/sport', 'pnp', 'Local football fixture', time(), null, null],
            [
                'https://www.pnp.de/nachrichten/bayern/gemeinde',
                'pnp',
                'Regional fixture',
                time() - 6 * 86400,
                null,
                null
            ],
            ['https://www.pnp.de/lokales/passau/gelesen', 'pnp', 'Read local fixture', time(), time(), null],
            ['https://www.pnp.de/nachrichten/politik/bundestag', 'pnp', 'National fixture', time(), null, null],
            ['https://www.pnp.de/nachrichten/wirtschaft/bahn', 'pnp', 'National traffic fixture', time(), null, null],
            ['https://example.com/lokales/passau/verein', 'other', 'Other source fixture', time(), null, null],
            ['https://www.pnp.de/lokales/passau/alt', 'pnp', 'Old fixture', time() - 8 * 86400, null, null],
            [
                'https://www.pnp.de/lokales/passau/doppelt',
                'pnp',
                'Duplicate fixture',
                time(),
                null,
                'https://www.pnp.de/lokales/passau/sport'
            ]
        ]
        as [$url, $paper, $title, $publishedAt, $readAt, $duplicateOf]
    ) {
        $statement->execute(params: [$url, $paper, $title, $publishedAt, time(), time(), $readAt, $duplicateOf]);
    }
    $invoke('cacheSet', 'crypto_market_year', '{"assets":{}}');
    $invoke(
        'generateDailyDigest',
        $database,
        ['provider' => 'fixture', 'model' => 'fixture'],
        'fixture',
        static function (string $message): void {}
    );
    $digest = json_decode(json: $invoke('cacheGet', 'daily_digest'), associative: true);
    if (($digest['local']['count'] ?? null) !== 3) {
        throw new RuntimeException(message: 'Expected three eligible local headlines in the saved digest.');
    }
    if (($digest['local']['sources'][0]['url'] ?? null) !== 'https://www.pnp.de/lokales/passau/sport') {
        throw new RuntimeException(message: 'Expected a resolved regional source link.');
    }
    $digest['crypto'] = ['prose' => 'Kryptowährungen im Wochenvergleich.'];
    $invoke('cacheSet', 'daily_digest', (string) json_encode(value: $digest));
    $localDashboard = $invoke('renderDashboard', '', '', '', '', '', '', '', '', '', '', 'zeitung');
    $assertContains('Kryptowährungen', $localDashboard);
    $assertContains('Lokales &amp; Regionales', $localDashboard);
    $assertContains('letzte 7 Tage', $localDashboard);
    $assertContains('steht der lokale Sport im Mittelpunkt.', $localDashboard);
    $assertContains('Allgemeine Wochenmeldungen.', $localDashboard);
    $assertContains('href="https://www.pnp.de/lokales/passau/sport"', $localDashboard);
    if (
        strpos(haystack: $localDashboard, needle: 'Kryptowährungen') >=
        strpos(haystack: $localDashboard, needle: 'Lokales &amp; Regionales')
    ) {
        throw new RuntimeException(message: 'Expected the regional block after cryptocurrencies.');
    }

    unset($digest['local']);
    $invoke('cacheSet', 'daily_digest', (string) json_encode(value: $digest));
    $legacy = $invoke('renderDigestHtml');
    $assertContains('Allgemeine Wochenmeldungen.', $legacy);
    if (str_contains(haystack: $legacy, needle: 'Lokales &amp; Regionales')) {
        throw new RuntimeException(message: 'Legacy digests must not invent regional prose.');
    }
    $database->exec(
        statement: "DELETE FROM articles WHERE url LIKE 'https://www.pnp.de/lokales/%' OR url LIKE 'https://www.pnp.de/nachrichten/bayern/%'"
    );
    $invoke(
        'generateDailyDigest',
        $database,
        ['provider' => 'fixture', 'model' => 'fixture'],
        'fixture',
        static function (string $message): void {}
    );
    $empty = json_decode(json: $invoke('cacheGet', 'daily_digest'), associative: true);
    if (($empty['local'] ?? null) !== null) {
        throw new RuntimeException(message: 'Expected no local block without eligible news.');
    }
} finally {
    foreach (glob(pattern: $rootDirectory . '/.data/*') as $file) {
        unlink(filename: $file);
    }
    rmdir(directory: $rootDirectory . '/.data');
    rmdir(directory: $rootDirectory);
}

echo "Local digest tests passed\n";
