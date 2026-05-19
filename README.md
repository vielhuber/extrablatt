[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/extrablatt)](https://github.com/vielhuber/extrablatt/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/extrablatt)](https://github.com/vielhuber/extrablatt/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/extrablatt)](https://github.com/vielhuber/extrablatt/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/extrablatt)](https://packagist.org/packages/vielhuber/extrablatt)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/extrablatt)](https://packagist.org/packages/vielhuber/extrablatt)

# 📰 extrablatt 📰

a simple news aggregator. pulls articles from configurable rss feeds (plus reddit, hacker news and x via cookie-authenticated scrape), stores them in sqlite, detects paywalls, fetches thumbnails, categorises through an llm, and opens single articles through an `archive.ph` proxy with mobile-friendly css rewrites. installable as a pwa.

## installation

```bash
mkdir extrablatt
cd extrablatt
composer create-project vielhuber/extrablatt .
```

this clones the project, runs `composer install`, downloads `curl-impersonate` into `.bin/`, creates `config.json` + `.env` from the templates and sets up the runtime directories. one-stop, idempotent — re-run any time with `composer install-extrablatt`.

then edit `config.json` (papers / categories / ai params) and `.env` (AI_API_KEY + AUTH_PASSWORD), drop cookie exports per host into `.cookies/`, and you're ready.

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
