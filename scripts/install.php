<?php
declare(strict_types=1);

// Post-create-project scaffolder. Triggered automatically by composer when
// the consumer runs `composer create-project vielhuber/extrablatt myapp`.
// Idempotent — safe to re-run manually as `php scripts/install.php`.

$root = dirname(__DIR__);
chdir(directory: $root);

echo "\n📰 extrablatt setup\n\n";

// 1. curl-impersonate into .bin/
$binDir = $root . '/.bin';
$binFile = $binDir . '/curl_chrome123';
if (file_exists(filename: $binFile)) {
    echo "✓ .bin/curl_chrome123 already present, skipping download\n";
} elseif (PHP_OS_FAMILY !== 'Linux') {
    echo "⚠️  curl-impersonate auto-install only available on Linux x86_64.\n";
    echo "   manual install instructions: see README.md\n";
} else {
    if (!is_dir(filename: $binDir)) {
        mkdir(directory: $binDir, permissions: 0755, recursive: true);
    }
    echo "→ downloading curl-impersonate v1.5.6 (lexiforest fork) ...\n";
    $tarUrl = 'https://github.com/lexiforest/curl-impersonate/releases/download/v1.5.6/curl-impersonate-v1.5.6.x86_64-linux-gnu.tar.gz';
    $tarFile = $binDir . '/ci.tar.gz';
    $stream = @fopen(filename: $tarUrl, mode: 'rb');
    if ($stream === false) {
        fwrite(stream: STDERR, data: "  ✗ download failed (network?). install manually per README.\n");
    } else {
        $ok = file_put_contents(filename: $tarFile, data: $stream);
        fclose(stream: $stream);
        if ($ok === false || filesize(filename: $tarFile) < 100000) {
            fwrite(stream: STDERR, data: "  ✗ download incomplete. install manually per README.\n");
            @unlink(filename: $tarFile);
        } else {
            passthru(command: 'tar xzf ' . escapeshellarg(arg: $tarFile) . ' -C ' . escapeshellarg(arg: $binDir) . ' curl_chrome123 curl-impersonate');
            chmod(filename: $binDir . '/curl_chrome123', permissions: 0755);
            chmod(filename: $binDir . '/curl-impersonate', permissions: 0755);
            @unlink(filename: $tarFile);
            echo "✓ curl-impersonate installed in .bin/\n";
        }
    }
}

// 2. config.json from example
if (!file_exists(filename: $root . '/config.json') && file_exists(filename: $root . '/config.example.json')) {
    copy(from: $root . '/config.example.json', to: $root . '/config.json');
    echo "✓ config.json created from example\n";
} else {
    echo "✓ config.json already present\n";
}

// 3. .env from example
if (!file_exists(filename: $root . '/.env') && file_exists(filename: $root . '/.env.example')) {
    copy(from: $root . '/.env.example', to: $root . '/.env');
    echo "✓ .env created from example\n";
} else {
    echo "✓ .env already present\n";
}

// 4. runtime directories
foreach (['.cookies', '.cache', '.data', '.logs'] as $dir) {
    if (!is_dir(filename: $root . '/' . $dir)) {
        mkdir(directory: $root . '/' . $dir, permissions: 0755, recursive: true);
        echo "✓ {$dir}/ directory created\n";
    }
}

echo "\nNext steps:\n";
echo "  1. edit config.json    — papers, categories, ai params\n";
echo "  2. edit .env           — AI_API_KEY and AUTH_PASSWORD (`openssl rand -hex 16`)\n";
echo "  3. drop cookie exports into .cookies/ (one .json per host)\n";
echo "  4. php -S 127.0.0.1:8080 -t .\n\n";
