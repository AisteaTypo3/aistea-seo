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

        $issuesSummary = [];
        foreach ($pages as $page) {
            foreach ($page->getIssuesArray() as $issue) {
                $type = $issue['type'] ?? 'unknown';
                if (!isset($issuesSummary[$type])) {
                    $issuesSummary[$type] = [
                        'type' => $type,
                        'severity' => $issue['severity'],
                        'message' => $issue['message'],
                        'count' => 0,
                    ];
                }
                $issuesSummary[$type]['count']++;
            }
        }
        usort($issuesSummary, static fn($a, $b) => $b['count'] <=> $a['count']);

        $moduleTemplate->assignMultiple([
            'report' => $report,
            'pages' => $pages,
            'errorCount' => $errorCount,
            'warningCount' => $warningCount,
            'noticeCount' => $noticeCount,
            'issuesSummary' => $issuesSummary,
        ]);

        return $moduleTemplate->renderResponse('Backend/Report/Show');
    }

    public function analyzeAction(SeoReport $report): ResponseInterface
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
}
