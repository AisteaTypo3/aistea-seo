<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Checker;

use Aistea\AisteaSeo\Checker\Http\SafeHttpClient;

/**
 * Site-wide checks that do not depend on a single page: robots.txt, XML sitemaps,
 * protocol/host canonicalisation, soft-404 handling and well-known files.
 */
final class SiteAnalyzer
{
    public const AI_CRAWLERS = ['GPTBot', 'OAI-SearchBot', 'ClaudeBot', 'Google-Extended', 'PerplexityBot', 'CCBot'];

    private const MAX_SITEMAP_FILES = 12;
    private const MAX_SITEMAP_SAMPLE = 2000;

    public function __construct(private readonly SafeHttpClient $http) {}

    public function robots(string $origin): array
    {
        $response = $this->http->fetch($origin . '/robots.txt', 3, ['accept' => 'text/plain,*/*;q=0.5', 'maxBytes' => 512 * 1024]);
        $facts = [
            'status' => $response->status,
            'error' => $response->error,
            'content' => '',
            'sitemaps' => [],
            'blocksAll' => false,
            'blocksGooglebot' => false,
            'aiBlocked' => [],
        ];

        $isText = !str_contains($response->contentType(), 'html');
        if ($response->ok() && $isText) {
            $robots = new RobotsTxt($response->body);
            $facts['content'] = mb_substr($response->body, 0, 100000);
            $facts['sitemaps'] = array_values(array_filter(array_map(
                static fn(string $s): string => UrlTools::normalize(UrlTools::resolve($origin . '/robots.txt', $s)) ?? '',
                $robots->getSitemaps()
            )));
            $facts['blocksAll'] = $robots->blocksEverything('*');
            $facts['blocksGooglebot'] = $robots->blocksEverything('Googlebot');
            foreach (self::AI_CRAWLERS as $bot) {
                if ($robots->hasExplicitGroup($bot) && $robots->blocksEverything($bot)) {
                    $facts['aiBlocked'][] = $bot;
                }
            }
        } elseif ($response->ok()) {
            // An HTML page served as robots.txt (typical CMS 404 fallback) is no robots.txt.
            $facts['status'] = 404;
            $facts['servedHtml'] = true;
        }

        return $facts;
    }

    /**
     * @param list<string> $robotsSitemaps
     * @return list<string>
     */
    public function sitemapCandidates(string $origin, array $robotsSitemaps): array
    {
        if ($robotsSitemaps !== []) {
            return array_slice($robotsSitemaps, 0, 5);
        }

        return [$origin . '/sitemap.xml', $origin . '/sitemap_index.xml', $origin . '/sitemap-index.xml', $origin . '/wp-sitemap.xml'];
    }

    /**
     * Fetches one sitemap file. Returns parsed URLs and child sitemaps (for index files).
     *
     * @return array{url: string, status: int, valid: bool, type: string, urls: list<string>, children: list<string>, urlCount: int, lastmodCount: int}
     */
    public function sitemapFile(string $url): array
    {
        $response = $this->http->fetch($url, 3, [
            'accept' => 'application/xml,text/xml;q=0.9,*/*;q=0.5',
            'maxBytes' => 20 * 1024 * 1024,
            'timeout' => 25,
        ]);
        $result = ['url' => $url, 'status' => $response->status, 'valid' => false, 'type' => '', 'urls' => [], 'children' => [], 'urlCount' => 0, 'lastmodCount' => 0];
        if (!$response->ok() || $response->truncated) {
            return $result;
        }

        $body = $response->body;
        if (str_starts_with($body, "\x1f\x8b")) {
            $body = (string) @gzdecode($body);
        }
        if (stripos(substr($body, 0, 1000), '<html') !== false) {
            return $result; // soft-404 HTML page instead of XML
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($body, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded !== true || $dom->documentElement === null) {
            $result['type'] = 'invalid';

            return $result;
        }

        $xpath = new \DOMXPath($dom);
        $root = strtolower($dom->documentElement->localName);
        if ($root === 'sitemapindex') {
            $result['type'] = 'index';
            $result['valid'] = true;
            foreach ($xpath->query('//*[local-name()="sitemap"]/*[local-name()="loc"]') as $loc) {
                $child = UrlTools::normalize(trim($loc->textContent));
                if ($child !== null) {
                    $result['children'][] = $child;
                }
            }
        } elseif ($root === 'urlset') {
            $result['type'] = 'urlset';
            $result['valid'] = true;
            $locs = $xpath->query('//*[local-name()="url"]/*[local-name()="loc"]');
            $result['urlCount'] = $locs->length;
            $result['lastmodCount'] = (int) $xpath->evaluate('count(//*[local-name()="url"]/*[local-name()="lastmod"])');
            foreach ($locs as $loc) {
                if (count($result['urls']) >= self::MAX_SITEMAP_SAMPLE) {
                    break;
                }
                $pageUrl = UrlTools::normalize(trim($loc->textContent));
                if ($pageUrl !== null) {
                    $result['urls'][] = $pageUrl;
                }
            }
        } else {
            $result['type'] = 'invalid';
        }

        return $result;
    }

    public function maxSitemapFiles(): int
    {
        return self::MAX_SITEMAP_FILES;
    }

    /**
     * Checks whether http:// redirects to https:// and whether the alternate host (www / non-www)
     * redirects to the primary host instead of serving a duplicate.
     */
    public function variants(string $finalUrl): array
    {
        $host = UrlTools::host($finalUrl);
        $origin = UrlTools::origin($finalUrl);

        $httpUrl = 'http://' . $host . '/';
        $http = $this->http->request($httpUrl, ['timeout' => 10, 'maxBytes' => 65536]);
        $httpLocation = $http->isRedirect() ? UrlTools::resolve($httpUrl, $http->header('location')) : '';

        $alternateHost = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
        $alternateUrl = (str_starts_with($origin, 'https') ? 'https://' : 'http://') . $alternateHost . '/';
        $alternate = $this->http->fetch($alternateUrl, 5, ['timeout' => 10, 'maxBytes' => 65536]);

        return [
            'http' => [
                'status' => $http->status,
                'error' => $http->error,
                'location' => $httpLocation,
                'toHttps' => str_starts_with($httpLocation, 'https://'),
            ],
            'alternate' => [
                'host' => $alternateHost,
                'status' => $alternate->status,
                'error' => $alternate->error,
                'redirected' => $alternate->redirects !== [],
                'firstStatus' => $alternate->redirects[0]['status'] ?? $alternate->status,
                'finalHost' => UrlTools::host($alternate->finalUrl),
            ],
        ];
    }

    public function soft404(string $origin): array
    {
        $probe = $origin . '/aistea-seo-check-' . bin2hex(random_bytes(6)) . '-not-found';
        $response = $this->http->fetch($probe, 5, ['timeout' => 12, 'maxBytes' => 262144]);

        return [
            'status' => $response->status,
            'error' => $response->error,
            'redirectedTo' => $response->redirects !== [] ? $response->finalUrl : '',
            'redirectedHome' => $response->redirects !== [] && in_array(UrlTools::pathWithQuery($response->finalUrl), ['/', ''], true),
        ];
    }

    public function wellKnown(string $origin): array
    {
        $results = $this->http->checkMany([$origin . '/favicon.ico', $origin . '/llms.txt'], 2, 8);

        return [
            'faviconIco' => ($results[$origin . '/favicon.ico'] ?? null)?->status ?? 0,
            'llmsTxt' => ($results[$origin . '/llms.txt'] ?? null)?->status ?? 0,
        ];
    }
}
