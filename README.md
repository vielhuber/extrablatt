# 📰 extrablatt 📰

a simple news aggregator. pulls articles from configurable rss feeds (plus reddit, hacker news and x via cookie-authenticated scrape), stores them in sqlite, detects paywalls, fetches thumbnails, categorises through an llm, and opens single articles through an `archive.ph` proxy with mobile-friendly css rewrites. installable as a pwa.

## requirements

- php 8.3+
- [curl-impersonate](https://github.com/lwthiker/curl-impersonate) (chrome tls fingerprint) in `/opt/curl-impersonate/`
- one cookie export per `archive.<tld>` mirror (for the cloudflare `cf_clearance` token); optionally for `reddit.com` and `x.com`

## setup

```
cp config.example.json config.json
cp .env.example .env
```

place json cookie exports per host in `.cookies/` (any browser cookie-editor extension). point the webroot at the project. php needs write access to `.cache/`.

## usage

```bash
php -S 127.0.0.1:8080 -t .
```

## cron

```bash
0 6,18 * * * curl -s 'https://your-host/?scrape=1' >/dev/null
```
