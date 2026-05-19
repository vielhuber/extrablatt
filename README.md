[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/extrablatt)](https://github.com/vielhuber/extrablatt/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/extrablatt)](https://github.com/vielhuber/extrablatt/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/extrablatt)](https://github.com/vielhuber/extrablatt/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/extrablatt)](https://packagist.org/packages/vielhuber/extrablatt)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/extrablatt)](https://packagist.org/packages/vielhuber/extrablatt)

# 📰 extrablatt 📰

a simple news aggregator. pulls articles from configurable rss feeds (plus reddit, hacker news and x via cookie-authenticated scrape), stores them in sqlite, detects paywalls, fetches thumbnails, categorises through an llm, and opens single articles through an `archive.ph` proxy with mobile-friendly css rewrites. installable as a pwa.

## installation

```
composer require vielhuber/extrablatt
```

create a minimal `index.php` in your project root:

```php
<?php
require __DIR__ . '/vendor/autoload.php';
(new \vielhuber\extrablatt\Extrablatt(rootDir: __DIR__))->run();
```

copy the asset bundle from the package to your project root:

```
cp -r vendor/vielhuber/extrablatt/{css,pwa,.htaccess,config.example.json,.env.example} .
cp config.example.json config.json     # populate papers / categories / ai params
cp .env.example .env                    # populate credentials + AUTH_PASSWORD
mkdir -p .cookies                       # populate per-host cookie exports
```

install `curl-impersonate` (chrome tls fingerprint — required for archive.ph, reddit and x) into `.bin/`:

```
mkdir -p .bin && cd .bin
curl -sL -o ci.tar.gz https://github.com/lexiforest/curl-impersonate/releases/download/v1.5.6/curl-impersonate-v1.5.6.x86_64-linux-gnu.tar.gz
tar xzf ci.tar.gz curl_chrome123 curl-impersonate
chmod +x curl_chrome123 curl-impersonate
./curl_chrome123 -V          # smoke test
rm ci.tar.gz
cd ..
```

## usage

```bash
php -S 127.0.0.1:8080 -t .
```

login with the value of `AUTH_PASSWORD`. leave the env var empty to disable the auth gate (only for local dev).

## cron

```cron
0 6,18 * * * curl -s 'https://your-host/?scrape=1&key=<AUTH_PASSWORD>' >/dev/null
```

the cron-scrape endpoint reuses `AUTH_PASSWORD` as its key. authenticated browser sessions skip the key check.
