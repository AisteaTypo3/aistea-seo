<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class SeoPage extends AbstractEntity
{
    protected int $report = 0;
    protected string $url = '';
    protected int $statusCode = 0;
    protected string $pageTitle = '';
    protected int $titleLength = 0;
    protected string $metaDescription = '';
    protected int $metaDescriptionLength = 0;
    protected int $h1Count = 0;
    protected string $h1Text = '';
    protected int $h2Count = 0;
    protected string $canonicalUrl = '';
    protected int $robotsNoindex = 0;
    protected int $robotsNofollow = 0;
    protected int $imagesTotal = 0;
    protected int $imagesMissingAlt = 0;
    protected int $linksInternal = 0;
    protected int $linksExternal = 0;
    protected int $wordCount = 0;
    protected int $loadTime = 0;
    protected int $pageScore = 0;
    protected string $issues = '';

    public function getReport(): int
    {
        return $this->report;
    }

    public function setReport(int $report): void
    {
        $this->report = $report;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    public function getPageTitle(): string
    {
        return $this->pageTitle;
    }

    public function setPageTitle(string $pageTitle): void
    {
        $this->pageTitle = $pageTitle;
    }

    public function getTitleLength(): int
    {
        return $this->titleLength;
    }

    public function setTitleLength(int $titleLength): void
    {
        $this->titleLength = $titleLength;
    }

    public function getMetaDescription(): string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(string $metaDescription): void
    {
        $this->metaDescription = $metaDescription;
    }

    public function getMetaDescriptionLength(): int
    {
        return $this->metaDescriptionLength;
    }

    public function setMetaDescriptionLength(int $metaDescriptionLength): void
    {
        $this->metaDescriptionLength = $metaDescriptionLength;
    }

    public function getH1Count(): int
    {
        return $this->h1Count;
    }

    public function setH1Count(int $h1Count): void
    {
        $this->h1Count = $h1Count;
    }

    public function getH1Text(): string
    {
        return $this->h1Text;
    }

    public function setH1Text(string $h1Text): void
    {
        $this->h1Text = $h1Text;
    }

    public function getH2Count(): int
    {
        return $this->h2Count;
    }

    public function setH2Count(int $h2Count): void
    {
        $this->h2Count = $h2Count;
    }

    public function getCanonicalUrl(): string
    {
        return $this->canonicalUrl;
    }

    public function setCanonicalUrl(string $canonicalUrl): void
    {
        $this->canonicalUrl = $canonicalUrl;
    }

    public function getRobotsNoindex(): int
    {
        return $this->robotsNoindex;
    }

    public function setRobotsNoindex(int $robotsNoindex): void
    {
        $this->robotsNoindex = $robotsNoindex;
    }

    public function getRobotsNofollow(): int
    {
        return $this->robotsNofollow;
    }

    public function setRobotsNofollow(int $robotsNofollow): void
    {
        $this->robotsNofollow = $robotsNofollow;
    }

    public function getImagesTotal(): int
    {
        return $this->imagesTotal;
    }

    public function setImagesTotal(int $imagesTotal): void
    {
        $this->imagesTotal = $imagesTotal;
    }

    public function getImagesMissingAlt(): int
    {
        return $this->imagesMissingAlt;
    }

    public function setImagesMissingAlt(int $imagesMissingAlt): void
    {
        $this->imagesMissingAlt = $imagesMissingAlt;
    }

    public function getLinksInternal(): int
    {
        return $this->linksInternal;
    }

    public function setLinksInternal(int $linksInternal): void
    {
        $this->linksInternal = $linksInternal;
    }

    public function getLinksExternal(): int
    {
        return $this->linksExternal;
    }

    public function setLinksExternal(int $linksExternal): void
    {
        $this->linksExternal = $linksExternal;
    }

    public function getWordCount(): int
    {
        return $this->wordCount;
    }

    public function setWordCount(int $wordCount): void
    {
        $this->wordCount = $wordCount;
    }

    public function getLoadTime(): int
    {
        return $this->loadTime;
    }

    public function setLoadTime(int $loadTime): void
    {
        $this->loadTime = $loadTime;
    }

    public function getPageScore(): int
    {
        return $this->pageScore;
    }

    public function setPageScore(int $pageScore): void
    {
        $this->pageScore = $pageScore;
    }

    public function getIssues(): string
    {
        return $this->issues;
    }

    public function setIssues(string $issues): void
    {
        $this->issues = $issues;
    }

    public function getIssuesArray(): array
    {
        if (empty($this->issues)) {
            return [];
        }
        return json_decode($this->issues, true) ?? [];
    }

    public function getScoreBadgeClass(): string
    {
        if ($this->pageScore >= 80) {
            return 'success';
        }
        if ($this->pageScore >= 60) {
            return 'warning';
        }
        return 'danger';
    }

    public function getErrorCount(): int
    {
        return count(array_filter($this->getIssuesArray(), static fn($i) => ($i['severity'] ?? '') === 'error'));
    }

    public function getWarningCount(): int
    {
        return count(array_filter($this->getIssuesArray(), static fn($i) => ($i['severity'] ?? '') === 'warning'));
    }

    public function getNoticeCount(): int
    {
        return count(array_filter($this->getIssuesArray(), static fn($i) => ($i['severity'] ?? '') === 'notice'));
    }

    public function getStatusCodeClass(): string
    {
        if ($this->statusCode >= 500) {
            return 'danger';
        }
        if ($this->statusCode >= 400) {
            return 'warning';
        }
        if ($this->statusCode >= 300) {
            return 'info';
        }
        return 'success';
    }
}
