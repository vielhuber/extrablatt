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
$assertNotContains = static function (string $needle, string $haystack): void {
    if (str_contains(haystack: $haystack, needle: $needle)) {
        throw new RuntimeException(message: 'Expected output not to contain: ' . $needle);
    }
};
$assertBefore = static function (string $first, string $second, string $haystack): void {
    $firstPosition = strpos(haystack: $haystack, needle: $first);
    $secondPosition = strpos(haystack: $haystack, needle: $second);
    if ($firstPosition === false || $secondPosition === false || $firstPosition >= $secondPosition) {
        throw new RuntimeException(message: 'Expected "' . $first . '" before "' . $second . '".');
    }
};

$currentTimestamp = 1_787_086_400_000;
$fourWeekStartTimestamp = $currentTimestamp - 27 * 86_400_000;
$annualStartTimestamp = $currentTimestamp - 364 * 86_400_000;
$marketData = [
    'assets' => [
        'bitcoin' => [
            'symbol' => 'BTC',
            'prices' => [[$annualStartTimestamp, 50_000.0], [$fourWeekStartTimestamp, 60_000.0], [$currentTimestamp, 63_000.0]],
        ],
        'ethereum' => [
            'symbol' => 'ETH',
            'prices' => [[$annualStartTimestamp, 2_500.0], [$fourWeekStartTimestamp, 3_000.0], [$currentTimestamp, 2_850.0]],
        ],
    ],
];
$invoke('cacheSet', 'crypto_market_year', (string) json_encode(value: $marketData));
$historyDays = (new ReflectionClass(objectOrClass: $application))->getConstant(name: 'CRYPTO_MARKET_HISTORY_DAYS');
if ($historyDays !== 365) {
    throw new RuntimeException(message: 'Expected one year of crypto history.');
}
$digestStats = $invoke('cryptoDigestStats', $marketData, 28);
if (($digestStats['assets']['BTC']['change'] ?? null) !== 5.0 || ($digestStats['assets']['ETH']['change'] ?? null) !== -5.0) {
    throw new RuntimeException(message: 'Expected four-week changes for BTC and ETH.');
}
if (count($digestStats['assets']['BTC']['prices'] ?? []) !== 2 || count($digestStats['assets']['ETH']['prices'] ?? []) !== 2) {
    throw new RuntimeException(message: 'Expected four-week chart points for BTC and ETH.');
}

$dashboard = $invoke('renderDashboard', '', '', '', '', '', '', '', '', '', '', 'crypto');
$assertContains('main { max-width: 940px;', $dashboard);
$assertContains('href="/?view=crypto">Crypto</a>', $dashboard);
$assertBefore('href="/?view=crypto">Crypto</a>', 'href="/?view=watch">Gesundheit</a>', $dashboard);
$assertContains('BTC/EUR', $dashboard);
$assertContains('ETH/EUR', $dashboard);
$assertContains('BTC/EUR · 1 Jahr', $dashboard);
$assertContains('ETH/EUR · 1 Jahr', $dashboard);
$assertContains('BTC/EUR · 4 Wochen', $dashboard);
$assertContains('ETH/EUR · 4 Wochen', $dashboard);
$assertContains('id="cryptoBitcoinFourWeeks"', $dashboard);
$assertContains('id="cryptoEthereumFourWeeks"', $dashboard);
if (substr_count(haystack: $dashboard, needle: 'class="crypto__chart"') !== 4) {
    throw new RuntimeException(message: 'Expected four crypto charts.');
}
if (substr_count(haystack: $dashboard, needle: 'class="crypto__kpi"') !== 4) {
    throw new RuntimeException(message: 'Expected four crypto widgets.');
}
$assertContains('63.000,00 €', $dashboard);
$assertContains('data-chart-config', $dashboard);
$assertContains('Seite 9 / 10', $dashboard);
$assertContains('href="/?view=meldungen" aria-label="Vorheriger Tab"', $dashboard);
$assertContains('href="/?view=watch" aria-label="Nächster Tab"', $dashboard);

$_GET = ['view' => 'crypto'];
ob_start();
$application->run();
$routedDashboard = (string) ob_get_clean();
$_GET = [];
$assertContains('BTC/EUR', $routedDashboard);
$assertContains('Seite 9 / 10', $routedDashboard);

$healthDashboard = $invoke('renderDashboard', '', '', '', '', '', '', '', '', '', '', 'watch');
$assertContains('Seite 10 / 10', $healthDashboard);
$assertContains('href="/?view=crypto" aria-label="Vorheriger Tab"', $healthDashboard);

$digest = [
    'generated_at' => time(),
    'window_start' => time() - 7 * 86400,
    'top_today' => null,
    'items' => [],
    'crypto' => [
        'assets' => $digestStats['assets'],
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
$assertContains('4-Wochen-Trend · EUR', $digestHtml);
$assertContains('Bitcoin steigt, während Ethereum nachgibt.', $digestHtml);
$assertNotContains('class="digest__crypto-kpi"', $digestHtml);
$assertNotContains('id="digestCryptoBTC"', $digestHtml);
$assertNotContains('data-chart-config', $digestHtml);
$assertBefore('Kryptowährungen', 'Wetter', $digestHtml);
$fallbackHtml = $invoke('buildCryptoDigestBlock', ['assets' => $digest['crypto']['assets']]);
$assertContains('63.000,00 €', $fallbackHtml);
$assertContains('-5,00 %', $fallbackHtml);

unlink(filename: $rootDirectory . '/.data/database.sqlite');
rmdir(directory: $rootDirectory . '/.data');
rmdir(directory: $rootDirectory);

echo "crypto feature tests passed\n";
