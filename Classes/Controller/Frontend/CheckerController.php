<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Controller\Frontend;

use Aistea\AisteaSeo\Checker\AuditRepository;
use Aistea\AisteaSeo\Checker\CheckerSettings;
use Aistea\AisteaSeo\Checker\ReportBuilder;
use Aistea\AisteaSeo\Checker\VisitorGuard;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Public SEO checker page: form, live progress and the finished report (?audit=<token>).
 * Uncached; the JSON work happens in Middleware\CheckerEndpoint.
 */
final class CheckerController extends ActionController
{
    private const LL = 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang_checker.xlf:';

    public function __construct(
        private readonly AuditRepository $repository,
        private readonly VisitorGuard $visitorGuard,
        private readonly CheckerSettings $checkerSettings,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $pageUri = $this->request->getUri()->withQuery('')->withFragment('');
        $token = (string) ($this->request->getQueryParams()['audit'] ?? '');
        $audit = $token !== '' ? $this->repository->findByToken($token) : null;

        $mode = 'form';
        $report = null;
        if ($token !== '' && $audit === null) {
            $mode = 'notFound';
        } elseif ($audit !== null) {
            $mode = match ($audit['status']) {
                AuditRepository::STATUS_DONE => 'report',
                AuditRepository::STATUS_FAILED => 'failed',
                default => 'progress',
            };
            if ($mode === 'report') {
                $report = json_decode((string) $audit['result'], true) ?: null;
                $mode = $report !== null ? 'report' : 'failed';
            }
        }

        $language = $this->request->getAttribute('language');
        $basePath = $language instanceof SiteLanguage ? rtrim($language->getBase()->getPath(), '/') : '';

        $this->view->assignMultiple([
            'mode' => $mode,
            'll' => self::LL,
            'audit' => $audit,
            'report' => $report,
            'formToken' => $this->visitorGuard->issueFormToken(),
            'remaining' => $this->visitorGuard->remaining($this->request),
            'dailyLimit' => $this->checkerSettings->dailyLimit,
            'maxPages' => $this->checkerSettings->maxPages,
            'pageSpeedEnabled' => $this->checkerSettings->pageSpeedEnabled,
            'endpoint' => $basePath . '/_seo-checker/',
            'pageUrl' => (string) $pageUri,
            'shareUrl' => $audit !== null ? $pageUri . '?audit=' . $audit['token'] : '',
            'categoryIds' => array_keys(ReportBuilder::CATEGORIES),
            'jsLabels' => json_encode($this->jsLabels(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        ]);

        return $this->htmlResponse();
    }

    /**
     * @return array<string, string>
     */
    private function jsLabels(): array
    {
        $labels = [];
        foreach (['network', 'invalidUrl', 'copied', 'copyFailed', 'starting', 'busy', 'finishing', 'generic'] as $key) {
            $labels[$key] = (string) LocalizationUtility::translate(self::LL . 'js.' . $key);
        }

        return $labels;
    }
}
