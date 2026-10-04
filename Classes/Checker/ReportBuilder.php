<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Checker;

/**
 * Turns collected facts into weighted checks and scores.
 *
 * Scoring: each check has a weight (1-10) and a status. pass = 100 %, warn = 50 %, fail = 0 %,
 * info is not scored. Page-level checks fail only when a relevant share of pages is affected,
 * so a single outlier does not dominate the result. Category scores are combined with fixed
 * category weights. The score expresses fulfilled best practices, not a ranking prediction.
 */
final class ReportBuilder
{
    public const CATEGORIES = [
        'indexing' => 30,
        'content' => 25,
        'performance' => 20,
        'structure' => 15,
        'security' => 10,
    ];

    private const MAX_URLS_PER_CHECK = 12;

    /** @var array<string, list<array<string, mixed>>> */
    private array $checks = [];

    /** @var array<string, int> */
    private array $pageIssues = [];

    private array $state = [];

    /**
     * @return array<string, mixed>
     */
    public function build(array $state, CheckerSettings $settings): array
    {
        $this->checks = array_fill_keys(array_keys(self::CATEGORIES), []);
        $this->pageIssues = [];
        $this->state = $state;

        $pages = $state['pages'];
        $html = array_filter($pages, static fn(array $p): bool => (bool) ($p['isHtml'] ?? false));
        $indexable = array_filter($html, fn(array $p): bool => !$this->isNoindex($p));
        $home = $pages[$state['finalUrl']] ?? reset($html) ?: [];

        $this->indexingChecks($state, $pages, $html, $home);
        $this->contentChecks($html, $indexable, $home);
        $this->performanceChecks($state, $html, $home);
        $this->structureChecks($state, $html, $indexable, $home);
        $this->securityChecks($state, $html, $home);

        return $this->assemble($state, $pages, $html, $settings);
    }

    // ---------------------------------------------------------------------------------------------
    // Indexing & crawling
    // ---------------------------------------------------------------------------------------------

    private function indexingChecks(array $state, array $pages, array $html, array $home): void
    {
        $c = 'indexing';
        $this->add($c, 'reachability', 'pass', 10, 'pass', [$home['status'] ?? 200]);

        $isHttps = str_starts_with($state['finalUrl'], 'https://');
        $this->add($c, 'https', $isHttps ? 'pass' : 'fail', 9, $isHttps ? 'pass' : 'fail');

        $http = $state['site']['variants']['http'] ?? null;
        if ($isHttps && $http !== null) {
            if ($http['error'] !== '' || $http['status'] === 0) {
                $this->add($c, 'http_redirect', 'pass', 6, 'unreachable');
            } elseif ($http['toHttps'] && in_array($http['status'], [301, 308], true)) {
                $this->add($c, 'http_redirect', 'pass', 6, 'pass', [$http['status']]);
            } elseif ($http['toHttps']) {
                $this->add($c, 'http_redirect', 'warn', 6, 'temporary', [$http['status']]);
            } else {
                $this->add($c, 'http_redirect', 'fail', 6, 'fail', [$http['status']]);
            }
        }

        $alternate = $state['site']['variants']['alternate'] ?? null;
        if ($alternate !== null) {
            if ($alternate['error'] !== '' && $alternate['status'] === 0) {
                $this->add($c, 'host_canonical', 'pass', 5, 'notAvailable', [$alternate['host']]);
            } elseif ($alternate['redirected'] && $alternate['finalHost'] === $state['host']) {
                $permanent = in_array($alternate['firstStatus'], [301, 308], true);
                $this->add($c, 'host_canonical', $permanent ? 'pass' : 'warn', 5, $permanent ? 'pass' : 'temporary', [$alternate['host'], $state['host'], $alternate['firstStatus']]);
            } elseif ($alternate['status'] >= 200 && $alternate['status'] < 300 && !$alternate['redirected']) {
                $this->add($c, 'host_canonical', 'fail', 5, 'duplicate', [$alternate['host'], $state['host']]);
            } else {
                $this->add($c, 'host_canonical', 'pass', 5, 'notAvailable', [$alternate['host']]);
            }
        }

        $hops = count($state['entryRedirects'] ?? []);
        $this->add($c, 'redirect_chain', $hops <= 1 ? 'pass' : ($hops === 2 ? 'warn' : 'fail'), 4, $hops === 0 ? 'none' : 'hops', [$hops],
            array_map(static fn(array $r): array => ['url' => $r['url'], 'note' => (string) $r['status']], $state['entryRedirects'] ?? []));

        // robots.txt
        $robots = $state['site']['robots'] ?? ['status' => 0, 'error' => 'missing', 'sitemaps' => [], 'blocksAll' => false, 'blocksGooglebot' => false, 'aiBlocked' => []];
        if ($robots['blocksAll'] || $robots['blocksGooglebot']) {
            $this->add($c, 'robots_txt', 'fail', 8, 'blocksAll');
        } elseif ($robots['status'] >= 500) {
            $this->add($c, 'robots_txt', 'fail', 8, 'serverError', [$robots['status']]);
        } elseif ($robots['status'] === 200) {
            $this->add($c, 'robots_txt', 'pass', 8, 'pass');
        } else {
            $this->add($c, 'robots_txt', 'warn', 8, 'missing', [$robots['status']]);
        }
        $validSitemaps = array_column(array_filter($state['sitemapFiles'] ?? [], static fn(array $f): bool => $f['valid']), 'url');
        $brokenReferences = array_values(array_diff(array_slice($robots['sitemaps'], 0, 5), $validSitemaps));
        if ($robots['sitemaps'] === []) {
            $this->add($c, 'robots_sitemap', 'warn', 2, 'missing');
        } elseif ($brokenReferences !== []) {
            $this->add($c, 'robots_sitemap', 'warn', 2, 'unreachable', [count($brokenReferences)],
                array_map(static fn(string $u): array => ['url' => $u], $brokenReferences));
        } else {
            $this->add($c, 'robots_sitemap', 'pass', 2, 'pass', [count($robots['sitemaps'])],
                array_map(static fn(string $u): array => ['url' => $u], $robots['sitemaps']));
        }

        // XML sitemap
        $files = $state['sitemapFiles'] ?? [];
        $valid = array_filter($files, static fn(array $f): bool => $f['valid']);
        $urlCount = array_sum(array_column($files, 'urlCount'));
        $invalid = array_filter($files, static fn(array $f): bool => $f['type'] === 'invalid');
        if ($valid !== [] && $urlCount > 0) {
            $this->add($c, 'sitemap', 'pass', 7, 'pass', [$urlCount, count($valid)],
                array_map(static fn(array $f): array => ['url' => $f['url'], 'note' => $f['type'] === 'index' ? 'Index' : (string) $f['urlCount']], array_values($valid)));
        } elseif ($invalid !== []) {
            $this->add($c, 'sitemap', 'fail', 7, 'invalid', [], array_map(static fn(array $f): array => ['url' => $f['url']], array_values($invalid)));
        } elseif ($valid !== []) {
            $this->add($c, 'sitemap', 'warn', 7, 'empty');
        } else {
            $this->add($c, 'sitemap', 'fail', 7, 'missing');
        }

        $sample = array_flip($state['sitemapSample'] ?? []);
        $fromSitemap = array_filter($pages, static fn(array $p, string $url): bool => isset($sample[$url]), ARRAY_FILTER_USE_BOTH);
        if ($fromSitemap === []) {
            $this->add($c, 'sitemap_quality', 'info', 0, 'notChecked');
        } else {
            $this->pageCheck($c, 'sitemap_quality', 5, $fromSitemap, function (array $p, string $url): string|bool {
                if (isset($p['redirectTo'])) {
                    return 'redirect ' . $p['status'];
                }
                if (($p['status'] ?? 0) >= 400 || ($p['error'] ?? '') !== '') {
                    return 'HTTP ' . ($p['status'] ?: $p['error']);
                }
                if ($this->isNoindex($p)) {
                    return 'noindex';
                }
                if ($this->canonicalPointsElsewhere($p, $url)) {
                    return 'canonical';
                }

                return false;
            }, 0.15);
        }

        // Indexability
        $homeNoindex = $home !== [] && $this->isNoindex($home);
        if ($homeNoindex) {
            $this->add($c, 'noindex', 'fail', 9, 'home', [], [['url' => $state['finalUrl']]]);
        } else {
            $this->pageCheck($c, 'noindex', 9, $html, fn(array $p): string|bool => $this->isNoindex($p) ? 'noindex' : false, 0.5, true, 'msg.noindexAffected');
        }

        $errors = array_filter($pages, static fn(array $p): bool => !isset($p['redirectTo']) && (($p['status'] ?? 0) >= 400 || ($p['error'] ?? '') !== ''));
        $hasServerError = array_filter($errors, static fn(array $p): bool => ($p['status'] ?? 0) >= 500) !== [];
        if ($errors === []) {
            $this->add($c, 'http_errors', 'pass', 8, 'msg.allPages', [count($pages)]);
        } else {
            $this->add($c, 'http_errors', $hasServerError || count($errors) / max(1, count($pages)) >= 0.1 ? 'fail' : 'warn', 8, 'msg.affected', [count($errors), count($pages)],
                $this->urlList($errors, static fn(array $p): string => (string) ($p['status'] ?: $p['error'])));
        }

        // Link checks
        [$brokenInternal, $brokenExternal, $redirectLinks, $checkedLinks] = $this->linkStatus($state);
        $this->add($c, 'broken_internal', $brokenInternal === [] ? 'pass' : (count($brokenInternal) >= 3 ? 'fail' : 'warn'), 8,
            $brokenInternal === [] ? 'pass' : 'found', [count($brokenInternal), $checkedLinks['internal']], array_slice($brokenInternal, 0, self::MAX_URLS_PER_CHECK));
        $this->add($c, 'broken_external', $brokenExternal === [] ? 'pass' : (count($brokenExternal) >= 5 ? 'fail' : 'warn'), 4,
            $brokenExternal === [] ? 'pass' : 'found', [count($brokenExternal), $checkedLinks['external']], array_slice($brokenExternal, 0, self::MAX_URLS_PER_CHECK));
        $this->add($c, 'internal_redirects', $redirectLinks === [] ? 'pass' : (count($redirectLinks) > 10 ? 'fail' : 'warn'), 3,
            $redirectLinks === [] ? 'pass' : 'found', [count($redirectLinks)], array_slice($redirectLinks, 0, self::MAX_URLS_PER_CHECK));

        // Canonicals
        $this->pageCheck($c, 'canonical_present', 4, $html, static fn(array $p): string|bool => ($p['canonical'] ?? '') === '' ? true : false, 0.5);
        $this->pageCheck($c, 'canonical_valid', 5, $html, function (array $p, string $url) use ($pages): string|bool {
            if (($p['canonical'] ?? '') === '') {
                return false;
            }
            if (count(array_unique($p['canonicals'])) > 1) {
                return 'multiple';
            }
            if (UrlTools::host($p['canonical']) !== UrlTools::host($url)) {
                return 'host: ' . UrlTools::host($p['canonical']);
            }
            $target = $pages[UrlTools::normalize($p['canonical']) ?? ''] ?? null;
            if ($target !== null && (isset($target['redirectTo']) || ($target['status'] ?? 200) >= 400)) {
                return 'target ' . $target['status'];
            }
            if (!$p['canonicalAbsolute']) {
                return 'relative';
            }

            return false;
        }, 0.2);

        // Soft 404
        $soft = $state['site']['soft404'] ?? null;
        if ($soft !== null) {
            if (in_array($soft['status'], [404, 410], true)) {
                $this->add($c, 'soft_404', 'pass', 5, 'pass', [$soft['status']]);
            } elseif ($soft['redirectedHome'] ?? false) {
                $this->add($c, 'soft_404', 'warn', 5, 'redirectHome');
            } elseif ($soft['status'] >= 200 && $soft['status'] < 300) {
                $this->add($c, 'soft_404', 'fail', 5, 'fail', [$soft['status']]);
            } else {
                $this->add($c, 'soft_404', 'info', 0, 'other', [$soft['status'] ?: $soft['error']]);
            }
        }

        $this->pageCheck($c, 'url_structure', 2, $html, static function (array $p, string $url): string|bool {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $notes = [];
            if (preg_match('/[A-Z]/', $path)) {
                $notes[] = 'A-Z';
            }
            if (str_contains($path, '_')) {
                $notes[] = '_';
            }
            if (strlen($url) > 115) {
                $notes[] = strlen($url) . ' chars';
            }
            if (UrlTools::queryParamCount($url) > 2) {
                $notes[] = '?params';
            }

            return $notes !== [] ? implode(', ', $notes) : false;
        }, 1.1, false);

        $this->pageCheck($c, 'meta_refresh', 2, $html, static fn(array $p): string|bool => ($p['metaRefresh'] ?? '') !== '' ? $p['metaRefresh'] : false, 1.1, false);

        if ($home['spaHint'] ?? false) {
            $this->add($c, 'js_rendering', 'warn', 3, 'warn', [$home['wordCount'] ?? 0]);
        } else {
            $this->add($c, 'js_rendering', 'info', 0, 'info');
        }

        $aiBlocked = $robots['aiBlocked'] ?? [];
        $this->add($c, 'ai_crawlers', 'info', 0, $aiBlocked === [] ? 'open' : 'blocked', [implode(', ', $aiBlocked)]);
        $llms = (int) ($state['site']['wellKnown']['llmsTxt'] ?? 0);
        $this->add($c, 'llms_txt', 'info', 0, $llms === 200 ? 'present' : 'missing');
    }

    // ---------------------------------------------------------------------------------------------
    // Content & on-page
    // ---------------------------------------------------------------------------------------------

    private function contentChecks(array $html, array $indexable, array $home): void
    {
        $c = 'content';

        $this->pageCheck($c, 'title_present', 9, $html, static fn(array $p): string|bool => ($p['title'] ?? '') === '' ? true : (($p['titleCount'] ?? 1) > 1 ? 'multiple' : false), 0.1);
        $this->pageCheck($c, 'title_length', 4, $html, static function (array $p): string|bool {
            $length = mb_strlen($p['title'] ?? '');
            if ($length === 0) {
                return false;
            }

            return $length < 25 || $length > 65 ? $length . ' · ' . mb_substr($p['title'], 0, 80) : false;
        }, 1.1, false);
        $this->duplicateCheck($c, 'title_duplicate', 6, $indexable, 'title');

        $this->pageCheck($c, 'meta_description_present', 6, $html, static fn(array $p): string|bool => ($p['metaDescription'] ?? '') === '' ? true : false, 0.5);
        $this->pageCheck($c, 'meta_description_length', 2, $html, static function (array $p): string|bool {
            $length = mb_strlen($p['metaDescription'] ?? '');
            if ($length === 0) {
                return false;
            }

            return $length < 70 || $length > 170 ? (string) $length : false;
        }, 1.1, false);
        $this->duplicateCheck($c, 'meta_description_duplicate', 4, $indexable, 'metaDescription');

        $this->pageCheck($c, 'h1_present', 6, $html, static fn(array $p): string|bool => ($p['h1'] ?? []) === [] || implode('', $p['h1']) === '' ? true : false, 0.3);
        $this->pageCheck($c, 'h1_multiple', 2, $html, static fn(array $p): string|bool => count($p['h1'] ?? []) > 1 ? count($p['h1']) . '× H1' : false, 1.1, false);
        $this->pageCheck($c, 'heading_structure', 2, $html, static fn(array $p): string|bool => ($p['headingSkips'] ?? 0) > 0 ? $p['headingSkips'] . '×' : false, 1.1, false);
        $this->duplicateCheck($c, 'h1_duplicate', 2, $indexable, 'h1', false);

        $this->pageCheck($c, 'content_length', 5, $indexable, static function (array $p): string|bool {
            $words = (int) ($p['wordCount'] ?? 0);

            return $words < 150 ? $words . ' words' : false;
        }, 0.5);
        $this->duplicateCheck($c, 'duplicate_content', 5, $indexable, 'contentHash');

        $totalImages = array_sum(array_map(static fn(array $p): int => $p['images']['total'] ?? 0, $html));
        $missingAlt = array_sum(array_map(static fn(array $p): int => $p['images']['missingAlt'] ?? 0, $html));
        if ($totalImages === 0) {
            $this->add($c, 'image_alt', 'info', 0, 'noImages');
        } else {
            $affected = array_filter($html, static fn(array $p): bool => ($p['images']['missingAlt'] ?? 0) > 0);
            $ratio = $missingAlt / $totalImages;
            $this->add($c, 'image_alt', $missingAlt === 0 ? 'pass' : ($ratio >= 0.2 ? 'fail' : 'warn'), 5,
                $missingAlt === 0 ? 'pass' : 'missing', [$missingAlt, $totalImages],
                $this->urlList($affected, static fn(array $p): string => $p['images']['missingAlt'] . '× · ' . implode(' ', array_map('basename', $p['images']['examplesMissingAlt'] ?? []))));
            foreach (array_keys($affected) as $url) {
                $this->pageIssues[$url] = ($this->pageIssues[$url] ?? 0) + 1;
            }
        }
        $this->pageCheck($c, 'linked_image_alt', 2, $html, static fn(array $p): string|bool => ($p['images']['linkedEmptyAlt'] ?? 0) > 0 ? $p['images']['linkedEmptyAlt'] . '×' : false, 1.1, false);
        $this->pageCheck($c, 'anchor_text', 2, $html, static function (array $p): string|bool {
            $count = ($p['emptyAnchors'] ?? 0) + ($p['genericAnchors'] ?? 0);

            return $count > 2 ? sprintf('%d empty · %d generic', $p['emptyAnchors'], $p['genericAnchors']) : false;
        }, 1.1, false);
        $this->pageCheck($c, 'lang', 4, $html, static function (array $p): string|bool {
            $lang = $p['lang'] ?? '';
            if ($lang === '') {
                return true;
            }

            return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/i', $lang) ? false : $lang;
        }, 0.3);

        $terms = $home['topTerms'] ?? [];
        if ($terms !== []) {
            $haystack = mb_strtolower(($home['title'] ?? '') . ' ' . implode(' ', $home['h1'] ?? []));
            $inTitle = array_values(array_filter(array_slice($terms, 0, 5), static fn(string $t): bool => str_contains($haystack, $t)));
            $this->add($c, 'keyword_focus', 'info', 0, $inTitle !== [] ? 'aligned' : 'notAligned', [implode(', ', array_slice($terms, 0, 6)), implode(', ', $inTitle)]);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Technology & performance
    // ---------------------------------------------------------------------------------------------

    private function performanceChecks(array $state, array $html, array $home): void
    {
        $c = 'performance';
        $psi = $state['psi'] ?? null;
        $hasPsi = is_array($psi) && ($psi['error'] ?? '') === '' && ($psi['score'] ?? null) !== null;

        if ($hasPsi) {
            $score = (int) $psi['score'];
            $this->add($c, 'psi_score', $score >= 90 ? 'pass' : ($score >= 50 ? 'warn' : 'fail'), 8, 'score', [$score]);

            $field = $psi['field'];
            $lab = $psi['lab'];
            $source = $field['source'] !== '' ? $field['source'] : 'lab';
            $this->vitalsCheck($c, 'cwv_lcp', $field['lcp'] ?? null, $lab['lcp'] ?? null, 2500, 4000, static fn(float $v): string => number_format($v / 1000, 1, ',', '.') . ' s', $source);
            $this->vitalsCheck($c, 'cwv_inp', $field['inp'] ?? null, null, 200, 500, static fn(float $v): string => (int) $v . ' ms', $source);
            $this->vitalsCheck($c, 'cwv_cls', $field['cls'] ?? null, $lab['cls'] ?? null, 0.1, 0.25, static fn(float $v): string => number_format($v, 2, ',', '.'), $source);
            if (isset($lab['tbt'])) {
                $tbt = (float) $lab['tbt'];
                $this->add($c, 'tbt', $tbt <= 200 ? 'pass' : ($tbt <= 600 ? 'warn' : 'fail'), 4, 'value', [(int) $tbt . ' ms']);
            }
        } else {
            $this->add($c, 'psi_score', 'info', 0, 'unavailable');
        }

        $ttfbs = array_values(array_filter(array_map(static fn(array $p): int => (int) ($p['ttfbMs'] ?? 0), $html)));
        sort($ttfbs);
        $median = $ttfbs !== [] ? $ttfbs[intdiv(count($ttfbs), 2)] : 0;
        $this->add($c, 'ttfb', $median <= 600 ? 'pass' : ($median <= 1500 ? 'warn' : 'fail'), 6, 'value', [$median, $home['ttfbMs'] ?? 0],
            $this->urlList(array_filter($html, static fn(array $p): bool => ($p['ttfbMs'] ?? 0) > 1500), static fn(array $p): string => $p['ttfbMs'] . ' ms'));

        $this->pageCheck($c, 'html_size', 3, $html, static function (array $p): string|bool {
            $kb = (int) round(($p['bytes'] ?? 0) / 1024);

            return $kb > 400 ? $kb . ' KB' : false;
        }, 0.3);

        $encoding = $home['encoding'] ?? '';
        $compressed = (bool) preg_match('/\b(gzip|br|zstd|deflate)\b/', $encoding);
        $this->add($c, 'compression', $compressed ? 'pass' : 'fail', 5, $compressed ? 'pass' : 'fail', [$encoding]);

        $version = $home['httpVersion'] ?? '';
        $modern = in_array($version, ['2', '3'], true);
        $this->add($c, 'http2', $modern ? 'pass' : 'warn', 2, $modern ? 'pass' : 'fail', [$version !== '' ? $version : '1.1']);

        $assets = $state['assetResults'] ?? [];
        $cacheable = array_filter($assets, static fn(array $a): bool => $a['status'] >= 200 && $a['status'] < 300);
        if ($cacheable === []) {
            $this->add($c, 'asset_caching', 'info', 0, 'notChecked');
        } else {
            $poor = array_filter($cacheable, static function (array $a): bool {
                if (str_contains($a['cacheControl'], 'immutable')) {
                    return false;
                }
                if (preg_match('/max-age=(\d+)/', $a['cacheControl'], $m)) {
                    return (int) $m[1] < 7 * 86400;
                }
                $expires = $a['expires'] !== '' ? strtotime($a['expires']) : false;

                return $expires === false || $expires < time() + 7 * 86400;
            });
            $list = [];
            foreach ($poor as $url => $a) {
                $list[] = ['url' => $url, 'note' => $a['cacheControl'] !== '' ? $a['cacheControl'] : '–'];
            }
            $this->add($c, 'asset_caching', $poor === [] ? 'pass' : (count($poor) / count($cacheable) > 0.5 ? 'fail' : 'warn'), 3,
                $poor === [] ? 'pass' : 'short', [count($poor), count($cacheable)], $list);
        }

        $blocking = (int) ($home['scripts']['blockingHead'] ?? 0);
        $this->add($c, 'render_blocking', $blocking === 0 ? 'pass' : ($blocking <= 3 ? 'warn' : 'fail'), 4, $blocking === 0 ? 'pass' : 'found', [$blocking]);

        $requests = (int) ($home['scripts']['total'] ?? 0) + (int) ($home['stylesheets'] ?? 0);
        $this->add($c, 'requests', $requests <= 30 ? 'pass' : ($requests <= 50 ? 'warn' : 'fail'), 2, 'value', [$requests, $home['scripts']['total'] ?? 0, $home['stylesheets'] ?? 0]);

        $this->pageCheck($c, 'image_dimensions', 3, $html, static function (array $p): string|bool {
            $count = $p['images']['noDimensions'] ?? 0;

            return $count > 0 ? $count . '/' . $p['images']['total'] : false;
        }, 0.5);
        $this->pageCheck($c, 'lazy_loading', 2, $html, static fn(array $p): string|bool => ($p['images']['total'] ?? 0) > 5 && ($p['images']['lazy'] ?? 0) === 0 ? $p['images']['total'] . ' img' : false, 1.1, false);

        $raster = array_sum(array_map(static fn(array $p): int => $p['images']['raster'] ?? 0, $html));
        $modernImages = array_sum(array_map(static fn(array $p): int => $p['images']['modern'] ?? 0, $html));
        if ($raster < 3) {
            $this->add($c, 'modern_images', 'info', 0, 'noImages');
        } else {
            $share = (int) round($modernImages / $raster * 100);
            $this->add($c, 'modern_images', $share >= 50 ? 'pass' : 'warn', 2, 'share', [$share, $modernImages, $raster]);
        }

        $this->pageCheck($c, 'dom_size', 2, $html, static fn(array $p): string|bool => ($p['domNodes'] ?? 0) > 1500 ? $p['domNodes'] . ' nodes' : false, 0.5);

        $this->pageCheck($c, 'viewport', 6, $html, static function (array $p): string|bool {
            $viewport = strtolower($p['viewport'] ?? '');
            if ($viewport === '') {
                return true;
            }
            if (!str_contains($viewport, 'width=device-width')) {
                return $viewport;
            }

            return str_contains($viewport, 'user-scalable=no') || preg_match('/maximum-scale=1(\.0)?\b/', $viewport) ? 'zoom disabled' : false;
        }, 0.3);

        $this->pageCheck($c, 'doctype_charset', 2, $html, static function (array $p): string|bool {
            $missing = [];
            if (!($p['doctype'] ?? true)) {
                $missing[] = 'doctype';
            }
            if (!($p['charset'] ?? true)) {
                $missing[] = 'charset';
            }

            return $missing !== [] ? implode(', ', $missing) : false;
        }, 0.5);
    }

    // ---------------------------------------------------------------------------------------------
    // Structured data, social, international
    // ---------------------------------------------------------------------------------------------

    private function structureChecks(array $state, array $html, array $indexable, array $home): void
    {
        $c = 'structure';

        $types = [];
        foreach ($html as $page) {
            array_push($types, ...($page['schemaTypes'] ?? []));
        }
        $types = array_values(array_unique($types));
        $this->add($c, 'structured_data', $types !== [] ? 'pass' : 'warn', 5, $types !== [] ? 'pass' : 'missing', [implode(', ', array_slice($types, 0, 12))]);

        $this->pageCheck($c, 'structured_data_valid', 5, $html, static function (array $p): string|bool {
            $notes = [];
            if (($p['schemaErrors'] ?? 0) > 0) {
                $notes[] = 'JSON error';
            }
            if (($p['schemaMissing'] ?? []) !== []) {
                $notes[] = implode(', ', array_slice($p['schemaMissing'], 0, 4));
            }

            return $notes !== [] ? implode(' · ', $notes) : false;
        }, 0.3);

        $hasOrganization = array_intersect($types, ['Organization', 'LocalBusiness', 'Corporation', 'Person', 'ProfessionalService']) !== []
            || array_filter($types, static fn(string $t): bool => str_ends_with($t, 'Business')) !== [];
        $this->add($c, 'organization_schema', $hasOrganization ? 'pass' : 'warn', 2, $hasOrganization ? 'pass' : 'missing');

        $this->pageCheck($c, 'open_graph', 3, $indexable, static function (array $p): string|bool {
            $missing = [];
            foreach (['title', 'description', 'image'] as $key) {
                if (($p['og'][$key] ?? '') === '') {
                    $missing[] = 'og:' . $key;
                }
            }
            if (($p['og']['image'] ?? '') !== '' && !($p['og']['imageAbsolute'] ?? true)) {
                $missing[] = 'og:image relative';
            }

            return $missing !== [] ? implode(', ', $missing) : false;
        }, 0.5);
        $this->pageCheck($c, 'twitter_card', 1, $indexable, static fn(array $p): string|bool => ($p['twitterCard'] ?? '') === '' && ($p['og']['image'] ?? '') === '' ? true : false, 1.1, false);

        $faviconIco = (int) ($state['site']['wellKnown']['faviconIco'] ?? 0);
        $hasFavicon = ($home['favicon'] ?? false) || $faviconIco === 200;
        $this->add($c, 'favicon', $hasFavicon ? 'pass' : 'warn', 2, $hasFavicon ? 'pass' : 'missing');

        $this->hreflangCheck($state, $html);
    }

    private function hreflangCheck(array $state, array $html): void
    {
        $withHreflang = array_filter($html, static fn(array $p): bool => ($p['hreflang'] ?? []) !== []);
        if ($withHreflang === []) {
            $langs = array_unique(array_filter(array_map(static fn(array $p): string => strtolower(substr($p['lang'] ?? '', 0, 2)), $html)));
            $multilingual = count($langs) > 1;
            $this->add('structure', 'hreflang', $multilingual ? 'warn' : 'info', $multilingual ? 4 : 0, $multilingual ? 'missingMultilingual' : 'none', [implode(', ', $langs)]);

            return;
        }

        $pages = $state['pages'];
        $this->pageCheck('structure', 'hreflang', 5, $withHreflang, static function (array $p, string $url) use ($pages): string|bool {
            $notes = [];
            $hrefs = [];
            $hasXDefault = false;
            foreach ($p['hreflang'] as $alt) {
                $code = strtolower($alt['lang']);
                $hasXDefault = $hasXDefault || $code === 'x-default';
                if ($code !== 'x-default' && !preg_match('/^[a-z]{2,3}(-([a-z]{4}))?(-([a-z]{2}|\d{3}))?$/', $code)) {
                    $notes[] = 'code "' . $alt['lang'] . '"';
                } elseif (preg_match('/-uk$/', $code)) {
                    $notes[] = '"' . $alt['lang'] . '" → en-GB';
                }
                if (!$alt['absolute']) {
                    $notes[] = 'relative URL';
                }
                $hrefs[] = UrlTools::normalize($alt['href']) ?? $alt['href'];
            }
            if (!in_array($url, $hrefs, true) && !in_array(UrlTools::normalize($p['canonical'] ?? '') ?? '', $hrefs, true)) {
                $notes[] = 'no self reference';
            }
            if (!$hasXDefault) {
                $notes[] = 'no x-default';
            }
            // Return links: alternates that were crawled must link back.
            foreach ($hrefs as $href) {
                $target = $pages[$href] ?? null;
                if ($target === null || $href === $url) {
                    continue;
                }
                if (isset($target['redirectTo']) || ($target['status'] ?? 200) >= 400) {
                    $notes[] = 'target ' . ($target['status'] ?? '?');
                    continue;
                }
                $back = array_map(static fn(array $a): string => UrlTools::normalize($a['href']) ?? $a['href'], $target['hreflang'] ?? []);
                if (!in_array($url, $back, true)) {
                    $notes[] = 'no return link from ' . UrlTools::pathWithQuery($href);
                }
            }

            return $notes !== [] ? implode(', ', array_unique(array_slice($notes, 0, 4))) : false;
        }, 0.3);
    }

    // ---------------------------------------------------------------------------------------------
    // Security
    // ---------------------------------------------------------------------------------------------

    private function securityChecks(array $state, array $html, array $home): void
    {
        $c = 'security';
        $isHttps = str_starts_with($state['finalUrl'], 'https://');

        $expires = $state['certExpires'] ?? null;
        if (!$isHttps) {
            $this->add($c, 'tls_certificate', 'fail', 6, 'noHttps');
        } elseif ($expires === null) {
            $this->add($c, 'tls_certificate', 'pass', 6, 'valid');
        } else {
            $days = (int) floor(($expires - time()) / 86400);
            $this->add($c, 'tls_certificate', $days >= 14 ? 'pass' : ($days >= 5 ? 'warn' : 'fail'), 6, 'expires', [$days, date('d.m.Y', $expires)]);
        }

        $headers = $home['headers'] ?? [];
        if ($isHttps) {
            $hsts = strtolower($headers['hsts'] ?? '');
            $maxAge = preg_match('/max-age=(\d+)/', $hsts, $m) ? (int) $m[1] : 0;
            $this->add($c, 'hsts', $maxAge >= 15552000 ? 'pass' : ($maxAge > 0 ? 'warn' : 'warn'), 4, $maxAge >= 15552000 ? 'pass' : ($maxAge > 0 ? 'short' : 'missing'), [(int) round($maxAge / 86400)]);
        }

        $this->pageCheck($c, 'mixed_content', 6, $html, static fn(array $p): string|bool => ($p['mixedContent'] ?? 0) > 0 ? $p['mixedContent'] . '× · ' . ($p['mixedExamples'][0] ?? '') : false, 0.0001);

        $csp = strtolower($headers['csp'] ?? '');
        $present = [
            'X-Content-Type-Options' => str_contains(strtolower($headers['xcto'] ?? ''), 'nosniff'),
            'Clickjacking (X-Frame-Options / frame-ancestors)' => ($headers['xfo'] ?? '') !== '' || str_contains($csp, 'frame-ancestors'),
            'Content-Security-Policy' => $csp !== '',
            'Referrer-Policy' => ($headers['referrer'] ?? '') !== '',
            'Permissions-Policy' => ($headers['permissions'] ?? '') !== '',
        ];
        $missing = array_keys(array_filter($present, static fn(bool $v): bool => !$v));
        $count = count($present) - count($missing);
        $this->add($c, 'security_headers', $count >= 4 ? 'pass' : ($count >= 2 ? 'warn' : 'fail'), 4, $missing === [] ? 'pass' : 'missing', [$count, count($present), implode(', ', $missing)]);

        $disclosure = [];
        if (($headers['poweredBy'] ?? '') !== '') {
            $disclosure[] = 'X-Powered-By: ' . $headers['poweredBy'];
        }
        if (preg_match('#/\d#', $headers['server'] ?? '')) {
            $disclosure[] = 'Server: ' . $headers['server'];
        }
        $this->add($c, 'server_disclosure', $disclosure === [] ? 'pass' : 'warn', 1, $disclosure === [] ? 'pass' : 'found', [implode(' · ', $disclosure)]);
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /**
     * @param callable(array, string): (string|bool) $affected returns false or a note
     */
    private function pageCheck(string $category, string $id, int $weight, array $pages, callable $affected, float $failRatio = 0.3, bool $canFail = true, string $affectedMessage = 'msg.affected'): void
    {
        if ($pages === []) {
            $this->add($category, $id, 'info', 0, 'msg.noPages');

            return;
        }

        $list = [];
        foreach ($pages as $url => $page) {
            $note = $affected($page, (string) $url);
            if ($note === false) {
                continue;
            }
            $list[] = ['url' => (string) $url, 'note' => is_string($note) ? $note : ''];
            $this->pageIssues[$url] = ($this->pageIssues[$url] ?? 0) + 1;
        }

        if ($list === []) {
            $this->add($category, $id, 'pass', $weight, 'msg.allPages', [count($pages)]);

            return;
        }

        $ratio = count($list) / count($pages);
        $status = $canFail && $ratio >= $failRatio ? 'fail' : 'warn';
        $this->add($category, $id, $status, $weight, $affectedMessage, [count($list), count($pages)], $list);
    }

    private function duplicateCheck(string $category, string $id, int $weight, array $pages, string $field, bool $canFail = true): void
    {
        $groups = [];
        foreach ($pages as $url => $page) {
            $value = $page[$field] ?? '';
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }
            $key = mb_strtolower(trim((string) $value));
            if ($key !== '') {
                $groups[$key][] = (string) $url;
            }
        }

        $list = [];
        $duplicateGroups = 0;
        foreach ($groups as $value => $urls) {
            if (count($urls) < 2) {
                continue;
            }
            $duplicateGroups++;
            $note = $field === 'contentHash' ? '#' . $duplicateGroups : mb_substr((string) $value, 0, 70);
            foreach ($urls as $url) {
                $list[] = ['url' => $url, 'note' => $note];
                $this->pageIssues[$url] = ($this->pageIssues[$url] ?? 0) + 1;
            }
        }

        if ($pages === []) {
            $this->add($category, $id, 'info', 0, 'msg.noPages');
        } elseif ($list === []) {
            $this->add($category, $id, 'pass', $weight, 'msg.noDuplicates', [count($pages)]);
        } else {
            $ratio = count($list) / count($pages);
            $this->add($category, $id, $canFail && $ratio >= 0.25 ? 'fail' : 'warn', $weight, 'msg.duplicates', [count($list), $duplicateGroups], $list);
        }
    }

    private function vitalsCheck(string $category, string $id, ?float $field, ?float $lab, float $good, float $poor, callable $format, string $source): void
    {
        $value = $field ?? $lab;
        if ($value === null) {
            $this->add($category, $id, 'info', 0, 'noData');

            return;
        }
        $status = $value <= $good ? 'pass' : ($value <= $poor ? 'warn' : 'fail');
        $this->add($category, $id, $status, 6, $field !== null ? 'field' : 'lab', [$format($value), $field !== null ? $source : 'lab']);
    }

    /**
     * @return array{0: list<array>, 1: list<array>, 2: list<array>, 3: array{internal: int, external: int}}
     */
    private function linkStatus(array $state): array
    {
        $brokenInternal = [];
        $brokenExternal = [];
        $redirects = [];
        $checked = ['internal' => 0, 'external' => 0];

        foreach ($state['links'] as $target => $info) {
            $page = $state['pages'][$target] ?? null;
            $result = $state['linkResults'][$target] ?? null;
            if ($page === null && $result === null) {
                continue;
            }
            $checked[$info['internal'] ? 'internal' : 'external']++;

            $status = $page !== null ? (int) ($page['status'] ?? 0) : (int) $result['status'];
            $error = $page !== null ? (string) ($page['error'] ?? '') : (string) $result['error'];
            $isRedirect = $page !== null ? isset($page['redirectTo']) : ($status >= 300 && $status < 400);
            $sources = implode(', ', array_map([UrlTools::class, 'pathWithQuery'], $info['sources']));

            // Bot protection and rate limits are not evidence of a broken link.
            $broken = in_array($status, [404, 410], true) || ($status >= 500 && $status < 600)
                || in_array($error, ['dns', 'connect', 'ssl'], true)
                || ($info['internal'] && $status >= 400 && !in_array($status, [401, 403, 429], true));
            if (str_starts_with($error, 'blocked:')) {
                $broken = false;
            }

            if ($broken) {
                $entry = ['url' => $target, 'note' => ($status ?: $error) . ' · ' . $sources];
                if ($info['internal']) {
                    $brokenInternal[] = $entry;
                } else {
                    $brokenExternal[] = $entry;
                }
            } elseif ($info['internal'] && $isRedirect) {
                $redirectTarget = $page['redirectTo'] ?? $result['location'] ?? '';
                // Trailing-slash or http->https hops are still worth fixing but are reported as such.
                $redirects[] = ['url' => $target, 'note' => $status . ' → ' . $redirectTarget];
            }
        }

        return [$brokenInternal, $brokenExternal, $redirects, $checked];
    }

    private function isNoindex(array $page): bool
    {
        return str_contains($page['metaRobots'] ?? '', 'noindex')
            || str_contains($page['metaRobots'] ?? '', 'none')
            || str_contains($page['xRobots'] ?? '', 'noindex');
    }

    private function canonicalPointsElsewhere(array $page, string $url): bool
    {
        $canonical = UrlTools::normalize($page['canonical'] ?? '');

        return $canonical !== null && rtrim($canonical, '/') !== rtrim($url, '/');
    }

    /**
     * @param callable(array): string $note
     */
    private function urlList(array $pages, callable $note): array
    {
        $list = [];
        foreach ($pages as $url => $page) {
            $list[] = ['url' => (string) $url, 'note' => $note($page)];
        }

        return $list;
    }

    private function add(string $category, string $id, string $status, int $weight, string $message, array $args = [], array $urls = []): void
    {
        $total = count($urls);
        $this->checks[$category][] = [
            'id' => $id,
            'status' => $status,
            'weight' => $status === 'info' ? 0 : $weight,
            'messageKey' => str_starts_with($message, 'msg.') ? $message : 'check.' . $id . '.' . $message,
            'args' => array_map(static fn($a): string => (string) $a, $args),
            'urls' => array_map(static fn(array $u): array => [
                // Only http(s) URLs become links; anything else is rendered as plain text.
                'url' => preg_match('#^https?://#i', $u['url']) ? $u['url'] : '',
                'path' => UrlTools::displayPath($u['url']),
                'note' => mb_substr((string) ($u['note'] ?? ''), 0, 160),
            ], array_slice($urls, 0, self::MAX_URLS_PER_CHECK)),
            'moreUrls' => max(0, $total - self::MAX_URLS_PER_CHECK),
        ];
    }

    private function assemble(array $state, array $pages, array $html, CheckerSettings $settings): array
    {
        $statusOrder = ['fail' => 0, 'warn' => 1, 'pass' => 2, 'info' => 3];
        $categories = [];
        $weightedSum = 0;
        $weightTotal = 0;
        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
        $priorities = [];

        foreach ($this->checks as $category => $checks) {
            $points = 0.0;
            $weights = 0;
            $categoryCounts = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
            foreach ($checks as $check) {
                $counts[$check['status']]++;
                $categoryCounts[$check['status']]++;
                if ($check['status'] === 'info') {
                    continue;
                }
                $weights += $check['weight'];
                $points += $check['weight'] * ['pass' => 1.0, 'warn' => 0.5, 'fail' => 0.0][$check['status']];
                if ($check['status'] !== 'pass') {
                    $priorities[] = $check + ['category' => $category];
                }
            }
            usort($checks, static fn(array $a, array $b): int => [$statusOrder[$a['status']], -$a['weight']] <=> [$statusOrder[$b['status']], -$b['weight']]);
            $score = $weights > 0 ? (int) round($points / $weights * 100) : 100;
            $categories[] = [
                'id' => $category,
                'score' => $score,
                'rating' => $this->rating($score),
                'counts' => $categoryCounts,
                'checks' => $checks,
                'ringOffset' => $this->ringOffset($score, 26),
            ];
            if ($weights > 0) {
                $weightedSum += $score * self::CATEGORIES[$category];
                $weightTotal += self::CATEGORIES[$category];
            }
        }

        usort($priorities, static fn(array $a, array $b): int => [$statusOrder[$a['status']], -$a['weight']] <=> [$statusOrder[$b['status']], -$b['weight']]);
        $score = $weightTotal > 0 ? (int) round($weightedSum / $weightTotal) : 0;

        $pageRows = [];
        foreach ($pages as $url => $page) {
            $pageRows[] = [
                'url' => $url,
                'path' => UrlTools::displayPath($url),
                'status' => $page['status'] ?? 0,
                'title' => mb_substr((string) ($page['title'] ?? ''), 0, 90),
                'words' => $page['wordCount'] ?? null,
                'ttfb' => $page['ttfbMs'] ?? null,
                'redirectTo' => $page['redirectTo'] ?? '',
                'issues' => $this->pageIssues[$url] ?? 0,
                'state' => ($page['status'] ?? 0) >= 400 || ($page['status'] ?? 0) === 0 ? 'fail' : (isset($page['redirectTo']) ? 'warn' : 'pass'),
            ];
        }

        $ttfbs = array_filter(array_map(static fn(array $p): int => (int) ($p['ttfbMs'] ?? 0), $html));
        $psi = $state['psi'] ?? null;

        return [
            'version' => 1,
            'url' => $state['startUrl'],
            'finalUrl' => $state['finalUrl'],
            'host' => $state['host'],
            'score' => $score,
            'rating' => $this->rating($score),
            'ringOffset' => $this->ringOffset($score, 54),
            'counts' => $counts,
            'categories' => $categories,
            'priorities' => array_slice($priorities, 0, 8),
            'pages' => $pageRows,
            'stats' => [
                'pages' => count($pages),
                'htmlPages' => count($html),
                'maxPages' => $settings->maxPages,
                'avgTtfb' => $ttfbs !== [] ? (int) round(array_sum($ttfbs) / count($ttfbs)) : 0,
                'sitemapUrls' => array_sum(array_column($state['sitemapFiles'] ?? [], 'urlCount')),
                'linksChecked' => count($state['linkResults'] ?? []) + count(array_intersect_key($state['links'] ?? [], $pages)),
                'psiScore' => is_array($psi) && ($psi['error'] ?? '') === '' ? ($psi['score'] ?? null) : null,
                'duration' => max(1, time() - (int) ($state['startedAt'] ?? time())),
            ],
            'createdAt' => time(),
        ];
    }

    private function rating(int $score): string
    {
        return $score >= 90 ? 'good' : ($score >= 50 ? 'medium' : 'poor');
    }

    private function ringOffset(int $score, int $radius): string
    {
        $circumference = 2 * M_PI * $radius;

        return number_format($circumference * (1 - $score / 100), 2, '.', '');
    }
}
