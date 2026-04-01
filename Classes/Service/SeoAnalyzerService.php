<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Service;

use Aistea\AisteaSeo\Domain\Model\SeoPage;
use Aistea\AisteaSeo\Domain\Model\SeoReport;
use Aistea\AisteaSeo\Domain\Repository\SeoPageRepository;
use Aistea\AisteaSeo\Domain\Repository\SeoReportRepository;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

class SeoAnalyzerService
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly SeoPageRepository $seoPageRepository,
        private readonly SeoReportRepository $seoReportRepository,
        private readonly PersistenceManager $persistenceManager,
    ) {}

    public function assertAllowedBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('A website URL is required.');
        }

        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Enter a valid absolute URL starting with http:// or https://.');
        }

        $parts = parse_url($baseUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only http:// and https:// URLs are allowed.');
        }

        if ($host === '') {
            throw new \InvalidArgumentException('The URL must include a hostname.');
        }

        if ($this->isBlockedHostName($host)) {
            throw new \InvalidArgumentException('Private or local network targets are not allowed.');
        }

        foreach ($this->resolveHostAddresses($host) as $ipAddress) {
            if ($this->isBlockedIpAddress($ipAddress)) {
                throw new \InvalidArgumentException('The hostname resolves to a private or reserved IP address and cannot be crawled.');
            }
        }

        return rtrim($baseUrl, '/');
    }

    public function analyzeWebsite(SeoReport $report): void
    {
        $now = time();
        $report->setStatus(SeoReport::STATUS_RUNNING);
        $report->setQueuedAt($report->getQueuedAt() > 0 ? $report->getQueuedAt() : $now);
        $report->setStartedAt($now);
        $report->setFinishedAt(0);
        $report->setPagesCrawled(0);
        $report->setProgressPages(0);
        $report->setOverallScore(0);
        $report->setLastCrawledUrl('');
        $report->setErrorMessage('');
        $this->seoReportRepository->update($report);
        $this->persistenceManager->persistAll();

        try {
            $baseUrl = $this->assertAllowedBaseUrl($report->getBaseUrl());
            $maxPages = max(1, min(200, $report->getMaxPages()));
            $visited = [];
            $queue = [$baseUrl];
            $crawled = 0;
            $totalScore = 0;

            while (!empty($queue) && $crawled < $maxPages) {
                $url = array_shift($queue);

                // Normalise and skip duplicates / non-http URLs
                $url = $this->normalizeUrl($url, $baseUrl);
                if ($url === null || in_array($url, $visited, true) || $this->shouldSkipCrawlUrl($url)) {
                    continue;
                }

                $visited[] = $url;
                $pageData = $this->analyzePage($url);

                $seoPage = new SeoPage();
                $seoPage->setReport($report->getUid());
                $seoPage->setUrl($url);
                $seoPage->setStatusCode($pageData['statusCode']);
                $seoPage->setPageType($pageData['pageType']);
                $seoPage->setContentType($pageData['contentType']);
                $seoPage->setRedirectTarget($pageData['redirectTarget']);
                $seoPage->setRedirectFinalUrl($pageData['redirectFinalUrl']);
                $seoPage->setRedirectHops($pageData['redirectHops']);
                $seoPage->setPageTitle($pageData['title']);
                $seoPage->setTitleLength(mb_strlen($pageData['title']));
                $seoPage->setMetaDescription($pageData['metaDescription']);
                $seoPage->setMetaDescriptionLength(mb_strlen($pageData['metaDescription']));
                $seoPage->setH1Count($pageData['h1Count']);
                $seoPage->setH1Text($pageData['h1Text']);
                $seoPage->setH2Count($pageData['h2Count']);
                $seoPage->setCanonicalUrl($pageData['canonical']);
                $seoPage->setRobotsNoindex($pageData['robotsNoindex'] ? 1 : 0);
                $seoPage->setRobotsNofollow($pageData['robotsNofollow'] ? 1 : 0);
                $seoPage->setImagesTotal($pageData['imagesTotal']);
                $seoPage->setImagesMissingAlt($pageData['imagesMissingAlt']);
                $seoPage->setLinksInternal($pageData['linksInternal']);
                $seoPage->setLinksExternal($pageData['linksExternal']);
                $seoPage->setWordCount($pageData['wordCount']);
                $seoPage->setLoadTime($pageData['loadTime']);
                $seoPage->setPageScore($pageData['score']);
                $seoPage->setIssues(json_encode($pageData['issues'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                $this->seoPageRepository->add($seoPage);

                foreach ($pageData['internalLinks'] as $link) {
                    $normalized = $this->normalizeUrl($link, $baseUrl);
                    if (
                        $normalized !== null
                        && !$this->shouldSkipCrawlUrl($normalized)
                        && !in_array($normalized, $visited, true)
                        && !in_array($normalized, $queue, true)
                        && $this->isSameDomain($normalized, $baseUrl)
                    ) {
                        $queue[] = $normalized;
                    }
                }

                if (($pageData['redirectQueueTarget'] ?? '') !== '') {
                    $normalizedRedirectTarget = $this->normalizeUrl($pageData['redirectQueueTarget'], $baseUrl);
                    if (
                        $normalizedRedirectTarget !== null
                        && !$this->shouldSkipCrawlUrl($normalizedRedirectTarget)
                        && !in_array($normalizedRedirectTarget, $visited, true)
                        && !in_array($normalizedRedirectTarget, $queue, true)
                        && $this->isSameDomain($normalizedRedirectTarget, $baseUrl)
                    ) {
                        $queue[] = $normalizedRedirectTarget;
                    }
                }

                $crawled++;
                $totalScore += $pageData['score'];
                $report->setPagesCrawled($crawled);
                $report->setProgressPages($crawled);
                $report->setLastCrawledUrl($url);
                $this->seoReportRepository->update($report);
                $this->persistenceManager->persistAll();
            }

            $this->applyCrossPageIssues($report);
            $report->setPagesCrawled($crawled);
            $report->setProgressPages($crawled);
            $report->setStatus(SeoReport::STATUS_COMPLETED);
            $report->setFinishedAt(time());
        } catch (\Throwable $e) {
            $report->setStatus(SeoReport::STATUS_FAILED);
            $report->setErrorMessage($e->getMessage());
            $report->setFinishedAt(time());
        }

        $this->seoReportRepository->update($report);
        $this->persistenceManager->persistAll();
    }

    public function queueReport(SeoReport $report): void
    {
        $this->assertAllowedBaseUrl($report->getBaseUrl());

        $report->setStatus(SeoReport::STATUS_QUEUED);
        $report->setQueuedAt(time());
        $report->setStartedAt(0);
        $report->setFinishedAt(0);
        $report->setProgressPages(0);
        $report->setLastCrawledUrl('');
        $report->setErrorMessage('');
        $this->seoReportRepository->update($report);
        $this->persistenceManager->persistAll();
    }

    private function applyCrossPageIssues(SeoReport $report): void
    {
        $pages = $this->seoPageRepository->findByReportUid($report->getUid());
        $titleMap = [];
        $metaDescriptionMap = [];

        foreach ($pages as $page) {
            if ($page->getPageType() !== 'html') {
                continue;
            }

            $title = trim($page->getPageTitle());
            if ($title !== '') {
                $titleMap[mb_strtolower($title)][] = $page;
            }

            $metaDescription = trim($page->getMetaDescription());
            if ($metaDescription !== '') {
                $metaDescriptionMap[mb_strtolower($metaDescription)][] = $page;
            }
        }

        foreach ($titleMap as $matchingPages) {
            if (count($matchingPages) < 2) {
                continue;
            }

            foreach ($matchingPages as $page) {
                $issues = $page->getIssuesArray();
                $issues = $this->appendIssueIfMissing(
                    $issues,
                    'duplicate_title',
                    'warning',
                    'Page title is duplicated on multiple crawled pages'
                );
                $page->setIssues(json_encode($issues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $page->setPageScore($this->calculateScore($issues));
                $this->seoPageRepository->update($page);
            }
        }

        foreach ($metaDescriptionMap as $matchingPages) {
            if (count($matchingPages) < 2) {
                continue;
            }

            foreach ($matchingPages as $page) {
                $issues = $page->getIssuesArray();
                $issues = $this->appendIssueIfMissing(
                    $issues,
                    'duplicate_meta_description',
                    'notice',
                    'Meta description is duplicated on multiple crawled pages'
                );
                $page->setIssues(json_encode($issues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $page->setPageScore($this->calculateScore($issues));
                $this->seoPageRepository->update($page);
            }
        }

        $this->persistenceManager->persistAll();

        $updatedPages = $this->seoPageRepository->findByReportUid($report->getUid());
        $totalScore = array_sum(array_map(static fn(SeoPage $page): int => $page->getPageScore(), $updatedPages));
        $report->setOverallScore($updatedPages !== [] ? (int) round($totalScore / count($updatedPages)) : 0);
    }

    /**
     * @param list<array{type?: string, severity?: string, message?: string}> $issues
     * @return list<array{type: string, severity: string, message: string}>
     */
    private function appendIssueIfMissing(array $issues, string $type, string $severity, string $message): array
    {
        foreach ($issues as $issue) {
            if (($issue['type'] ?? '') === $type) {
                return $issues;
            }
        }

        $issues[] = [
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
        ];

        return $issues;
    }

    /**
     * @param list<array{severity?: string}> $issues
     */
    private function calculateScore(array $issues): int
    {
        $score = 100;
        foreach ($issues as $issue) {
            $score -= match ($issue['severity'] ?? null) {
                'error' => 15,
                'warning' => 8,
                'notice' => 3,
                default => 0,
            };
        }

        return max(0, min(100, $score));
    }

    private function analyzePage(string $url): array
    {
        $startTime = microtime(true);
        $issues = [];

        try {
            $redirectInfo = $this->fetchResponseWithRedirects($url);
            $response = $redirectInfo['response'];
            $loadTime = (int) ((microtime(true) - $startTime) * 1000);
            $statusCode = $response->getStatusCode();
            $html = (string) $response->getBody();
            $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
            $redirectTarget = $redirectInfo['redirectTarget'];
            $redirectFinalUrl = $redirectInfo['redirectFinalUrl'];
            $redirectHops = $redirectInfo['redirectHops'];
        } catch (\Throwable $e) {
            $loadTime = (int) ((microtime(true) - $startTime) * 1000);
            return $this->emptyPageResult($loadTime, [
                ['type' => 'fetch_error', 'severity' => 'error', 'message' => 'Failed to fetch page: ' . $e->getMessage()],
            ]);
        }

        if ($redirectHops > 0) {
            $redirectIssues = [[
                'type' => 'redirect',
                'severity' => 'notice',
                'message' => $redirectHops === 1
                    ? 'URL responded with a redirect'
                    : sprintf('URL redirected %d times before reaching the final target', $redirectHops),
            ]];

            return [
                'statusCode' => $redirectInfo['initialStatusCode'],
                'pageType' => 'redirect',
                'contentType' => $contentType,
                'redirectTarget' => $redirectTarget,
                'redirectFinalUrl' => $redirectFinalUrl,
                'redirectHops' => $redirectHops,
                'redirectQueueTarget' => $redirectFinalUrl !== '' ? $redirectFinalUrl : $redirectTarget,
                'title' => '',
                'metaDescription' => '',
                'h1Count' => 0,
                'h1Text' => '',
                'h2Count' => 0,
                'canonical' => '',
                'robotsNoindex' => false,
                'robotsNofollow' => false,
                'imagesTotal' => 0,
                'imagesMissingAlt' => 0,
                'linksInternal' => 0,
                'linksExternal' => 0,
                'wordCount' => 0,
                'loadTime' => $loadTime,
                'score' => 100,
                'issues' => $redirectIssues,
                'internalLinks' => [],
            ];
        }

        if ($statusCode >= 400) {
            $issues[] = ['type' => 'http_error', 'severity' => 'error', 'message' => "HTTP error: {$statusCode}"];
        }

        if (!$this->isHtmlContentType($contentType, $url)) {
            return [
                'statusCode' => $statusCode,
                'pageType' => 'resource',
                'contentType' => $contentType,
                'redirectTarget' => '',
                'redirectFinalUrl' => '',
                'redirectHops' => 0,
                'redirectQueueTarget' => '',
                'title' => '',
                'metaDescription' => '',
                'h1Count' => 0,
                'h1Text' => '',
                'h2Count' => 0,
                'canonical' => '',
                'robotsNoindex' => false,
                'robotsNofollow' => false,
                'imagesTotal' => 0,
                'imagesMissingAlt' => 0,
                'linksInternal' => 0,
                'linksExternal' => 0,
                'wordCount' => 0,
                'loadTime' => $loadTime,
                'score' => $statusCode >= 400 ? 0 : 100,
                'issues' => $this->appendIssueIfMissing(
                    $issues,
                    'non_html_resource',
                    'notice',
                    sprintf('Skipped HTML checks for non-HTML resource (%s)', $contentType !== '' ? $contentType : 'unknown content type')
                ),
                'internalLinks' => [],
            ];
        }

        // Parse HTML
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        // --- Title ---
        $titleNodes = $xpath->query('//title');
        $title = $titleNodes !== false && $titleNodes->length > 0
            ? trim($titleNodes->item(0)->textContent)
            : '';
        $titleLength = mb_strlen($title);

        if ($title === '') {
            $issues[] = ['type' => 'missing_title', 'severity' => 'error', 'message' => 'Page title is missing'];
        } elseif ($titleLength > 60) {
            $issues[] = ['type' => 'title_too_long', 'severity' => 'warning', 'message' => "Title is too long ({$titleLength} chars, max 60 recommended)"];
        } elseif ($titleLength < 10) {
            $issues[] = ['type' => 'title_too_short', 'severity' => 'warning', 'message' => "Title is too short ({$titleLength} chars, min 10 recommended)"];
        }

        // --- Meta description ---
        $metaDescNodes = $xpath->query('//meta[@name="description"]/@content');
        $metaDescription = $metaDescNodes !== false && $metaDescNodes->length > 0
            ? trim($metaDescNodes->item(0)->nodeValue)
            : '';
        $metaDescLength = mb_strlen($metaDescription);

        if ($metaDescription === '') {
            $issues[] = ['type' => 'missing_meta_description', 'severity' => 'warning', 'message' => 'Meta description is missing'];
        } elseif ($metaDescLength > 160) {
            $issues[] = ['type' => 'meta_description_too_long', 'severity' => 'notice', 'message' => "Meta description is too long ({$metaDescLength} chars, max 160 recommended)"];
        } elseif ($metaDescLength < 50) {
            $issues[] = ['type' => 'meta_description_too_short', 'severity' => 'notice', 'message' => "Meta description is too short ({$metaDescLength} chars, min 50 recommended)"];
        }

        // --- H1 ---
        $h1Nodes = $xpath->query('//h1');
        $h1Count = $h1Nodes !== false ? $h1Nodes->length : 0;
        $h1Text = $h1Count > 0 ? mb_substr(trim($h1Nodes->item(0)->textContent), 0, 200) : '';

        if ($h1Count === 0) {
            $issues[] = ['type' => 'missing_h1', 'severity' => 'error', 'message' => 'Page has no H1 heading'];
        } elseif ($h1Count > 1) {
            $issues[] = ['type' => 'multiple_h1', 'severity' => 'warning', 'message' => "Page has {$h1Count} H1 headings (exactly 1 recommended)"];
        }

        // --- H2 ---
        $h2Nodes = $xpath->query('//h2');
        $h2Count = $h2Nodes !== false ? $h2Nodes->length : 0;

        // --- Canonical ---
        $canonicalNodes = $xpath->query('//link[@rel="canonical"]/@href');
        $canonical = $canonicalNodes !== false && $canonicalNodes->length > 0
            ? trim($canonicalNodes->item(0)->nodeValue)
            : '';

        if ($canonical === '') {
            $issues[] = ['type' => 'missing_canonical', 'severity' => 'notice', 'message' => 'No canonical URL defined'];
        }

        // --- Robots meta ---
        $robotsNodes = $xpath->query('//meta[@name="robots"]/@content');
        $robotsContent = $robotsNodes !== false && $robotsNodes->length > 0
            ? strtolower($robotsNodes->item(0)->nodeValue)
            : '';
        $robotsNoindex = str_contains($robotsContent, 'noindex');
        $robotsNofollow = str_contains($robotsContent, 'nofollow');

        if ($robotsNoindex) {
            $issues[] = ['type' => 'noindex', 'severity' => 'warning', 'message' => 'Page has noindex directive - it will not be indexed by search engines'];
        }

        // --- Images ---
        $imgNodes = $xpath->query('//img');
        $imagesTotal = $imgNodes !== false ? $imgNodes->length : 0;
        $imagesMissingAlt = 0;

        if ($imgNodes !== false) {
            foreach ($imgNodes as $img) {
                /** @var \DOMElement $img */
                if (!$img->hasAttribute('alt') || trim($img->getAttribute('alt')) === '') {
                    $imagesMissingAlt++;
                }
            }
        }

        if ($imagesMissingAlt > 0) {
            $issues[] = ['type' => 'images_missing_alt', 'severity' => 'warning', 'message' => "{$imagesMissingAlt} image(s) missing alt attributes"];
        }

        // --- Links ---
        $linkNodes = $xpath->query('//a[@href]');
        $internalLinks = [];
        $linksInternalCount = 0;
        $linksExternalCount = 0;
        $baseDomain = parse_url($url, PHP_URL_HOST);

        if ($linkNodes !== false) {
            foreach ($linkNodes as $link) {
                /** @var \DOMElement $link */
                $href = trim($link->getAttribute('href'));
                if (
                    $href === ''
                    || str_starts_with($href, '#')
                    || str_starts_with($href, 'mailto:')
                    || str_starts_with($href, 'tel:')
                    || str_starts_with($href, 'javascript:')
                ) {
                    continue;
                }

                if (!str_starts_with($href, 'http://') && !str_starts_with($href, 'https://')) {
                    $href = $this->resolveRelativeUrl($href, $url);
                }

                $linkDomain = parse_url($href, PHP_URL_HOST);

                if ($linkDomain === $baseDomain) {
                    $linksInternalCount++;
                    $internalLinks[] = $href;
                } else {
                    $linksExternalCount++;
                }
            }
        }

        // --- Word count ---
        $bodyNodes = $xpath->query('//body');
        $bodyText = $bodyNodes !== false && $bodyNodes->length > 0
            ? $bodyNodes->item(0)->textContent
            : '';
        $wordCount = str_word_count(strip_tags($bodyText));

        if ($wordCount < 300 && $statusCode < 400) {
            $issues[] = ['type' => 'thin_content', 'severity' => 'warning', 'message' => "Thin content: {$wordCount} words (min 300 recommended)"];
        }

        // --- Open Graph ---
        $ogTitleNodes = $xpath->query('//meta[@property="og:title"]/@content');
        if ($ogTitleNodes === false || $ogTitleNodes->length === 0) {
            $issues[] = ['type' => 'missing_og_title', 'severity' => 'notice', 'message' => 'Missing Open Graph og:title meta tag'];
        }

        $ogDescNodes = $xpath->query('//meta[@property="og:description"]/@content');
        if ($ogDescNodes === false || $ogDescNodes->length === 0) {
            $issues[] = ['type' => 'missing_og_description', 'severity' => 'notice', 'message' => 'Missing Open Graph og:description meta tag'];
        }

        // --- Score calculation ---
        $score = $this->calculateScore($issues);

        return [
            'statusCode' => $statusCode,
            'pageType' => 'html',
            'contentType' => $contentType,
            'redirectTarget' => '',
            'redirectFinalUrl' => '',
            'redirectHops' => 0,
            'redirectQueueTarget' => '',
            'title' => $title,
            'metaDescription' => $metaDescription,
            'h1Count' => $h1Count,
            'h1Text' => $h1Text,
            'h2Count' => $h2Count,
            'canonical' => $canonical,
            'robotsNoindex' => $robotsNoindex,
            'robotsNofollow' => $robotsNofollow,
            'imagesTotal' => $imagesTotal,
            'imagesMissingAlt' => $imagesMissingAlt,
            'linksInternal' => $linksInternalCount,
            'linksExternal' => $linksExternalCount,
            'wordCount' => $wordCount,
            'loadTime' => $loadTime,
            'score' => $score,
            'issues' => $issues,
            'internalLinks' => array_unique($internalLinks),
        ];
    }

    private function emptyPageResult(int $loadTime, array $issues): array
    {
        return [
            'statusCode' => 0,
            'pageType' => 'resource',
            'contentType' => '',
            'redirectTarget' => '',
            'redirectFinalUrl' => '',
            'redirectHops' => 0,
            'redirectQueueTarget' => '',
            'title' => '',
            'metaDescription' => '',
            'h1Count' => 0,
            'h1Text' => '',
            'h2Count' => 0,
            'canonical' => '',
            'robotsNoindex' => false,
            'robotsNofollow' => false,
            'imagesTotal' => 0,
            'imagesMissingAlt' => 0,
            'linksInternal' => 0,
            'linksExternal' => 0,
            'wordCount' => 0,
            'loadTime' => $loadTime,
            'score' => 0,
            'issues' => $issues,
            'internalLinks' => [],
        ];
    }

    /**
     * @return array{response: \Psr\Http\Message\ResponseInterface, redirectTarget: string, redirectFinalUrl: string, redirectHops: int, initialStatusCode: int}
     */
    private function fetchResponseWithRedirects(string $url): array
    {
        $currentUrl = $url;
        $firstRedirectTarget = '';
        $initialStatusCode = 0;
        $redirectHops = 0;

        for ($hop = 0; $hop < 5; $hop++) {
            $response = $this->requestFactory->request($currentUrl, 'GET', [
                'headers' => [
                    'User-Agent' => 'AIstea SEO Spider/1.0 (+https://aistea.me)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
                'timeout' => 15,
                'allow_redirects' => false,
                'verify' => true,
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            if ($initialStatusCode === 0) {
                $initialStatusCode = $statusCode;
            }

            if ($statusCode < 300 || $statusCode >= 400) {
                return [
                    'response' => $response,
                    'redirectTarget' => $firstRedirectTarget,
                    'redirectFinalUrl' => $redirectHops > 0 ? $currentUrl : '',
                    'redirectHops' => $redirectHops,
                    'initialStatusCode' => $initialStatusCode,
                ];
            }

            $location = trim($response->getHeaderLine('Location'));
            if ($location === '') {
                return [
                    'response' => $response,
                    'redirectTarget' => $firstRedirectTarget,
                    'redirectFinalUrl' => '',
                    'redirectHops' => $redirectHops,
                    'initialStatusCode' => $initialStatusCode,
                ];
            }

            $resolvedLocation = $this->resolveRelativeUrl($location, $currentUrl);
            if ($firstRedirectTarget === '') {
                $firstRedirectTarget = $resolvedLocation;
            }

            $currentUrl = $resolvedLocation;
            $redirectHops++;
        }

        throw new \RuntimeException('Too many redirects while fetching URL.');
    }

    private function normalizeUrl(string $url, string $baseUrl): ?string
    {
        // Strip fragments
        $url = (string) preg_replace('/#.*$/', '', $url);
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = $this->resolveRelativeUrl($url, $baseUrl);
        }

        // Strip query string for deduplication
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['host'])) {
            return null;
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $path = rtrim($parsed['path'] ?? '/', '/') ?: '/';

        return $scheme . '://' . $host . $path;
    }

    private function resolveRelativeUrl(string $relative, string $base): string
    {
        if (str_starts_with($relative, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?? 'https';
            return $scheme . ':' . $relative;
        }

        $parsed = parse_url($base);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '/';

        if (str_starts_with($relative, '/')) {
            return $scheme . '://' . $host . $relative;
        }

        return $scheme . '://' . $host . rtrim(dirname($path), '/') . '/' . $relative;
    }

    private function isSameDomain(string $url, string $baseUrl): bool
    {
        return parse_url($url, PHP_URL_HOST) === parse_url($baseUrl, PHP_URL_HOST);
    }

    private function shouldSkipCrawlUrl(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if ($path === '') {
            return false;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension === '') {
            return false;
        }

        return in_array($extension, [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', '7z',
            'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'avif', 'mp4', 'mp3', 'wav',
            'mov', 'avi', 'wmv', 'txt', 'csv', 'xml', 'json',
        ], true);
    }

    private function isHtmlContentType(string $contentType, string $url): bool
    {
        if ($contentType === '') {
            return !$this->shouldSkipCrawlUrl($url);
        }

        return str_contains($contentType, 'text/html') || str_contains($contentType, 'application/xhtml+xml');
    }

    private function isBlockedHostName(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }

        if (!str_contains($host, '.') && !filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            ? $this->isBlockedIpAddress($host)
            : false;
    }

    /**
     * @return list<string>
     */
    private function resolveHostAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ipAddresses = [];

        if (function_exists('dns_get_record')) {
            $dnsRecords = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($dnsRecords)) {
                foreach ($dnsRecords as $record) {
                    $ipAddress = $record['ip'] ?? $record['ipv6'] ?? null;
                    if (is_string($ipAddress) && $ipAddress !== '') {
                        $ipAddresses[] = $ipAddress;
                    }
                }
            }
        }

        if ($ipAddresses === [] && function_exists('gethostbynamel')) {
            $resolvedIpv4 = @gethostbynamel($host);
            if (is_array($resolvedIpv4)) {
                foreach ($resolvedIpv4 as $ipAddress) {
                    if (is_string($ipAddress) && $ipAddress !== '') {
                        $ipAddresses[] = $ipAddress;
                    }
                }
            }
        }

        return array_values(array_unique($ipAddresses));
    }

    private function isBlockedIpAddress(string $ipAddress): bool
    {
        return filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
