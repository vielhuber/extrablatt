# 📰 extrablatt 📰

a simple news aggregator. pulls articles from configurable rss feeds (plus reddit, hacker news and x via cookie-authenticated scrape), stores them in sqlite, detects paywalls, fetches thumbnails, categorises through an llm, and opens single articles through an `archive.ph` proxy with mobile-friendly css rewrites. installable as a pwa.

## requirements

- php 8.3+ with `pdo_sqlite`, `gd`, and `exec()` enabled
- linux x86_64 with glibc 2.27+ (works on most shared hosts and any modern desktop)
- one cookie export per `archive.<tld>` mirror (for the cloudflare `cf_clearance` token); optionally for `reddit.com` and `x.com`

## install curl-impersonate (linux x86_64)

`curl-impersonate` (lexiforest fork) ships the chrome tls fingerprint that lets us talk to archive.ph, reddit and x without getting blocked. drop the binary into `.bin/` inside the project — no root access needed:

```bash
mkdir -p .bin && cd .bin
curl -sL -o ci.tar.gz https://github.com/lexiforest/curl-impersonate/releases/download/v1.5.6/curl-impersonate-v1.5.6.x86_64-linux-gnu.tar.gz
tar xzf ci.tar.gz curl_chrome123 curl-impersonate
chmod +x curl_chrome123 curl-impersonate
./curl_chrome123 -V    # smoke test — should print a version string
rm ci.tar.gz
cd ..
```

`curl_chrome123` is a bash wrapper that calls the real `curl-impersonate` binary next to it with chrome-specific tls + header flags — both files need to live in `.bin/`.

works on most shared hosts (the binary is statically linked enough). if the smoke test fails with `error while loading shared libraries`, try an older release with looser glibc requirements.

reddit pins the binary to `curl_chrome123` because the newer chrome124+ fingerprints trip reddit's bot detection. the lexiforest fork is the actively-maintained successor of the original `lwthiker/curl-impersonate` (which stalled at chrome116).

## setup

```bash
cp config.example.json config.json     # papers, categories, ai params
cp .env.example .env                    # AI_PROVIDER, AI_MODEL, AI_API_KEY
```

place json cookie exports per host in `.cookies/` (any browser cookie-editor extension). point the webroot at the project. php needs write access to `.cache/` and `.logs/`.

## usage

```bash
php -S 127.0.0.1:8080 -t .
```

## cron

```cron
0 6,18 * * * curl -s 'https://your-host/?scrape=1' >/dev/null
```

## cli helpers

```bash
php index.php backfill-reddit       # one-shot: pull thumbnails for every reddit article in the db
php index.php backfill-hackernews   # one-shot: deep-probe hn articles for body images
```
