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
}
