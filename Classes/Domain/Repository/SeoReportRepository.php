<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Domain\Repository;

use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

class SeoReportRepository extends Repository
{
    protected $defaultOrderings = [
        'crdate' => QueryInterface::ORDER_DESCENDING,
    ];

    public function initializeObject(): void
    {
        $querySettings = $this->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * @return list<\Aistea\AisteaSeo\Domain\Model\SeoReport>
     */
    public function findQueuedReports(int $limit = 10): array
    {
        $query = $this->createQuery();
        $query->matching($query->equals('status', \Aistea\AisteaSeo\Domain\Model\SeoReport::STATUS_QUEUED));
        $query->setOrderings([
            'queuedAt' => QueryInterface::ORDER_ASCENDING,
            'crdate' => QueryInterface::ORDER_ASCENDING,
        ]);
        $query->setLimit(max(1, $limit));

        return $query->execute()->toArray();
    }
}
