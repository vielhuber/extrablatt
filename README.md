[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/extrablatt)](https://github.com/vielhuber/extrablatt/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/extrablatt)](https://github.com/vielhuber/extrablatt/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/extrablatt)](https://github.com/vielhuber/extrablatt/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/extrablatt)](https://packagist.org/packages/vielhuber/extrablatt)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/extrablatt)](https://packagist.org/packages/vielhuber/extrablatt)

# 📰 extrablatt 📰

a simple news aggregator in politically charged times. pulls articles from configurable rss feeds (plus reddit, hacker news and x via cookie-authenticated scrape), stores them in sqlite, detects paywalls, fetches thumbnails, categorises through an llm, and opens single articles through an `archive.ph` proxy with mobile-friendly css rewrites. installable as a progressive web app.

## installation

```
mkdir extrablatt
cd extrablatt
composer require vielhuber/extrablatt
./vendor/bin/extrablatt-init
```

after install, edit:

- `.data/config.json`: papers (see schema below)
- `.data/.env`: `AI_API_KEY` / `AUTH_PASSWORD` / `AI_PROVIDER` / `AI_MODEL`
- `.data/cookies/`: drop cookie exports per host into
- `.data/database.sqlite`: restore database (optional)

## config.json schema

```json
{
    "papers": {
        "<paper-key>": {
            "url": "https://example.com",
            "label": "Display Name",
            "rss": "https://example.com/feed.xml",
            "default_image": "https://example.com/fallback.png",
            "stub_markers": ["Subscribe to read", "Premium content"]
        }
    }
}
```

- `default_image` (optional): fallback thumbnail when the RSS item carries no image.
- `stub_markers` (optional): substrings present in the archive.ph snapshot of a PLUS article when it's only a teaser, so the snapshot is dropped instead of surfaced as if it were the full text.
- `following` (optional, `medium://home` only): explicit list of followed handles (`@username`) and publication slugs whose RSS feeds get aggregated. Without it the list is discovered from the cookie-authenticated `/following` page, which Cloudflare challenges from datacenter IPs — so shared hosting needs this key.
- Special `rss` schemes: `reddit://home`, `x://home` and `hackernews://best` activate the dedicated scrapers in place of XML parsing; `medium://home` aggregates the followed authors' RSS feeds; `ct://archiv` indexes the complete c't article archive (needs no login, backfills a bounded number of issues per scrape, rating = editorial page count).

the `Watch` tab reads the Google Health API (daily rollups of the paired watch). set `GOOGLE_HEALTH_CLIENT_ID` / `GOOGLE_HEALTH_CLIENT_SECRET` in `.env`, register the site root as the OAuth redirect URI, publish the cloud project (publishing status `Testing` makes google revoke the refresh token every 7 days), then visit `/?health=connect` once.

categories, AI defaults (`temperature`, `timeout`, `max_tries`), and the archive fulltext minimum (8000 chars) are hardcoded in the package.

## usage

```bash
php -S 127.0.0.1:8080 -t .
```

## cron

```cron
0 6,18 * * * curl -s 'https://your-host/?scrape=1&key=<AUTH_PASSWORD>' >/dev/null
```

## backup

```bash
zip -r backup.zip .data
```
