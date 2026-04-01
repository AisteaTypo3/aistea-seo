<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class SeoReport extends AbstractEntity
{
    public const STATUS_NEW = 0;
    public const STATUS_RUNNING = 1;
    public const STATUS_COMPLETED = 2;
    public const STATUS_FAILED = 3;
    public const STATUS_QUEUED = 4;

    protected string $title = '';
    protected string $baseUrl = '';
    protected int $status = self::STATUS_NEW;
    protected int $pagesCrawled = 0;
    protected int $progressPages = 0;
    protected int $maxPages = 50;
    protected int $overallScore = 0;
    protected string $errorMessage = '';
    protected int $queuedAt = 0;
    protected int $startedAt = 0;
    protected int $finishedAt = 0;
    protected string $lastCrawledUrl = '';
    protected string $robotsTxtUrl = '';
    protected int $robotsTxtStatus = 0;
    protected string $robotsTxtSitemaps = '';
    protected string $sitemapUrl = '';
    protected int $sitemapStatus = 0;
    protected int $sitemapUrlCount = 0;

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = $baseUrl;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_NEW => 'New',
            self::STATUS_RUNNING => 'Running',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_QUEUED => 'Queued',
            default => 'Unknown',
        };
    }

    public function getStatusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_NEW => 'secondary',
            self::STATUS_RUNNING => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_QUEUED => 'warning',
            default => 'secondary',
        };
    }

    public function getPagesCrawled(): int
    {
        return $this->pagesCrawled;
    }

    public function setPagesCrawled(int $pagesCrawled): void
    {
        $this->pagesCrawled = $pagesCrawled;
    }

    public function getProgressPages(): int
    {
        return $this->progressPages;
    }

    public function setProgressPages(int $progressPages): void
    {
        $this->progressPages = $progressPages;
    }

    public function getMaxPages(): int
    {
        return $this->maxPages;
    }

    public function setMaxPages(int $maxPages): void
    {
        $this->maxPages = $maxPages;
    }

    public function getOverallScore(): int
    {
        return $this->overallScore;
    }

    public function setOverallScore(int $overallScore): void
    {
        $this->overallScore = $overallScore;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getQueuedAt(): int
    {
        return $this->queuedAt;
    }

    public function setQueuedAt(int $queuedAt): void
    {
        $this->queuedAt = $queuedAt;
    }

    public function getStartedAt(): int
    {
        return $this->startedAt;
    }

    public function setStartedAt(int $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getFinishedAt(): int
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(int $finishedAt): void
    {
        $this->finishedAt = $finishedAt;
    }

    public function getLastCrawledUrl(): string
    {
        return $this->lastCrawledUrl;
    }

    public function setLastCrawledUrl(string $lastCrawledUrl): void
    {
        $this->lastCrawledUrl = $lastCrawledUrl;
    }

    public function getRobotsTxtUrl(): string
    {
        return $this->robotsTxtUrl;
    }

    public function setRobotsTxtUrl(string $robotsTxtUrl): void
    {
        $this->robotsTxtUrl = $robotsTxtUrl;
    }

    public function getRobotsTxtStatus(): int
    {
        return $this->robotsTxtStatus;
    }

    public function setRobotsTxtStatus(int $robotsTxtStatus): void
    {
        $this->robotsTxtStatus = $robotsTxtStatus;
    }

    public function getRobotsTxtSitemaps(): string
    {
        return $this->robotsTxtSitemaps;
    }

    public function setRobotsTxtSitemaps(string $robotsTxtSitemaps): void
    {
        $this->robotsTxtSitemaps = $robotsTxtSitemaps;
    }

    public function getRobotsTxtSitemapsArray(): array
    {
        if ($this->robotsTxtSitemaps === '') {
            return [];
        }

        return json_decode($this->robotsTxtSitemaps, true) ?? [];
    }

    public function getSitemapUrl(): string
    {
        return $this->sitemapUrl;
    }

    public function setSitemapUrl(string $sitemapUrl): void
    {
        $this->sitemapUrl = $sitemapUrl;
    }

    public function getSitemapStatus(): int
    {
        return $this->sitemapStatus;
    }

    public function setSitemapStatus(int $sitemapStatus): void
    {
        $this->sitemapStatus = $sitemapStatus;
    }

    public function getSitemapUrlCount(): int
    {
        return $this->sitemapUrlCount;
    }

    public function setSitemapUrlCount(int $sitemapUrlCount): void
    {
        $this->sitemapUrlCount = $sitemapUrlCount;
    }

    public function getScoreBadgeClass(): string
    {
        if ($this->overallScore >= 80) {
            return 'success';
        }
        if ($this->overallScore >= 60) {
            return 'warning';
        }
        return 'danger';
    }

    public function getProgressPercent(): int
    {
        if ($this->maxPages <= 0) {
            return 0;
        }

        return max(0, min(100, (int) round(($this->progressPages / $this->maxPages) * 100)));
    }

    public function getRobotsTxtStatusBadgeClass(): string
    {
        if ($this->robotsTxtStatus === 200) {
            return 'success';
        }
        if ($this->robotsTxtStatus === 0) {
            return 'secondary';
        }
        if ($this->robotsTxtStatus >= 400) {
            return 'warning';
        }

        return 'info';
    }

    public function getSitemapStatusBadgeClass(): string
    {
        if ($this->sitemapStatus === 200) {
            return 'success';
        }
        if ($this->sitemapStatus === 0) {
            return 'secondary';
        }
        if ($this->sitemapStatus >= 400) {
            return 'warning';
        }

        return 'info';
    }
}
