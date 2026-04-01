<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Command;

use Aistea\AisteaSeo\Domain\Repository\SeoPageRepository;
use Aistea\AisteaSeo\Domain\Repository\SeoReportRepository;
use Aistea\AisteaSeo\Service\SeoAnalyzerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

#[AsCommand(
    name: 'aistea-seo:process-queue',
    description: 'Processes queued SEO reports asynchronously'
)]
final class ProcessSeoQueueCommand extends Command
{
    public function __construct(
        private readonly SeoReportRepository $reportRepository,
        private readonly SeoPageRepository $pageRepository,
        private readonly SeoAnalyzerService $analyzerService,
        private readonly PersistenceManager $persistenceManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_REQUIRED,
            'Maximum number of queued reports to process',
            '5'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $reports = $this->reportRepository->findQueuedReports($limit);

        if ($reports === []) {
            $output->writeln('<info>No queued SEO reports found.</info>');
            return Command::SUCCESS;
        }

        foreach ($reports as $report) {
            $output->writeln(sprintf(
                '<info>Processing report #%d: %s</info>',
                $report->getUid(),
                $report->getTitle()
            ));

            $this->pageRepository->deleteByReportUid($report->getUid());
            $this->persistenceManager->persistAll();
            $this->analyzerService->analyzeWebsite($report);

            $output->writeln(sprintf(
                'Status: %s, pages crawled: %d, score: %d/100',
                $report->getStatusLabel(),
                $report->getPagesCrawled(),
                $report->getOverallScore()
            ));
        }

        return Command::SUCCESS;
    }
}
