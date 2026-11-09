<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Extrablatt.php';

use vielhuber\extrablatt\Extrablatt;

$rootDirectory = sys_get_temp_dir() . '/extrablatt-extraction-' . bin2hex(random_bytes(8));
mkdir($rootDirectory . '/.data', 0700, true);
$application = new Extrablatt(rootDir: $rootDirectory);
$invoke = static function (string $method, mixed ...$arguments) use ($application): mixed {
    return (new ReflectionMethod($application, $method))->invoke($application, ...$arguments);
};
try {
    $database = $invoke('openDatabase');
    $tables = $database->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['health_days', 'health_measurements'] as $table) {
        if (in_array($table, $tables, true)) {
            throw new RuntimeException('Extrablatt still creates health tables.');
        }
    }
    $invoke('cacheSet', 'daily_digest', (string) json_encode([
        'generated_at' => time(), 'items' => [],
        'health' => ['prose' => 'Legacy private health fixture']
    ]));
    if (str_contains($invoke('renderDigestHtml'), 'Legacy private health fixture')) {
        throw new RuntimeException('Cached private health data still rendered.');
    }
    ob_start();
    $_GET = ['view' => 'watch'];
    $application->run();
    $html = (string) ob_get_clean();
    foreach (['health-entry', 'view=watch', 'Google Health'] as $needle) {
        if (str_contains($html, $needle)) {
            throw new RuntimeException('Legacy health route still active: ' . $needle);
        }
    }
    if (!isset($invoke('categories')['Gesundheit'])) {
        throw new RuntimeException('Editorial health category was removed.');
    }
    $database->exec('CREATE TABLE health_days (day TEXT PRIMARY KEY)');
    $database->exec("INSERT INTO health_days VALUES ('2026-11-01')");
    $invoke('openDatabase');
    if ($database->query('SELECT day FROM health_days')->fetchColumn() !== '2026-11-01') {
        throw new RuntimeException('Existing health data must remain available for migration.');
    }
} finally {
    $_GET = [];
    unlink($rootDirectory . '/.data/database.sqlite');
    rmdir($rootDirectory . '/.data');
    rmdir($rootDirectory);
}
echo "Health extraction tests passed\n";
