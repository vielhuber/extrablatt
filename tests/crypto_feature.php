<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Extrablatt.php';

use vielhuber\extrablatt\Extrablatt;

$rootDirectory = sys_get_temp_dir() . '/extrablatt-crypto-' . bin2hex(string: random_bytes(length: 8));
mkdir(directory: $rootDirectory . '/.data', permissions: 0755, recursive: true);
$application = new Extrablatt(rootDir: $rootDirectory);
$invoke = static function (string $method, mixed ...$arguments) use ($application): mixed {
    return (new ReflectionMethod(objectOrMethod: $application, method: $method))->invoke($application, ...$arguments);
};
$assertContains = static function (string $needle, string $haystack): void {
    if (!str_contains(haystack: $haystack, needle: $needle)) {
        throw new RuntimeException(message: 'Expected output to contain: ' . $needle);
    }
};
$assertBefore = static function (string $first, string $second, string $haystack): void {
    $firstPosition = strpos(haystack: $haystack, needle: $first);
    $secondPosition = strpos(haystack: $haystack, needle: $second);
    if ($firstPosition === false || $secondPosition === false || $firstPosition >= $secondPosition) {
        throw new RuntimeException(message: 'Expected "' . $first . '" before "' . $second . '".');
    }
};

$marketData = [
    'assets' => [
        'bitcoin' => [
            'symbol' => 'BTC',
            'prices' => [[1_787_000_000_000, 60_000.0], [1_787_086_400_000, 63_000.0]],
        ],
        'ethereum' => [
            'symbol' => 'ETH',
            'prices' => [[1_787_000_000_000, 3_000.0], [1_787_086_400_000, 2_850.0]],
        ],
    ],
];
$invoke('cacheSet', 'crypto_market_year', (string) json_encode(value: $marketData));
$historyDays = (new ReflectionClass(objectOrClass: $application))->getConstant(name: 'CRYPTO_MARKET_HISTORY_DAYS');
if ($historyDays !== 365) {
    throw new RuntimeException(message: 'Expected one year of crypto history.');
}
$stats = $invoke('cryptoDigestStats', $marketData);
if (($stats['assets']['BTC']['change'] ?? null) !== 5.0 || ($stats['assets']['ETH']['change'] ?? null) !== -5.0) {
    throw new RuntimeException(message: 'Expected one-year changes for BTC and ETH.');
}

$dashboard = $invoke('renderDashboard', '', '', '', '', '', '', '', '', '', '', 'crypto');
$assertContains('main { max-width: 940px;', $dashboard);
$assertContains('href="/?view=crypto">Crypto</a>', $dashboard);
$assertBefore('href="/?view=crypto">Crypto</a>', 'href="/?view=watch">Gesundheit</a>', $dashboard);
$assertContains('BTC/EUR', $dashboard);
$assertContains('ETH/EUR', $dashboard);
$assertContains('BTC/EUR · 1 Jahr', $dashboard);
$assertContains('ETH/EUR · 1 Jahr', $dashboard);
$assertContains('63.000,00 €', $dashboard);
$assertContains('data-chart-config', $dashboard);
$assertContains('Seite 8 / 9', $dashboard);
$assertContains('href="/?view=meldungen" aria-label="Vorheriger Tab"', $dashboard);
$assertContains('href="/?view=watch" aria-label="Nächster Tab"', $dashboard);

$_GET = ['view' => 'crypto'];
ob_start();
$application->run();
$routedDashboard = (string) ob_get_clean();
$_GET = [];
$assertContains('BTC/EUR', $routedDashboard);
$assertContains('Seite 8 / 9', $routedDashboard);

$healthDashboard = $invoke('renderDashboard', '', '', '', '', '', '', '', '', '', '', 'watch');
$assertContains('Seite 9 / 9', $healthDashboard);
$assertContains('href="/?view=crypto" aria-label="Vorheriger Tab"', $healthDashboard);

$digest = [
    'generated_at' => time(),
    'window_start' => time() - 7 * 86400,
    'top_today' => null,
    'items' => [],
    'crypto' => [
        'assets' => [
            'BTC' => [
                'current' => 63_000.0,
                'high' => 64_000.0,
                'low' => 59_000.0,
                'change' => 5.0,
            ],
            'ETH' => ['current' => 2_850.0, 'high' => 3_100.0, 'low' => 2_800.0, 'change' => -5.0],
        ],
        'prose' => 'Bitcoin steigt, während Ethereum nachgibt.',
    ],
    'weather' => [
        'location' => 'München',
        'temp_current' => 20.0,
        'days' => [['date' => date(format: 'Y-m-d'), 'temp_min' => 12.0, 'temp_max' => 23.0, 'description' => 'klar']],
    ],
    'health' => null,
    'tv' => null,
    'media' => [],
];
$invoke('cacheSet', 'daily_digest', (string) json_encode(value: $digest));
$digestHtml = $invoke('renderDigestHtml');
$assertContains('1-Jahres-Trend · EUR', $digestHtml);
$assertContains('Bitcoin steigt, während Ethereum nachgibt.', $digestHtml);
$assertBefore('Kryptowährungen', 'Wetter', $digestHtml);
$fallbackHtml = $invoke('buildCryptoDigestBlock', ['assets' => $digest['crypto']['assets']]);
$assertContains('63.000,00 €', $fallbackHtml);
$assertContains('-5,00 %', $fallbackHtml);

unlink(filename: $rootDirectory . '/.data/database.sqlite');
rmdir(directory: $rootDirectory . '/.data');
rmdir(directory: $rootDirectory);

echo "crypto feature tests passed\n";
