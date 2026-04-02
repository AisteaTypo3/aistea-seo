<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Controller\Backend;

use Aistea\AisteaSeo\Domain\Model\SeoReport;
use Aistea\AisteaSeo\Domain\Repository\SeoPageRepository;
use Aistea\AisteaSeo\Domain\Repository\SeoReportRepository;
use Aistea\AisteaSeo\Service\SeoAnalyzerService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Property\TypeConverter\PersistentObjectConverter;

class ReportController extends ActionController
{
    public function __construct(
        private readonly SeoReportRepository $reportRepository,
        private readonly SeoPageRepository $pageRepository,
        private readonly SeoAnalyzerService $analyzerService,
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PersistenceManager $persistenceManager,
        private readonly IconFactory $iconFactory,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $this->registerBackendAssets();
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('SEO Reports');

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $newButton = $buttonBar->makeLinkButton()
            ->setHref($this->uriBuilder->uriFor('new'))
            ->setTitle('New Report')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-add', IconSize::SMALL));
        $buttonBar->addButton($newButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $shortcutButton = $buttonBar->makeShortcutButton()
            ->setRouteIdentifier('aistea_seo')
            ->setDisplayName('SEO Reports');
        $buttonBar->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT);

        $moduleTemplate->assign('reports', $this->reportRepository->findAll());
        return $moduleTemplate->renderResponse('Backend/Report/Index');
    }

    public function newAction(): ResponseInterface
    {
        $this->registerBackendAssets();
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('New SEO Report');

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $backButton = $buttonBar->makeLinkButton()
            ->setHref($this->uriBuilder->uriFor('index'))
            ->setTitle('All Reports')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-arrow-left', IconSize::SMALL));
        $buttonBar->addButton($backButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $moduleTemplate->assign('report', new SeoReport());
        return $moduleTemplate->renderResponse('Backend/Report/New');
    }

    protected function initializeCreateAction(): void
    {
        $propertyMappingConfiguration = $this->arguments->getArgument('report')->getPropertyMappingConfiguration();
        $propertyMappingConfiguration->allowAllProperties();
        $propertyMappingConfiguration->setTypeConverterOption(
            PersistentObjectConverter::class,
            PersistentObjectConverter::CONFIGURATION_CREATION_ALLOWED,
            true
        );
    }

    public function createAction(SeoReport $report): ResponseInterface
    {
        try {
            $report->setBaseUrl($this->analyzerService->assertAllowedBaseUrl($report->getBaseUrl()));
        } catch (\InvalidArgumentException $exception) {
            $this->addFlashMessage(
                htmlspecialchars($exception->getMessage()),
                'Invalid website URL',
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('new');
        }

        $this->reportRepository->add($report);
        $this->persistenceManager->persistAll();
        $this->addFlashMessage(
            'Report "' . htmlspecialchars($report->getTitle()) . '" has been created.',
            'Report created'
        );
        return $this->redirect('index');
    }

    public function showAction(SeoReport $report): ResponseInterface
    {
        $this->registerBackendAssets();
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle($report->getTitle());

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $backButton = $buttonBar->makeLinkButton()
            ->setHref($this->uriBuilder->uriFor('index'))
            ->setTitle('All Reports')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-arrow-left', IconSize::SMALL));
        $buttonBar->addButton($backButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $shortcutButton = $buttonBar->makeShortcutButton()
            ->setRouteIdentifier('aistea_seo')
            ->setDisplayName($report->getTitle());
        $buttonBar->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT);

        $pages = $this->pageRepository->findByReportUid($report->getUid());

        $errorCount = 0;
        $warningCount = 0;
        $noticeCount = 0;

        foreach ($pages as $page) {
            $errorCount += $page->getErrorCount();
            $warningCount += $page->getWarningCount();
            $noticeCount += $page->getNoticeCount();
        }

        $issuesSummary = $this->buildIssuesSummary($pages);
        $pageTypeSummary = $this->buildPageTypeSummary($pages);

        $moduleTemplate->assignMultiple([
            'report' => $report,
            'pages' => $pages,
            'errorCount' => $errorCount,
            'warningCount' => $warningCount,
            'noticeCount' => $noticeCount,
            'issuesSummary' => $issuesSummary,
            'pageTypeSummary' => $pageTypeSummary,
            'exportCsvUrl' => $this->uriBuilder->uriFor('exportCsv', ['report' => $report]),
            'exportIssuesCsvUrl' => $this->uriBuilder->uriFor('exportIssuesCsv', ['report' => $report]),
            'exportJsonUrl' => $this->uriBuilder->uriFor('exportJson', ['report' => $report]),
        ]);

        return $moduleTemplate->renderResponse('Backend/Report/Show');
    }

    public function exportCsvAction(SeoReport $report): ResponseInterface
    {
        $pages = $this->pageRepository->findByReportUid($report->getUid());
        $rows = [[
            'URL',
            'Page Type',
            'HTTP Status',
            'Content Type',
            'Redirect Target',
            'Redirect Final URL',
            'Redirect Hops',
            'Score',
            'Errors',
            'Warnings',
            'Notices',
            'Title',
            'Title Length',
            'Meta Description',
            'Meta Description Length',
            'H1 Count',
            'H1 Text',
            'H2 Count',
            'Canonical URL',
            'Robots Noindex',
            'Robots Nofollow',
            'Images Total',
            'Images Missing Alt',
            'Internal Links',
            'External Links',
            'Word Count',
            'Load Time (ms)',
            'Issue Types',
            'Issue Messages',
        ]];

        foreach ($pages as $page) {
            $rows[] = [
                $page->getUrl(),
                $page->getPageTypeLabel(),
                (string) $page->getStatusCode(),
                $page->getContentType(),
                $page->getRedirectTarget(),
                $page->getRedirectFinalUrl(),
                (string) $page->getRedirectHops(),
                (string) $page->getPageScore(),
                (string) $page->getErrorCount(),
                (string) $page->getWarningCount(),
                (string) $page->getNoticeCount(),
                $page->getPageTitle(),
                (string) $page->getTitleLength(),
                $page->getMetaDescription(),
                (string) $page->getMetaDescriptionLength(),
                (string) $page->getH1Count(),
                $page->getH1Text(),
                (string) $page->getH2Count(),
                $page->getCanonicalUrl(),
                $page->getRobotsNoindex() ? 'yes' : 'no',
                $page->getRobotsNofollow() ? 'yes' : 'no',
                (string) $page->getImagesTotal(),
                (string) $page->getImagesMissingAlt(),
                (string) $page->getLinksInternal(),
                (string) $page->getLinksExternal(),
                (string) $page->getWordCount(),
                (string) $page->getLoadTime(),
                $this->buildIssueTypesString($page->getIssuesArray()),
                $this->buildIssueMessagesString($page->getIssuesArray()),
            ];
        }

        $handle = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        $filename = sprintf(
            'seo-report-%d-%s.csv',
            $report->getUid(),
            preg_replace('/[^a-z0-9]+/i', '-', strtolower($report->getTitle())) ?: 'report'
        );

        $response = $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withBody($this->streamFactory->createStream($csv));

        return $response;
    }

    public function exportIssuesCsvAction(SeoReport $report): ResponseInterface
    {
        $issuesSummary = $this->buildIssuesSummary($this->pageRepository->findByReportUid($report->getUid()));
        $rows = [[
            'Issue Type',
            'Severity',
            'Message',
            'Affected Pages',
        ]];

        foreach ($issuesSummary as $issue) {
            $rows[] = [
                $issue['type'],
                $issue['severity'],
                $issue['message'],
                (string) $issue['count'],
            ];
        }

        return $this->createCsvResponse(
            $rows,
            sprintf(
                'seo-issues-%d-%s.csv',
                $report->getUid(),
                preg_replace('/[^a-z0-9]+/i', '-', strtolower($report->getTitle())) ?: 'report'
            )
        );
    }

    public function exportJsonAction(SeoReport $report): ResponseInterface
    {
        $pages = $this->pageRepository->findByReportUid($report->getUid());
        $payload = [
            'report' => [
                'uid' => $report->getUid(),
                'title' => $report->getTitle(),
                'baseUrl' => $report->getBaseUrl(),
                'status' => $report->getStatusLabel(),
                'overallScore' => $report->getOverallScore(),
                'pagesCrawled' => $report->getPagesCrawled(),
                'maxPages' => $report->getMaxPages(),
            ],
            'issuesSummary' => $this->buildIssuesSummary($pages),
            'pages' => array_map(static function ($page): array {
                return [
                    'url' => $page->getUrl(),
                    'pageType' => $page->getPageType(),
                    'pageTypeLabel' => $page->getPageTypeLabel(),
                    'statusCode' => $page->getStatusCode(),
                    'contentType' => $page->getContentType(),
                    'redirectTarget' => $page->getRedirectTarget(),
                    'redirectFinalUrl' => $page->getRedirectFinalUrl(),
                    'redirectHops' => $page->getRedirectHops(),
                    'score' => $page->getPageScore(),
                    'errorCount' => $page->getErrorCount(),
                    'warningCount' => $page->getWarningCount(),
                    'noticeCount' => $page->getNoticeCount(),
                    'title' => $page->getPageTitle(),
                    'titleLength' => $page->getTitleLength(),
                    'metaDescription' => $page->getMetaDescription(),
                    'metaDescriptionLength' => $page->getMetaDescriptionLength(),
                    'h1Count' => $page->getH1Count(),
                    'h1Text' => $page->getH1Text(),
                    'h2Count' => $page->getH2Count(),
                    'wordCount' => $page->getWordCount(),
                    'loadTime' => $page->getLoadTime(),
                    'issues' => $page->getIssuesArray(),
                ];
            }, $pages),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $filename = sprintf(
            'seo-report-%d-%s.json',
            $report->getUid(),
            preg_replace('/[^a-z0-9]+/i', '-', strtolower($report->getTitle())) ?: 'report'
        );

        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withBody($this->streamFactory->createStream($json ?: '{}'));
    }

    public function analyzeAction(SeoReport $report, string $runMode = 'queue'): ResponseInterface
    {
        if (!$this->isPostRequest()) {
            $this->addFlashMessage(
                'Running an analysis requires a POST request.',
                'Method not allowed',
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('show', null, null, ['report' => $report->getUid()]);
        }

        try {
            $report->setBaseUrl($this->analyzerService->assertAllowedBaseUrl($report->getBaseUrl()));
        } catch (\InvalidArgumentException $exception) {
            $this->addFlashMessage(
                htmlspecialchars($exception->getMessage()),
                'Unsafe website URL',
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('show', null, null, ['report' => $report->getUid()]);
        }

        if ($runMode === 'now') {
            return $this->startNowAction($report);
        }

        if ($this->isStaleRunningReport($report)) {
            $this->resetReportToQueuedState($report);
            $this->addFlashMessage(
                'A stale running state was detected and reset. The analysis has been queued again.',
                'Stale run recovered',
                ContextualFeedbackSeverity::WARNING
            );
        }

        if ($report->getStatus() === SeoReport::STATUS_RUNNING || $report->getStatus() === SeoReport::STATUS_QUEUED) {
            $this->addFlashMessage(
                'This report is already queued or running.',
                'Analysis already scheduled'
            );
            return $this->redirect('show', null, null, ['report' => $report->getUid()]);
        }

        $this->analyzerService->queueReport($report);
        $this->addFlashMessage(
            'Analysis queued. Run `bin/typo3 aistea-seo:process-queue` or schedule that command to process the crawl in the background.',
            'Analysis queued'
        );

        return $this->redirect('show', null, null, ['report' => $report->getUid()]);
    }

    public function startNowAction(SeoReport $report): ResponseInterface
    {
        if (!$this->isPostRequest()) {
            $this->addFlashMessage(
                'Starting an analysis requires a POST request.',
                'Method not allowed',
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('show', null, null, ['report' => $report->getUid()]);
        }

        if ($this->isStaleRunningReport($report)) {
            $this->resetReportToQueuedState($report);
            $this->addFlashMessage(
                'A stale running state was detected and reset. Starting the report again now.',
                'Stale run recovered',
                ContextualFeedbackSeverity::WARNING
            );
        }

        if ($report->getStatus() === SeoReport::STATUS_RUNNING) {
            $this->addFlashMessage(
                'This report is already running.',
                'Analysis already running'
            );
            return $this->redirect('index');
        }

        if ($report->getStatus() !== SeoReport::STATUS_QUEUED) {
            $this->addFlashMessage(
                'Only queued reports can be started manually. Queue the report first.',
                'Report is not queued',
                ContextualFeedbackSeverity::WARNING
            );
            return $this->redirect('show', null, null, ['report' => $report->getUid()]);
        }

        try {
            $report->setBaseUrl($this->analyzerService->assertAllowedBaseUrl($report->getBaseUrl()));
            $this->pageRepository->deleteByReportUid($report->getUid());
            $this->persistenceManager->persistAll();
            $this->analyzerService->analyzeWebsite($report);
        } catch (\Throwable $exception) {
            $this->addFlashMessage(
                htmlspecialchars($exception->getMessage()),
                'Could not start analysis',
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('show', null, null, ['report' => $report->getUid()]);
        }

        if ($report->getStatus() === SeoReport::STATUS_COMPLETED) {
            $this->addFlashMessage(
                'Analysis completed successfully.',
                'Analysis completed'
            );
        } elseif ($report->getStatus() === SeoReport::STATUS_FAILED) {
            $this->addFlashMessage(
                $report->getErrorMessage() !== '' ? htmlspecialchars($report->getErrorMessage()) : 'The analysis failed.',
                'Analysis failed',
                ContextualFeedbackSeverity::ERROR
            );
        } else {
            $this->addFlashMessage(
                'Analysis finished with an unexpected status.',
                'Analysis finished',
                ContextualFeedbackSeverity::WARNING
            );
        }

        return $this->redirect('show', null, null, ['report' => $report->getUid()]);
    }

    public function deleteAction(SeoReport $report): ResponseInterface
    {
        if (!$this->isPostRequest()) {
            $this->addFlashMessage(
                'Deleting a report requires a POST request.',
                'Method not allowed',
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('show', null, null, ['report' => $report->getUid()]);
        }

        $title = $report->getTitle();
        $this->pageRepository->deleteByReportUid($report->getUid());
        $this->reportRepository->remove($report);
        $this->persistenceManager->persistAll();
        $this->addFlashMessage(
            'Report "' . htmlspecialchars($title) . '" and all its data have been deleted.',
            'Report deleted'
        );
        return $this->redirect('index');
    }

    private function isPostRequest(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    }

    private function registerBackendAssets(): void
    {
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Page\PageRenderer::class)
            ->addJsFile('EXT:aistea_seo/Resources/Public/JavaScript/backend-module.js');
    }

    private function isStaleRunningReport(SeoReport $report): bool
    {
        if ($report->getStatus() !== SeoReport::STATUS_RUNNING) {
            return false;
        }

        $startedAt = $report->getStartedAt();
        if ($startedAt <= 0) {
            return true;
        }

        if ((time() - $startedAt) < 30) {
            return false;
        }

        if ($report->getPagesCrawled() > 0 || $report->getProgressPages() > 0) {
            return false;
        }

        return trim($report->getLastCrawledUrl()) === '';
    }

    private function resetReportToQueuedState(SeoReport $report): void
    {
        $report->setStatus(SeoReport::STATUS_QUEUED);
        $report->setQueuedAt(time());
        $report->setStartedAt(0);
        $report->setFinishedAt(0);
        $report->setProgressPages(0);
        $report->setPagesCrawled(0);
        $report->setOverallScore(0);
        $report->setLastCrawledUrl('');
        $report->setErrorMessage('');
        $this->reportRepository->update($report);
        $this->persistenceManager->persistAll();
    }

    /**
     * @param list<\Aistea\AisteaSeo\Domain\Model\SeoPage> $pages
     * @return list<array{type: string, severity: string, message: string, count: int}>
     */
    private function buildIssuesSummary(array $pages): array
    {
        $issuesSummary = [];
        foreach ($pages as $page) {
            foreach ($page->getIssuesArray() as $issue) {
                $type = $issue['type'] ?? 'unknown';
                if (!isset($issuesSummary[$type])) {
                    $issuesSummary[$type] = [
                        'type' => $type,
                        'severity' => (string) ($issue['severity'] ?? 'notice'),
                        'message' => (string) ($issue['message'] ?? $type),
                        'count' => 0,
                    ];
                }
                $issuesSummary[$type]['count']++;
            }
        }

        usort($issuesSummary, static fn($a, $b) => $b['count'] <=> $a['count']);
        return $issuesSummary;
    }

    /**
     * @param list<list<string>> $rows
     */
    private function createCsvResponse(array $rows, string $filename): ResponseInterface
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withBody($this->streamFactory->createStream($csv));
    }

    /**
     * @param list<\Aistea\AisteaSeo\Domain\Model\SeoPage> $pages
     * @return array<string,int>
     */
    private function buildPageTypeSummary(array $pages): array
    {
        $summary = [
            'html' => 0,
            'redirect' => 0,
            'resource' => 0,
            'withErrors' => 0,
        ];

        foreach ($pages as $page) {
            $pageType = $page->getPageType();
            if (isset($summary[$pageType])) {
                $summary[$pageType]++;
            }
            if ($page->getErrorCount() > 0) {
                $summary['withErrors']++;
            }
        }

        return $summary;
    }

    /**
     * @param list<array{type?: string}> $issues
     */
    private function buildIssueTypesString(array $issues): string
    {
        $types = array_map(
            static fn(array $issue): string => (string) ($issue['type'] ?? ''),
            $issues
        );
        $types = array_values(array_filter(array_unique($types), static fn(string $type): bool => $type !== ''));

        return implode(' | ', $types);
    }

    /**
     * @param list<array{message?: string}> $issues
     */
    private function buildIssueMessagesString(array $issues): string
    {
        $messages = array_map(
            static fn(array $issue): string => trim((string) ($issue['message'] ?? '')),
            $issues
        );
        $messages = array_values(array_filter($messages, static fn(string $message): bool => $message !== ''));

        return implode(' | ', $messages);
    }
}
