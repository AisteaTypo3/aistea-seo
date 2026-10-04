<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Checker;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

final class UrlTools
{
    private const TRACKING_PARAMS = ['gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', 'dclid', 'yclid', '_ga', '_gl'];

    private const SKIP_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', '7z', 'gz', 'tar', 'dmg', 'exe',
        'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp', 'tif', 'tiff',
        'mp4', 'mp3', 'wav', 'ogg', 'webm', 'mov', 'avi', 'wmv', 'm4a',
        'txt', 'csv', 'xml', 'json', 'rss', 'atom', 'css', 'js', 'woff', 'woff2', 'ttf', 'eot',
    ];

    /**
     * Turns visitor input ("example.com", " https://Example.com/ ") into an absolute URL.
     */
    public static function fromUserInput(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'https://' . ltrim($input, '/');
        }

        $parts = parse_url($input);
        if ($parts === false || !isset($parts['host'])) {
            return '';
        }

        $host = $parts['host'];
        if (preg_match('/[^\x20-\x7e]/', $host) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $input = str_replace($host, $ascii, $input);
            }
        }

        return self::normalize($input) ?? '';
    }

    public static function resolve(string $base, string $relative): string
    {
        $relative = trim(html_entity_decode($relative, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        try {
            return (string) UriResolver::resolve(new Uri($base), new Uri($relative));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Normalizes for deduplication: lower-case scheme/host, no fragment, no default port,
     * tracking parameters removed. Unlike the backend crawler the query string is kept,
     * because different parameters can serve different content.
     */
    public static function normalize(string $url): ?string
    {
        try {
            $uri = new Uri(trim($url));
        } catch (\Throwable) {
            return null;
        }

        $scheme = strtolower($uri->getScheme());
        if (!in_array($scheme, ['http', 'https'], true) || $uri->getHost() === '') {
            return null;
        }

        $query = $uri->getQuery();
        if ($query !== '') {
            $kept = [];
            foreach (explode('&', $query) as $pair) {
                $name = strtolower(urldecode(explode('=', $pair, 2)[0]));
                if ($name === '' || str_starts_with($name, 'utm_') || in_array($name, self::TRACKING_PARAMS, true)) {
                    continue;
                }
                $kept[] = $pair;
            }
            $query = implode('&', $kept);
        }

        $uri = $uri->withScheme($scheme)
            ->withHost(strtolower($uri->getHost()))
            ->withFragment('')
            ->withQuery($query)
            ->withPath($uri->getPath() === '' ? '/' : $uri->getPath());

        return (string) $uri;
    }

    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /**
     * Host without leading "www." for "same site" decisions.
     */
    public static function siteKey(string $url): string
    {
        return preg_replace('/^www\./', '', self::host($url)) ?? '';
    }

    public static function isSameSite(string $url, string $reference): bool
    {
        return self::siteKey($url) !== '' && self::siteKey($url) === self::siteKey($reference);
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    public static function pathWithQuery(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = (string) parse_url($url, PHP_URL_QUERY);

        return $query !== '' ? $path . '?' . $query : $path;
    }

    public static function isLikelyDocument(string $url): bool
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return $extension === '' || !in_array($extension, self::SKIP_EXTENSIONS, true);
    }

    public static function queryParamCount(string $url): int
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        return $query === '' ? 0 : count(explode('&', $query));
    }
}
