<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Checker;

use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Google PageSpeed Insights (Lighthouse lab data + Chrome UX Report field data).
 * Works without an API key at a low quota; configure pageSpeedApiKey for production use.
 */
final class PageSpeedClient
{
    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly CheckerSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>|null null when the API is disabled or failed
     */
    public function analyze(string $url, string $locale): ?array
    {
        if (!$this->settings->pageSpeedEnabled) {
            return null;
        }

        $query = ['url' => $url, 'strategy' => 'mobile', 'category' => 'performance', 'locale' => $locale];
        if ($this->settings->pageSpeedApiKey !== '') {
            $query['key'] = $this->settings->pageSpeedApiKey;
        }

        try {
            $response = $this->requestFactory->request(self::ENDPOINT . '?' . http_build_query($query), 'GET', [
                'timeout' => 75,
                'connect_timeout' => 10,
                'http_errors' => false,
            ]);
            if ($response->getStatusCode() !== 200) {
                return ['error' => 'http' . $response->getStatusCode()];
            }
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['error' => 'request'];
        }

        $audits = $data['lighthouseResult']['audits'] ?? [];
        $score = $data['lighthouseResult']['categories']['performance']['score'] ?? null;
        $field = $data['loadingExperience']['metrics'] ?? [];
        $fieldSource = 'url';
        if (($data['loadingExperience']['origin_fallback'] ?? false) === true) {
            $fieldSource = 'origin';
        }
        if ($field === [] && isset($data['originLoadingExperience']['metrics'])) {
            $field = $data['originLoadingExperience']['metrics'];
            $fieldSource = 'origin';
        }

        $fieldValue = static fn(string $key): ?float => isset($field[$key]['percentile']) ? (float) $field[$key]['percentile'] : null;
        $labValue = static fn(string $key): ?float => isset($audits[$key]['numericValue']) ? (float) $audits[$key]['numericValue'] : null;

        $cls = $fieldValue('CUMULATIVE_LAYOUT_SHIFT_SCORE');

        return [
            'error' => '',
            'score' => $score !== null ? (int) round($score * 100) : null,
            'field' => [
                'source' => $field !== [] ? $fieldSource : '',
                'lcp' => $fieldValue('LARGEST_CONTENTFUL_PAINT_MS'),
                'inp' => $fieldValue('INTERACTION_TO_NEXT_PAINT'),
                'cls' => $cls !== null ? $cls / 100 : null,
            ],
            'lab' => [
                'fcp' => $labValue('first-contentful-paint'),
                'lcp' => $labValue('largest-contentful-paint'),
                'tbt' => $labValue('total-blocking-time'),
                'cls' => $labValue('cumulative-layout-shift'),
                'si' => $labValue('speed-index'),
            ],
        ];
    }
}
