<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Checker;

use Aistea\AisteaSeo\Checker\Http\HttpResult;

/**
 * Extracts SEO-relevant facts from one fetched page. Evaluation happens in ReportBuilder,
 * so these facts stay neutral (counts, values, examples).
 */
final class PageAnalyzer
{
    private const MAX_LINKS = 400;

    private const GENERIC_ANCHORS = [
        'hier', 'hier klicken', 'klicken sie hier', 'mehr', 'mehr erfahren', 'weiter', 'weiterlesen', 'link', 'details',
        'click here', 'here', 'read more', 'more', 'learn more', 'this page', 'continue',
    ];

    private const STOPWORDS = [
        'der', 'die', 'das', 'und', 'oder', 'aber', 'nicht', 'eine', 'einer', 'eines', 'einem', 'einen', 'sich', 'mit',
        'für', 'auf', 'aus', 'bei', 'nach', 'von', 'vor', 'über', 'unter', 'auch', 'noch', 'sind', 'wird', 'werden',
        'wurde', 'kann', 'können', 'haben', 'hat', 'sein', 'ist', 'wir', 'sie', 'ihr', 'ihre', 'ihren', 'unsere',
        'unser', 'dass', 'diese', 'dieser', 'dieses', 'wie', 'was', 'wenn', 'dann', 'mehr', 'alle', 'zum', 'zur',
        'durch', 'ohne', 'hier', 'sowie', 'sehr', 'jetzt', 'ihnen', 'euch', 'mich', 'dich', 'uns', 'the', 'and',
        'for', 'with', 'that', 'this', 'from', 'your', 'you', 'are', 'our', 'have', 'has', 'will', 'can', 'not',
        'all', 'more', 'about', 'their', 'they', 'what', 'when', 'which', 'into', 'than', 'then', 'also', 'here',
        'cookie', 'cookies', 'datenschutz', 'impressum', 'privacy', 'menu', 'menü',
    ];

    /** Schema.org types with properties Google needs for rich results. */
    private const SCHEMA_REQUIRED = [
        'Organization' => ['name'],
        'LocalBusiness' => ['name', 'address'],
        'Article' => ['headline'],
        'NewsArticle' => ['headline'],
        'BlogPosting' => ['headline'],
        'Product' => ['name'],
        'BreadcrumbList' => ['itemListElement'],
        'FAQPage' => ['mainEntity'],
        'Event' => ['name', 'startDate', 'location'],
        'Recipe' => ['name', 'image'],
        'JobPosting' => ['title', 'datePosted', 'description', 'hiringOrganization'],
        'Review' => ['itemReviewed', 'author'],
        'VideoObject' => ['name', 'thumbnailUrl', 'uploadDate'],
        'WebSite' => ['name'],
        'Person' => ['name'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function analyze(HttpResult $response, string $requestedUrl): array
    {
        $url = $response->finalUrl;
        $facts = [
            'url' => $requestedUrl,
            'finalUrl' => $url,
            'status' => $response->status,
            'error' => $response->error,
            'redirects' => $response->redirects,
            'ttfbMs' => $response->ttfbMs,
            'totalMs' => $response->totalMs,
            'bytes' => strlen($response->body),
            'truncated' => $response->truncated,
            'contentType' => $response->contentType(),
            'encoding' => strtolower($response->header('content-encoding')),
            'httpVersion' => $response->httpVersion,
            'xRobots' => strtolower($response->header('x-robots-tag')),
            'isHtml' => false,
        ];

        $isHtml = $response->error === ''
            && $response->status >= 200 && $response->status < 300
            && ($facts['contentType'] === '' || str_contains($facts['contentType'], 'html'));
        if (!$isHtml || trim($response->body) === '') {
            return $facts;
        }

        $html = $this->toUtf8($response->body, $response->header('content-type'));
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);

        $facts['isHtml'] = true;
        $facts['doctype'] = $dom->doctype !== null;
        $facts += $this->headFacts($xpath, $url, $response);
        $facts += $this->headingFacts($xpath);
        $facts['images'] = $this->imageFacts($xpath, $url);
        $facts += $this->linkFacts($xpath, $url);
        $facts += $this->structuredDataFacts($xpath);
        $facts += $this->resourceFacts($xpath, $url);
        $facts['domNodes'] = (int) $xpath->evaluate('count(//*)');
        $facts += $this->contentFacts($dom, $xpath, strlen($html));
        $facts['headers'] = [
            'hsts' => $response->header('strict-transport-security'),
            'csp' => $response->header('content-security-policy'),
            'xcto' => $response->header('x-content-type-options'),
            'xfo' => $response->header('x-frame-options'),
            'referrer' => $response->header('referrer-policy'),
            'permissions' => $response->header('permissions-policy'),
            'poweredBy' => $response->header('x-powered-by'),
            'server' => $response->header('server'),
        ];
        $facts['spaHint'] = $facts['wordCount'] < 60
            && ((int) $xpath->evaluate('count(//*[@id="root" or @id="app" or @id="__next" or @id="__nuxt" or @ng-app or @data-reactroot])') > 0
                || $facts['scripts']['total'] >= 8);

        return $facts;
    }

    private function toUtf8(string $body, string $contentTypeHeader): string
    {
        $charset = '';
        if (preg_match('/charset=["\']?([\w-]+)/i', $contentTypeHeader, $m)) {
            $charset = $m[1];
        } elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($body, 0, 4096), $m)) {
            $charset = $m[1];
        }
        $charset = strtoupper($charset);
        if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'UTF8') {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return mb_check_encoding($body, 'UTF-8') ? $body : mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
    }

    private function headFacts(\DOMXPath $xpath, string $url, HttpResult $response): array
    {
        $titles = $xpath->query('/html/head/title');
        if ($titles->length === 0) {
            $titles = $xpath->query('//title[not(ancestor::svg)]');
        }
        $title = $titles->length > 0 ? $this->clean($titles->item(0)->textContent) : '';

        $descriptions = $xpath->query('//meta[translate(@name,"DESCRIPTION","description")="description"]/@content');
        $robots = [];
        foreach ($xpath->query('//meta[translate(@name,"ROBOTSGLEBOT","robotsglebot")="robots" or translate(@name,"ROBOTSGLEBOT","robotsglebot")="googlebot"]/@content') as $node) {
            $robots[] = strtolower($node->nodeValue);
        }

        $canonicals = [];
        foreach ($xpath->query('//link[translate(@rel,"CANONICAL","canonical")="canonical"]/@href') as $node) {
            $canonicals[] = trim($node->nodeValue);
        }
        // Canonical can also be sent as an HTTP Link header.
        if ($canonicals === [] && preg_match('/<([^>]+)>\s*;\s*rel="?canonical"?/i', $response->header('link'), $m)) {
            $canonicals[] = $m[1];
        }

        $hreflang = [];
        foreach ($xpath->query('//link[@rel="alternate"][@hreflang]') as $node) {
            /** @var \DOMElement $node */
            $hreflang[] = [
                'lang' => trim($node->getAttribute('hreflang')),
                'href' => UrlTools::resolve($url, $node->getAttribute('href')),
                'absolute' => (bool) preg_match('#^https?://#i', trim($node->getAttribute('href'))),
            ];
        }

        $meta = static function (string $query) use ($xpath): string {
            $nodes = $xpath->query($query);

            return $nodes !== false && $nodes->length > 0 ? trim((string) $nodes->item(0)->nodeValue) : '';
        };

        $ogImage = $meta('//meta[@property="og:image" or @name="og:image"]/@content');
        $viewport = $meta('//meta[@name="viewport"]/@content');

        return [
            'title' => $title,
            'titleCount' => $titles->length,
            'metaDescription' => $descriptions->length > 0 ? $this->clean($descriptions->item(0)->nodeValue) : '',
            'metaDescriptionCount' => $descriptions->length,
            'metaRobots' => implode(', ', $robots),
            'canonicals' => $canonicals,
            'canonical' => $canonicals !== [] ? UrlTools::resolve($url, $canonicals[0]) : '',
            'canonicalAbsolute' => $canonicals !== [] && (bool) preg_match('#^https?://#i', $canonicals[0]),
            'lang' => $meta('/html/@lang'),
            'charset' => $meta('//meta[@charset]/@charset') !== ''
                || $meta('//meta[translate(@http-equiv,"CONTENT-TYPE","content-type")="content-type"]/@content') !== ''
                || str_contains(strtolower($response->header('content-type')), 'charset'),
            'viewport' => $viewport,
            'metaRefresh' => $meta('//meta[translate(@http-equiv,"REFRESH","refresh")="refresh"]/@content'),
            'hreflang' => $hreflang,
            'og' => [
                'title' => $meta('//meta[@property="og:title" or @name="og:title"]/@content'),
                'description' => $meta('//meta[@property="og:description" or @name="og:description"]/@content'),
                'image' => $ogImage,
                'imageAbsolute' => $ogImage === '' || (bool) preg_match('#^https?://#i', $ogImage),
                'url' => $meta('//meta[@property="og:url" or @name="og:url"]/@content'),
                'type' => $meta('//meta[@property="og:type" or @name="og:type"]/@content'),
            ],
            'twitterCard' => $meta('//meta[@name="twitter:card" or @property="twitter:card"]/@content'),
            'favicon' => (int) $xpath->evaluate('count(//link[contains(translate(@rel,"ICON","icon"),"icon")])') > 0,
        ];
    }

    private function headingFacts(\DOMXPath $xpath): array
    {
        $h1 = [];
        $levels = [];
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') as $node) {
            $level = (int) substr(strtolower($node->nodeName), 1);
            $levels[] = $level;
            if ($level === 1) {
                $h1[] = mb_substr($this->clean($node->textContent), 0, 200);
            }
        }

        $skips = 0;
        $previous = 0;
        foreach ($levels as $level) {
            if ($previous > 0 && $level > $previous + 1) {
                $skips++;
            }
            $previous = $level;
        }

        return [
            'h1' => $h1,
            'h2Count' => count(array_filter($levels, static fn(int $l): bool => $l === 2)),
            'headingCount' => count($levels),
            'headingSkips' => $skips,
            'firstHeadingLevel' => $levels[0] ?? 0,
        ];
    }

    private function imageFacts(\DOMXPath $xpath, string $url): array
    {
        $facts = [
            'total' => 0, 'missingAlt' => 0, 'emptyAlt' => 0, 'linkedEmptyAlt' => 0, 'noDimensions' => 0,
            'lazy' => 0, 'raster' => 0, 'modern' => 0, 'examplesMissingAlt' => [], 'assets' => [],
        ];

        foreach ($xpath->query('//img') as $img) {
            /** @var \DOMElement $img */
            $src = trim($img->getAttribute('src') ?: $img->getAttribute('data-src'));
            if ($img->getAttribute('width') === '1' && $img->getAttribute('height') === '1') {
                continue; // tracking pixel
            }
            $facts['total']++;

            if (!$img->hasAttribute('alt')) {
                $facts['missingAlt']++;
                if (count($facts['examplesMissingAlt']) < 5 && $src !== '' && !str_starts_with($src, 'data:')) {
                    $facts['examplesMissingAlt'][] = UrlTools::resolve($url, $src);
                }
            } elseif (trim($img->getAttribute('alt')) === '') {
                // Empty alt marks decorative images, which is correct unless the image is the only link content.
                $facts['emptyAlt']++;
                $link = $this->closest($img, 'a');
                if ($link !== null && $this->clean($link->textContent) === ''
                    && trim($link->getAttribute('aria-label') . $link->getAttribute('title')) === ''
                ) {
                    $facts['linkedEmptyAlt']++;
                }
            }

            $style = strtolower($img->getAttribute('style'));
            if ((!$img->hasAttribute('width') || !$img->hasAttribute('height')) && !str_contains($style, 'aspect-ratio')) {
                $facts['noDimensions']++;
            }
            if (strtolower($img->getAttribute('loading')) === 'lazy' || $img->hasAttribute('data-src') || str_contains($img->getAttribute('class'), 'lazy')) {
                $facts['lazy']++;
            }

            $extension = strtolower(pathinfo((string) parse_url($src, PHP_URL_PATH), PATHINFO_EXTENSION));
            if ($extension === 'svg' || str_starts_with($src, 'data:image/svg')) {
                continue;
            }
            $facts['raster']++;
            $picture = $this->closest($img, 'picture');
            $srcset = strtolower($img->getAttribute('srcset'));
            $hasModernSource = $picture !== null
                && (int) (new \DOMXPath($img->ownerDocument))->evaluate('count(.//source[contains(@type,"webp") or contains(@type,"avif")])', $picture) > 0;
            if (in_array($extension, ['webp', 'avif'], true) || $hasModernSource || preg_match('/\.(webp|avif)\b/', $srcset)) {
                $facts['modern']++;
            }
            if ($src !== '' && !str_starts_with($src, 'data:') && count($facts['assets']) < 3) {
                $facts['assets'][] = UrlTools::resolve($url, $src);
            }
        }

        return $facts;
    }

    private function linkFacts(\DOMXPath $xpath, string $url): array
    {
        $internal = [];
        $external = [];
        $emptyAnchors = 0;
        $genericAnchors = 0;
        $nofollowInternal = 0;

        foreach ($xpath->query('//a[@href]') as $a) {
            /** @var \DOMElement $a */
            $href = trim($a->getAttribute('href'));
            if ($href === '' || $href[0] === '#' || preg_match('#^(mailto|tel|javascript|data|sms|whatsapp|callto|fax):#i', $href)) {
                continue;
            }
            $absolute = UrlTools::normalize(UrlTools::resolve($url, $href));
            if ($absolute === null) {
                continue;
            }

            $text = $this->clean($a->textContent);
            if ($text === '') {
                $text = trim($a->getAttribute('aria-label') ?: $a->getAttribute('title'));
            }
            if ($text === '') {
                foreach ($a->getElementsByTagName('img') as $img) {
                    $text = trim($img->getAttribute('alt'));
                    if ($text !== '') {
                        break;
                    }
                }
            }
            if ($text === '') {
                $emptyAnchors++;
            } elseif (in_array(mb_strtolower(rtrim($text, ' .…»>→')), self::GENERIC_ANCHORS, true)) {
                $genericAnchors++;
            }

            $nofollow = str_contains(strtolower($a->getAttribute('rel')), 'nofollow');
            if (UrlTools::isSameSite($absolute, $url)) {
                if ($nofollow) {
                    $nofollowInternal++;
                }
                if (count($internal) < self::MAX_LINKS) {
                    $internal[$absolute] ??= ['url' => $absolute, 'nofollow' => $nofollow];
                }
            } elseif (count($external) < self::MAX_LINKS) {
                $external[$absolute] ??= ['url' => $absolute, 'nofollow' => $nofollow];
            }
        }

        return [
            'internalLinks' => array_values($internal),
            'externalLinks' => array_values($external),
            'emptyAnchors' => $emptyAnchors,
            'genericAnchors' => $genericAnchors,
            'nofollowInternal' => $nofollowInternal,
        ];
    }

    private function structuredDataFacts(\DOMXPath $xpath): array
    {
        $types = [];
        $errors = 0;
        $missing = [];

        foreach ($xpath->query('//script[translate(@type,"APPLICATIONLDJSON","applicationldjson")="application/ld+json"]') as $script) {
            $json = trim($script->textContent);
            $json = preg_replace('/^\s*<!--|-->\s*$/', '', $json) ?? $json;
            try {
                $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $errors++;
                continue;
            }
            foreach ($this->schemaNodes($data) as $node) {
                foreach ((array) ($node['@type'] ?? []) as $type) {
                    if (!is_string($type)) {
                        continue;
                    }
                    $type = preg_replace('#^https?://schema\.org/#', '', $type) ?? $type;
                    $types[] = $type;
                    $requiredType = isset(self::SCHEMA_REQUIRED[$type]) ? $type : (str_ends_with($type, 'Business') ? 'LocalBusiness' : null);
                    if ($requiredType === null) {
                        continue;
                    }
                    foreach (self::SCHEMA_REQUIRED[$requiredType] as $property) {
                        if (!isset($node[$property]) || $node[$property] === '' || $node[$property] === []) {
                            $missing[] = $type . '.' . $property;
                        }
                    }
                }
            }
        }

        $microdata = [];
        foreach ($xpath->query('//*[@itemscope][@itemtype]/@itemtype') as $node) {
            $microdata[] = preg_replace('#^https?://schema\.org/#', '', trim($node->nodeValue)) ?? '';
        }

        return [
            'schemaTypes' => array_values(array_unique(array_merge($types, array_filter($microdata)))),
            'schemaErrors' => $errors,
            'schemaMissing' => array_values(array_unique($missing)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function schemaNodes(mixed $data, int $depth = 0): array
    {
        if (!is_array($data) || $depth > 6) {
            return [];
        }
        $nodes = [];
        if (isset($data['@type'])) {
            $nodes[] = $data;
        }
        foreach ($data as $key => $value) {
            if (is_array($value) && ($key === '@graph' || array_is_list($data) || $key === 'mainEntity' || $key === 'itemReviewed')) {
                array_push($nodes, ...$this->schemaNodes($value, $depth + 1));
            }
        }

        return $nodes;
    }

    private function resourceFacts(\DOMXPath $xpath, string $url): array
    {
        $scriptsTotal = 0;
        $blocking = 0;
        $assets = [];
        foreach ($xpath->query('//script[@src]') as $script) {
            /** @var \DOMElement $script */
            $scriptsTotal++;
            $inHead = $this->closest($script, 'head') !== null;
            $type = strtolower($script->getAttribute('type'));
            if ($inHead && !$script->hasAttribute('async') && !$script->hasAttribute('defer') && $type !== 'module') {
                $blocking++;
            }
            if (count($assets) < 3) {
                $assets[] = UrlTools::resolve($url, $script->getAttribute('src'));
            }
        }

        $stylesTotal = 0;
        foreach ($xpath->query('//link[contains(translate(@rel,"STYLESHEET","stylesheet"),"stylesheet")][@href]') as $link) {
            /** @var \DOMElement $link */
            $stylesTotal++;
            if (count($assets) < 6) {
                $assets[] = UrlTools::resolve($url, $link->getAttribute('href'));
            }
        }

        $mixed = [];
        if (str_starts_with($url, 'https://')) {
            $query = '//img/@src|//script/@src|//link[contains(@rel,"stylesheet")]/@href|//iframe/@src|//source/@src|//source/@srcset|//video/@src|//audio/@src|//img/@srcset|//embed/@src|//object/@data';
            foreach ($xpath->query($query) as $attr) {
                $value = trim($attr->nodeValue);
                if (preg_match('#(^|[\s,])http://#i', $value)) {
                    $mixed[] = mb_substr($value, 0, 200);
                }
            }
        }

        return [
            'scripts' => ['total' => $scriptsTotal, 'blockingHead' => $blocking, 'inline' => (int) $xpath->evaluate('count(//script[not(@src)])')],
            'stylesheets' => $stylesTotal,
            'assets' => array_values(array_unique(array_filter($assets))),
            'mixedContent' => count($mixed),
            'mixedExamples' => array_slice(array_values(array_unique($mixed)), 0, 5),
        ];
    }

    private function contentFacts(\DOMDocument $dom, \DOMXPath $xpath, int $htmlLength): array
    {
        foreach (iterator_to_array($xpath->query('//script|//style|//noscript|//template|//svg|//iframe')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = $xpath->query('//body')->item(0);
        $bodyText = $body !== null ? $this->clean($body->textContent) : '';

        // Main content: <main>/<article> if present, otherwise body without page chrome.
        $main = $xpath->query('//main|//*[@role="main"]')->item(0) ?? $xpath->query('//article')->item(0);
        if ($main === null && $body !== null) {
            $main = $body->cloneNode(true);
            foreach (iterator_to_array((new \DOMXPath($dom))->query('.//header|.//nav|.//footer|.//aside|.//*[@role="navigation"]', $main) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        $mainText = $main !== null ? $this->clean($main->textContent) : '';

        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $mainText, $words);
        $wordList = $words[0];

        $frequencies = [];
        foreach ($wordList as $word) {
            $word = mb_strtolower($word);
            if (mb_strlen($word) < 4 || in_array($word, self::STOPWORDS, true) || is_numeric($word)) {
                continue;
            }
            $frequencies[$word] = ($frequencies[$word] ?? 0) + 1;
        }
        arsort($frequencies);

        return [
            'wordCount' => count($wordList),
            'textRatio' => $htmlLength > 0 ? round(strlen($bodyText) / $htmlLength * 100, 1) : 0.0,
            'contentHash' => count($wordList) >= 50 ? sha1(mb_strtolower(implode(' ', $wordList))) : '',
            'topTerms' => array_slice(array_keys($frequencies), 0, 8),
        ];
    }

    private function closest(\DOMNode $node, string $tagName): ?\DOMElement
    {
        $parent = $node->parentNode;
        while ($parent instanceof \DOMElement) {
            if (strtolower($parent->nodeName) === $tagName) {
                return $parent;
            }
            $parent = $parent->parentNode;
        }

        return null;
    }

    private function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
