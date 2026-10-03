<?php

namespace App\Support\Restaurants;

/**
 * The single definition of an acceptable restaurant Google Review link
 * (CARTA 5.3A). The value is later rendered as an href on a public
 * surface, so it is checked purely STRUCTURALLY — scheme, host, path —
 * and is never fetched, redirect-followed or short-link-resolved
 * server-side (no SSRF surface).
 *
 * These are the Google review/share URL formats CURRENTLY SUPPORTED BY
 * AFORO — not a normative list published by Google. Google documents the
 * flow (Business Profile → Read reviews → Get more reviews → Copy) but not
 * the hosts/paths of the link it generates; new formats are added here
 * when real links produced by that flow are not yet accepted.
 *
 *   https://g.page/r/{id}/review                          observed "Get more reviews" copy link
 *   https://search.google.com/local/writereview?placeid=  write-review by Place ID
 *   https://search.google.com/local/reviews?placeid=      reviews by Place ID
 *   https://maps.app.goo.gl/{id}                          Maps share link
 *   https://www.google.com/maps/...  https://google.com/maps/...
 *   https://maps.google.com/ (root or /maps...)
 *
 * The Maps formats are accepted as Google/Maps links; they are not
 * guaranteed to open the review form directly (the 5.3B tutorial steers
 * owners to the "Get more reviews" link).
 *
 * The scheme is case-insensitive (HTTPS://… is https) and normalized to
 * lowercase on write; host, path, query and fragment are never rewritten.
 *
 * Hosts are compared for EXACT equality (never str_contains/suffix), so
 * google.com.evil.example and evilgoogle.com are rejected. Paths are
 * pinned per host so Google's own redirectors (e.g. /url?q=...) cannot be
 * used to bounce a guest to an arbitrary site. Plain goo.gl (the retired
 * generic shortener) and country TLDs (google.es, ...) are not accepted.
 */
final class GoogleReviewUrl
{
    public const MAX_LENGTH = 2048;

    /**
     * host => allowed path prefixes (matched on a segment boundary), or
     * null for "any non-root path" (opaque short-link ids).
     *
     * @var array<string, array<int, string>|null>
     */
    private const ALLOWED = [
        'g.page' => null,
        'maps.app.goo.gl' => null,
        'search.google.com' => ['/local/writereview', '/local/reviews'],
        'www.google.com' => ['/maps'],
        'google.com' => ['/maps'],
        'maps.google.com' => ['/maps'],
    ];

    /**
     * Hosts whose bare root (e.g. https://maps.google.com/?cid=...) is
     * itself a valid place link.
     *
     * @var array<int, string>
     */
    private const ROOT_ALLOWED = ['maps.google.com'];

    /**
     * Write-side normalization: trim, and blank => null. Never rewrites
     * a non-blank URL (path/query/fragment are kept verbatim).
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : self::lowercaseScheme($trimmed);
    }

    /**
     * Lowercases ONLY a leading RFC 3986 scheme ("HtTpS:" → "https:");
     * everything after the first ':' is returned untouched.
     */
    private static function lowercaseScheme(string $url): string
    {
        return preg_replace_callback(
            '/^[A-Za-z][A-Za-z0-9+.\-]*:/',
            fn (array $match) => strtolower($match[0]),
            $url,
        ) ?? $url;
    }

    public static function isValid(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH) {
            return false;
        }

        $url = self::lowercaseScheme($url);

        // Whitespace/control characters anywhere (already trimmed at the
        // edges), and backslashes, which browsers treat as '/' and can
        // make a browser and parse_url() disagree about the host.
        if (preg_match('/[\s\x00-\x1F\x7F\\\\]/u', $url) !== 0) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || ! str_starts_with($url, 'https://'.$parts['host'])
        ) {
            return false;
        }

        $host = strtolower($parts['host']);

        if (! array_key_exists($host, self::ALLOWED)) {
            return false;
        }

        $path = $parts['path'] ?? '';

        if ($path === '' || $path === '/') {
            return in_array($host, self::ROOT_ALLOWED, true);
        }

        $prefixes = self::ALLOWED[$host];

        if ($prefixes === null) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
