<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Command;

use Aistea\AisteaSeo\Checker\AuditRepository;
use Aistea\AisteaSeo\Checker\CheckerSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'aistea-seo:checker-cleanup',
    description: 'Deletes expired public SEO checker reports and old quota entries'
)]
final class CheckerCleanupCommand extends Command
{
    public function __construct(
        private readonly AuditRepository $repository,
        private readonly CheckerSettings $settings,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->repository->cleanup($this->settings->retentionDays);
        $output->writeln(sprintf('Deleted %d reports and %d quota entries.', $result['audits'], $result['quota']));

        return Command::SUCCESS;
    }
}
