<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Domain\Repository;

use Aistea\AisteaSeo\Domain\Model\SeoReport;
use TYPO3\CMS\Extbase\Persistence\Repository;

class SeoPageRepository extends Repository
{
    public function initializeObject(): void
    {
        $querySettings = $this->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * @return list<\Aistea\AisteaSeo\Domain\Model\SeoPage>
     */
    public function findByReportUid(int $reportUid): array
    {
        $query = $this->createQuery();
        $query->matching($query->equals('report', $reportUid));
        $query->setOrderings(['pageScore' => \TYPO3\CMS\Extbase\Persistence\QueryInterface::ORDER_ASCENDING]);
        return $query->execute()->toArray();
    }

    public function deleteByReportUid(int $reportUid): void
    {
        $pages = $this->findByReportUid($reportUid);
        foreach ($pages as $page) {
            $this->remove($page);
        }
    }
}
