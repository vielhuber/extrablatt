<?php
declare(strict_types=1);

namespace vielhuber\extrablatt;

/**
 * Route public HN requests to the isolated test server without changing other HTTP calls.
 */
function curl_init(?string $url = null): \CurlHandle|false
{
    if ($url !== null && str_starts_with(haystack: $url, needle: 'https://news.ycombinator.com/')) {
        $url = str_replace(
            search: 'https://news.ycombinator.com',
            replace: (string) getenv(name: 'EXTRABLATT_HN_FIXTURE_URL'),
            subject: $url
        );
    }
    return \curl_init(url: $url);
}
