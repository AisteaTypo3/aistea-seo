<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Checker;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Crypto\HashAlgo;
use TYPO3\CMS\Core\Crypto\HashService;

/**
 * Visitor identity for the daily quota and the signed form token.
 * The identity is an HMAC of IP + day, so it cannot be reversed or linked across days.
 */
final class VisitorGuard
{
    private const TOKEN_SECRET = 'aistea-seo-checker-form';
    private const TOKEN_MIN_AGE = 2;
    private const TOKEN_MAX_AGE = 7200;

    public function __construct(
        private readonly HashService $hashService,
        private readonly AuditRepository $repository,
        private readonly CheckerSettings $settings,
    ) {}

    public function issueFormToken(): string
    {
        $timestamp = (string) time();

        return $timestamp . '.' . $this->hashService->hmac($timestamp, self::TOKEN_SECRET, HashAlgo::SHA256);
    }

    public function isValidFormToken(string $token): bool
    {
        if (!preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $token, $m)) {
            return false;
        }
        $age = time() - (int) $m[1];

        return $age >= self::TOKEN_MIN_AGE && $age <= self::TOKEN_MAX_AGE
            && $this->hashService->validateHmac($m[1], self::TOKEN_SECRET, $m[2], HashAlgo::SHA256);
    }

    public function day(): string
    {
        return date('Y-m-d');
    }

    public function identity(ServerRequestInterface $request): string
    {
        return $this->hashService->hmac($this->remoteAddress($request) . '|' . $this->day(), 'aistea-seo-checker-quota', HashAlgo::SHA256);
    }

    public function isUnlimited(): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $this->settings->unlimitedForBackendUsers
            && $backendUser instanceof BackendUserAuthentication
            && is_array($backendUser->user)
            && (int) ($backendUser->user['uid'] ?? 0) > 0;
    }

    public function remaining(ServerRequestInterface $request): int
    {
        if ($this->isUnlimited()) {
            return $this->settings->dailyLimit;
        }

        return max(0, $this->settings->dailyLimit - $this->repository->usedQuota($this->identity($request), $this->day()));
    }

    private function remoteAddress(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams !== null) {
            return (string) $normalizedParams->getRemoteAddress();
        }

        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }
}
