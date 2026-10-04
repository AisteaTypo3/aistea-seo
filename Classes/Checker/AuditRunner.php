<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Checker;

use Aistea\AisteaSeo\Checker\Http\SafeHttpClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Runs a public audit as a resumable state machine. Each call to step() does a bounded amount of
 * work (time budget) and persists the state, so no cron job is needed and the browser can show
 * live progress. Phases: site -> crawl -> links -> performance -> finalize.
 */
final class AuditRunner implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const STEP_BUDGET_SECONDS = 10.0;
    private const MAX_QUEUE = 1500;
    private const SITEMAP_SEED = 200;

    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly PageAnalyzer $pageAnalyzer,
        private readonly SiteAnalyzer $siteAnalyzer,
        private readonly PageSpeedClient $pageSpeed,
        private readonly ReportBuilder $reportBuilder,
        private readonly AuditRepository $repository,
        private readonly CheckerSettings $settings,
    ) {}

    /**
     * Fetches the start page. Throws AuditException when the site cannot be audited,
     * before any quota is consumed.
     *
     * @return array<string, mixed> initial state
     */
    public function initialize(string $url): array
    {
        $response = $this->http->fetch($url, 5, ['certInfo' => true]);
        if ($response->error !== '') {
            throw new AuditException(str_starts_with($response->error, 'blocked:') ? substr($response->error, 8) : $response->error);
        }
        if ($response->status >= 400 || $response->status === 0) {
            throw new AuditException('httpStatus', [$response->status]);
        }
        $facts = $this->pageAnalyzer->analyze($response, $url);
        if (!$facts['isHtml']) {
            throw new AuditException('notHtml');
        }

        $finalUrl = UrlTools::normalize($response->finalUrl) ?? $response->finalUrl;
        $state = [
            'version' => 1,
            'startUrl' => $url,
            'finalUrl' => $finalUrl,
            'origin' => UrlTools::origin($finalUrl),
            'host' => UrlTools::host($finalUrl),
            'maxPages' => $this->settings->maxPages,
            'startedAt' => time(),
            'certExpires' => $response->certExpires,
            'entryRedirects' => $response->redirects,
            'tasks' => ['robots', 'sitemaps', 'variants', 'soft404', 'wellKnown'],
            'site' => [],
            'sitemapPending' => [],
            'sitemapFiles' => [],
            'sitemapSample' => [],
            'pages' => [],
            'seen' => [$finalUrl => true],
            'queue' => [],
            'queued' => [],
            'sitemapQueue' => [],
            'links' => [],
            'linkQueue' => null,
            'linkResults' => [],
            'assetResults' => [],
            'psi' => null,
            'current' => '',
        ];
        $this->storePage($state, $finalUrl, $facts, 0, 'start');

        return $state;
    }

    /**
     * @return array{status: string, phase: string, progress: int, current: string}
     */
    public function step(string $token): array
    {
        $audit = $this->repository->findByToken($token, true);
        if ($audit === null) {
            throw new AuditException('notFound');
        }
        if ($audit['status'] !== AuditRepository::STATUS_RUNNING) {
            return $this->status($audit['status'], (string) $audit['phase'], (int) $audit['progress'], '');
        }
        if (!$this->repository->acquireLock($token)) {
            return $this->status('busy', (string) $audit['phase'], (int) $audit['progress'], '');
        }

        @set_time_limit(150);
        $phase = (string) $audit['phase'];
        try {
            $state = json_decode((string) $audit['state'], true, 512, JSON_THROW_ON_ERROR);
            $deadline = microtime(true) + self::STEP_BUDGET_SECONDS;

            $phase = match ($phase) {
                'site' => $this->runSite($state, $deadline),
                'crawl' => $this->runCrawl($state, $deadline),
                'links' => $this->runLinks($state, $deadline),
                'performance' => $this->runPerformance($state, (string) $audit['language']),
                default => 'finalize',
            };

            if ($phase === 'finalize') {
                $result = $this->reportBuilder->build($state, $this->settings);
                $this->repository->complete($token, $result);

                return $this->status(AuditRepository::STATUS_DONE, 'done', 100, '');
            }

            $progress = $this->progress($phase, $state);
            $this->repository->saveProgress($token, $phase, $progress, $state);

            return $this->status(AuditRepository::STATUS_RUNNING, $phase, $progress, (string) ($state['current'] ?? ''));
        } catch (\Throwable $e) {
            $this->logger?->error('SEO checker step failed', ['token' => $token, 'phase' => $phase, 'exception' => $e]);
            $this->repository->fail($token, 'internal');

            return $this->status(AuditRepository::STATUS_FAILED, $phase, (int) $audit['progress'], '');
        }
    }

    private function runSite(array &$state, float $deadline): string
    {
        while ($state['tasks'] !== [] && microtime(true) < $deadline) {
            $task = $state['tasks'][0];
            $state['current'] = $task;

            switch ($task) {
                case 'robots':
                    $state['site']['robots'] = $this->siteAnalyzer->robots($state['origin']);
                    $state['sitemapPending'] = $this->siteAnalyzer->sitemapCandidates($state['origin'], $state['site']['robots']['sitemaps']);
                    array_shift($state['tasks']);
                    break;

                case 'sitemaps':
                    // One sitemap file per iteration; index files enqueue their children.
                    $next = array_shift($state['sitemapPending']);
                    // robots.txt sitemaps all unreachable: fall back to the default locations once.
                    if ($next === null && empty($state['sitemapFallback'])
                        && array_filter($state['sitemapFiles'], static fn(array $f): bool => $f['valid']) === []
                    ) {
                        $state['sitemapFallback'] = true;
                        $state['sitemapPending'] = array_values(array_diff(
                            $this->siteAnalyzer->sitemapCandidates($state['origin'], []),
                            array_column($state['sitemapFiles'], 'url')
                        ));
                        $next = array_shift($state['sitemapPending']);
                    }
                    if ($next === null || count($state['sitemapFiles']) >= $this->siteAnalyzer->maxSitemapFiles()) {
                        $state['sitemapPending'] = [];
                        array_shift($state['tasks']);
                        break;
                    }
                    $file = $this->siteAnalyzer->sitemapFile($next);
                    $foundBefore = array_filter($state['sitemapFiles'], static fn(array $f): bool => $f['valid']);
                    $state['sitemapFiles'][] = array_diff_key($file, ['urls' => 1, 'children' => 1]) + ['childCount' => count($file['children'])];
                    foreach ($file['children'] as $child) {
                        if (!in_array($child, $state['sitemapPending'], true)) {
                            $state['sitemapPending'][] = $child;
                        }
                    }
                    foreach ($file['urls'] as $pageUrl) {
                        if (count($state['sitemapSample']) < 2000) {
                            $state['sitemapSample'][] = $pageUrl;
                        }
                    }
                    // Default candidates (no robots.txt hint): stop at the first valid one.
                    $usingDefaults = $state['site']['robots']['sitemaps'] === [] || !empty($state['sitemapFallback']);
                    if ($usingDefaults && $file['valid'] && $foundBefore === [] && $file['type'] === 'urlset') {
                        $state['sitemapPending'] = [];
                    }
                    if ($usingDefaults && $file['valid'] && $file['type'] === 'index') {
                        $state['sitemapPending'] = array_values(array_filter(
                            $state['sitemapPending'],
                            static fn(string $u): bool => in_array($u, $file['children'], true)
                        ));
                    }
                    break;

                case 'variants':
                    $state['site']['variants'] = $this->siteAnalyzer->variants($state['finalUrl']);
                    array_shift($state['tasks']);
                    break;

                case 'soft404':
                    $state['site']['soft404'] = $this->siteAnalyzer->soft404($state['origin']);
                    array_shift($state['tasks']);
                    break;

                case 'wellKnown':
                    $state['site']['wellKnown'] = $this->siteAnalyzer->wellKnown($state['origin']);
                    array_shift($state['tasks']);
                    break;

                default:
                    array_shift($state['tasks']);
            }
        }

        if ($state['tasks'] !== []) {
            return 'site';
        }

        $state['sitemapSample'] = array_values(array_unique($state['sitemapSample']));
        $sameHost = array_values(array_filter(
            $state['sitemapSample'],
            static fn(string $u): bool => UrlTools::host($u) === $state['host']
        ));
        // Evenly spread sample so the crawl sees different sections of large sitemaps.
        $step = max(1, (int) floor(count($sameHost) / self::SITEMAP_SEED));
        for ($i = 0; $i < count($sameHost) && count($state['sitemapQueue']) < self::SITEMAP_SEED; $i += $step) {
            $state['sitemapQueue'][] = $sameHost[$i];
        }
        $state['current'] = '';

        return 'crawl';
    }

    private function runCrawl(array &$state, float $deadline): string
    {
        $robots = new RobotsTxt((string) ($state['site']['robots']['content'] ?? ''));
        $iteration = 0;

        while (count($state['pages']) < $state['maxPages'] && microtime(true) < $deadline) {
            // Every third page comes from the sitemap so sitemap quality is sampled as well.
            $fromSitemap = $state['sitemapQueue'] !== [] && ($iteration % 3 === 2 || $state['queue'] === []);
            $entry = $fromSitemap ? [array_shift($state['sitemapQueue']), 1, 'sitemap'] : array_shift($state['queue']);
            if ($entry === null) {
                break;
            }
            [$url, $depth, $source] = $entry;
            $iteration++;

            if (isset($state['seen'][$url]) || !$robots->isAllowed($url, SafeHttpClient::USER_AGENT)) {
                continue;
            }
            $state['seen'][$url] = true;
            $state['current'] = UrlTools::pathWithQuery($url);

            $response = $this->http->fetch($url, 5);
            if ($response->redirects !== []) {
                $final = UrlTools::normalize($response->finalUrl) ?? $response->finalUrl;
                $state['pages'][$url] = [
                    'url' => $url,
                    'status' => $response->redirects[0]['status'],
                    'redirectTo' => $final,
                    'hops' => count($response->redirects),
                    'source' => $source,
                    'isHtml' => false,
                ];
                if (isset($state['seen'][$final]) || UrlTools::host($final) !== $state['host'] || count($state['pages']) >= $state['maxPages']) {
                    continue;
                }
                $state['seen'][$final] = true;
                $url = $final;
            }

            $this->storePage($state, $url, $this->pageAnalyzer->analyze($response, $url), $depth, $source);
        }

        $exhausted = $state['queue'] === [] && $state['sitemapQueue'] === [];
        if (count($state['pages']) < $state['maxPages'] && !$exhausted) {
            return 'crawl';
        }
        $state['queue'] = [];
        $state['queued'] = [];
        $state['sitemapQueue'] = [];
        $state['current'] = '';

        return 'links';
    }

    private function runLinks(array &$state, float $deadline): string
    {
        if ($state['linkQueue'] === null) {
            $internal = [];
            $external = [];
            foreach ($state['links'] as $target => $info) {
                if (isset($state['pages'][$target])) {
                    continue; // status already known from the crawl
                }
                if ($info['internal']) {
                    $internal[] = $target;
                } else {
                    $external[] = $target;
                }
            }
            $limit = $this->settings->linkCheckLimit;
            $internal = array_slice($internal, 0, (int) ceil($limit * 0.6));
            $external = array_slice($external, 0, max(0, $limit - count($internal)));
            $state['linkQueue'] = array_merge($internal, $external);
            $state['linkTotal'] = count($state['linkQueue']);

            $home = $state['pages'][$state['finalUrl']] ?? [];
            $assets = array_slice(array_values(array_unique(array_merge($home['assets'] ?? [], $home['images']['assets'] ?? []))), 0, 8);
            foreach ($this->http->checkMany($assets, 8, 8) as $assetUrl => $result) {
                $state['assetResults'][$assetUrl] = [
                    'status' => $result->status,
                    'cacheControl' => strtolower($result->header('cache-control')),
                    'expires' => $result->header('expires'),
                ];
            }
        }

        while ($state['linkQueue'] !== [] && microtime(true) < $deadline) {
            $batch = array_splice($state['linkQueue'], 0, 16);
            $state['current'] = UrlTools::host($batch[0]);
            foreach ($this->http->checkMany($batch, 8, 10) as $url => $result) {
                $state['linkResults'][$url] = [
                    'status' => $result->status,
                    'error' => $result->error,
                    'location' => $result->isRedirect() ? UrlTools::resolve($url, $result->header('location')) : '',
                ];
            }
        }

        if ($state['linkQueue'] !== []) {
            return 'links';
        }
        $state['current'] = '';

        return $this->settings->pageSpeedEnabled ? 'performance' : 'finalize';
    }

    private function runPerformance(array &$state, string $language): string
    {
        $state['psi'] = $this->pageSpeed->analyze($state['finalUrl'], $language === 'en' ? 'en' : 'de');

        return 'finalize';
    }

    /**
     * Stores page facts and moves its links into the shared link graph to keep the state compact.
     */
    private function storePage(array &$state, string $url, array $facts, int $depth, string $source): void
    {
        $facts['depth'] = $depth;
        $facts['source'] = $source;

        foreach (['internalLinks' => true, 'externalLinks' => false] as $key => $isInternal) {
            foreach ($facts[$key] ?? [] as $link) {
                $target = $link['url'];
                if (!isset($state['links'][$target])) {
                    if (count($state['links']) >= 5000) {
                        continue;
                    }
                    $state['links'][$target] = ['internal' => $isInternal, 'sources' => []];
                }
                if (count($state['links'][$target]['sources']) < 3 && !in_array($url, $state['links'][$target]['sources'], true)) {
                    $state['links'][$target]['sources'][] = $url;
                }

                if ($isInternal && !$link['nofollow'] && UrlTools::host($target) === $state['host']
                    && UrlTools::isLikelyDocument($target) && UrlTools::queryParamCount($target) <= 2
                    && !isset($state['seen'][$target]) && !isset($state['queued'][$target])
                    && count($state['queue']) < self::MAX_QUEUE
                ) {
                    $state['queue'][] = [$target, $depth + 1, 'link'];
                    $state['queued'][$target] = true;
                }
            }
            $facts[$key . 'Count'] = count($facts[$key] ?? []);
            unset($facts[$key]);
        }

        $state['pages'][$url] = $facts;
    }

    private function progress(string $phase, array $state): int
    {
        return match ($phase) {
            'site' => 5 + (int) round((5 - count($state['tasks'])) / 5 * 10),
            'crawl' => 15 + (int) round(min(1, count($state['pages']) / max(1, $state['maxPages'])) * 55),
            'links' => $state['linkQueue'] === null ? 70 : 70 + (int) round((1 - count($state['linkQueue'] ?? []) / max(1, $state['linkTotal'] ?? 1)) * 15),
            'performance' => 88,
            default => 95,
        };
    }

    private function status(string $status, string $phase, int $progress, string $current): array
    {
        return ['status' => $status, 'phase' => $phase, 'progress' => $progress, 'current' => mb_substr($current, 0, 120)];
    }
}
