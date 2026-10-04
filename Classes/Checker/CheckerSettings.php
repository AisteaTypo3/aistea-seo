<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Checker;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Limits for the public SEO checker (Settings > Extension Configuration > aistea_seo).
 * Kept out of FlexForms on purpose: abuse limits are an operator decision, not an editor one.
 */
final class CheckerSettings
{
    public readonly int $dailyLimit;
    public readonly int $globalDailyLimit;
    public readonly int $perHostDailyLimit;
    public readonly int $maxPages;
    public readonly int $linkCheckLimit;
    public readonly int $retentionDays;
    public readonly bool $pageSpeedEnabled;
    public readonly string $pageSpeedApiKey;
    public readonly bool $unlimitedForBackendUsers;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        try {
            $config = (array) $extensionConfiguration->get('aistea_seo');
        } catch (\Throwable) {
            $config = [];
        }
        $config = (array) ($config['checker'] ?? $config);

        $int = static fn(string $key, int $default, int $min, int $max): int => max($min, min($max, (int) ($config[$key] ?? $default)));

        $this->dailyLimit = $int('dailyLimit', 2, 1, 100);
        $this->globalDailyLimit = $int('globalDailyLimit', 300, 1, 100000);
        $this->perHostDailyLimit = $int('perHostDailyLimit', 6, 1, 1000);
        $this->maxPages = $int('maxPages', 30, 1, 100);
        $this->linkCheckLimit = $int('linkCheckLimit', 80, 0, 300);
        $this->retentionDays = $int('retentionDays', 30, 1, 365);
        $this->pageSpeedEnabled = (bool) ($config['pageSpeedEnabled'] ?? true);
        $this->pageSpeedApiKey = trim((string) ($config['pageSpeedApiKey'] ?? ''));
        $this->unlimitedForBackendUsers = (bool) ($config['unlimitedForBackendUsers'] ?? true);
    }
}
