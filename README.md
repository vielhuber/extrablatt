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
- `.data/.env`: `AI_API_KEY` / `AI_BASE_URL` / `AUTH_PASSWORD` / `AI_PROVIDER` / `AI_MODEL`
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

the `Crypto` tab shows rolling one-year and four-week BTC/EUR and ETH/EUR price histories from CoinGecko's public endpoint without a configured API key, using the bundled Chart.js library and compact widgets for both periods. market data stays fresh for 15 minutes, with the last cached response retained through temporary API failures; the weekly digest describes the most recent four weeks above the weather without duplicating the charts.

the `Lokal` tab after BILD shows the ten newest eligible PNP articles from the publisher's `Lokales` and `Bayern` sections, with optional topic filters. The default relevance order reuses the existing score: personal votes, source/topic preferences from votes and reading history, normalized external ratings when available, and recency. PNP currently supplies no external ratings. Publication date and insertion order break ties; manual sorting only reorders the same latest-ten selection. Other tabs and the weekly digest remain unchanged. During each digest scrape, these same sections supply a separate `Lokales & Regionales` prose block for the past seven days, including read articles. Existing cached digests gain the block on the next successful scrape; without matching articles or an AI response, it is omitted.

categories, AI defaults (`temperature`, `timeout`, `max_tries`), and the archive fulltext minimum (8000 chars) are hardcoded in the package.

## usage

Personal measurements, watch synchronisation and the former `Gesundheit` tab now live in the independent [healthspace](https://github.com/vielhuber/healthspace) package. Its README covers migration; existing health tables are left untouched. The editorial health-news category remains part of Extrablatt.

```bash
php -S 127.0.0.1:8080 -t .
```

## daily podcasts

The `Podcast` tab comes first in the menu and bottom page navigation. It lists daily newspaper episodes newest first, with an authenticated audio player for each completed episode. Page views never start a generation.

After the first successful newspaper generation of a calendar day in `Europe/Berlin`, the existing scrape launches one background audio job. The existing recurring scrape schedule therefore needs no additional cron. A later scrape on the same day does not regenerate or replace that day's episode, including failed or uncertain jobs. If no scrape succeeds that day, no episode is created.

Each episode keeps a plain-text snapshot of the entire rendered newspaper, including the lead story, all weekly stories, local news, crypto, weather, TV and every media section, with source URLs. Nothing is truncated or limited to unread headlines. German generation requests approximately 15 minutes and coverage of every event. This is a target, not a guaranteed duration or exhaustive-content guarantee: Google documents explicit length settings as English-only, so German uses the default format plus instructions. See [Google's Audio Overview documentation](https://support.google.com/gemininotebook/answer/16212820).

The optional worker requires Python 3.10+ with `notebooklm-py==0.8.6` in an isolated virtual environment (no browser is required on the web host). Configure these values only in the consuming installation's private `.data/.env`:

- `NOTEBOOKLM_PYTHON`: the absolute path to that environment's Python executable.
- `NOTEBOOKLM_AUTH_JSON`: the Google session as a single-line Playwright storage-state JSON object from a separate authenticated login. Never commit it or pass it through URLs or JavaScript. This is not a Google Cloud API key.

The session reaches the worker only through its process environment and is not persisted separately. Inline authentication cannot persist refreshed cookies, so the session may need to be replaced in `.data/.env` later. Keep that file private (0600). Daily sources, status and audio remain under protected `.data/podcast/YYYY-MM-DD/` directories. Date validation prevents arbitrary file access; the existing application login protects status and audio routes. Legacy test artifacts are left untouched but are not daily episodes.

This uses [notebooklm-py](https://github.com/teng-lin/notebooklm-py), not an officially supported Google API. Account quota and expired sessions can prevent a daily episode; failures are visible in the list and are not silently retried. Generating an episode consumes the account's shared compute budget. The repository contains no real Google credentials.

## cron

```cron
0 6,18 * * * curl -s 'https://your-host/?scrape=1&key=<AUTH_PASSWORD>' >/dev/null
```

## deployment

production consumes this repository as the Composer package `vielhuber/extrablatt`.

1. Test the task branch, update the package version with `npm version <version> --no-git-tag-version`, and commit the release changes.
2. Merge the tested branch into `main`, create the matching version tag on the merged commit, and push `main` and that tag. The tag is published through Packagist.
3. On production, back up `composer.json` and `composer.lock` outside the public document root or in a protected dot directory.
4. Preview the targeted update with `composer update vielhuber/extrablatt:<version> --dry-run --no-dev --prefer-dist --no-interaction`. Only the Extrablatt package should change.
5. Run the same command without `--dry-run`. Keep the application's configuration, cookies and SQLite database untouched.
6. Run the existing authenticated scrape and check its completion and the regenerated weekly digest. Verify that the installed package reference matches the release tag.

For a package rollback, restore the saved Composer files and run `composer install --no-dev --prefer-dist --no-interaction`. This restores package versions, not data changed by a scrape.

## backup

```bash
zip -r backup.zip .data
```
