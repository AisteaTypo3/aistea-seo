<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Middleware;

use Aistea\AisteaSeo\Checker\AuditException;
use Aistea\AisteaSeo\Checker\AuditRepository;
use Aistea\AisteaSeo\Checker\AuditRunner;
use Aistea\AisteaSeo\Checker\CheckerSettings;
use Aistea\AisteaSeo\Checker\Http\BlockedTargetException;
use Aistea\AisteaSeo\Checker\Http\UrlGuard;
use Aistea\AisteaSeo\Checker\UrlTools;
use Aistea\AisteaSeo\Checker\VisitorGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * JSON API of the public SEO checker:
 *   POST <language-base>/_seo-checker/start  url, formToken, website (honeypot)
 *   POST <language-base>/_seo-checker/step   token
 */
final class CheckerEndpoint implements MiddlewareInterface
{
    private const LL = 'LLL:EXT:aistea_seo/Resources/Private/Language/locallang_checker.xlf:';

    public function __construct(
        private readonly AuditRunner $runner,
        private readonly AuditRepository $repository,
        private readonly VisitorGuard $visitorGuard,
        private readonly UrlGuard $urlGuard,
        private readonly CheckerSettings $settings,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!preg_match('#/_seo-checker/(start|step)$#', $path, $m)) {
            return $handler->handle($request);
        }
        if ($request->getMethod() !== 'POST') {
            return $this->json(['error' => 'method'], 405);
        }
        if (!$this->isSameOrigin($request)) {
            return $this->json(['error' => 'origin'], 403);
        }

        $body = (array) $request->getParsedBody();
        $ls = $this->languageService($request);

        return $m[1] === 'start' ? $this->start($request, $body, $ls) : $this->step($body, $ls);
    }

    private function start(ServerRequestInterface $request, array $body, LanguageService $ls): ResponseInterface
    {
        if (trim((string) ($body['website'] ?? '')) !== '' || !$this->visitorGuard->isValidFormToken((string) ($body['formToken'] ?? ''))) {
            return $this->error($ls, 'formExpired', 400);
        }

        $url = UrlTools::fromUserInput(mb_substr((string) ($body['url'] ?? ''), 0, 2000));
        if ($url === '') {
            return $this->error($ls, 'invalidUrl', 422);
        }
        try {
            $this->urlGuard->validate($url);
        } catch (BlockedTargetException $e) {
            return $this->error($ls, $e->getMessage(), 422);
        }

        $unlimited = $this->visitorGuard->isUnlimited();
        $identity = $this->visitorGuard->identity($request);
        $day = $this->visitorGuard->day();
        $startOfDay = (int) strtotime('today');
        if (!$unlimited) {
            if ($this->visitorGuard->remaining($request) <= 0) {
                return $this->error($ls, 'dailyLimit', 429, [$this->settings->dailyLimit]);
            }
            if ($this->repository->countCreatedSince($startOfDay) >= $this->settings->globalDailyLimit) {
                return $this->error($ls, 'globalLimit', 503);
            }
            if ($this->repository->countCreatedSince($startOfDay, UrlTools::host($url)) >= $this->settings->perHostDailyLimit) {
                return $this->error($ls, 'hostLimit', 429);
            }
        }

        try {
            $state = $this->runner->initialize($url);
        } catch (AuditException $e) {
            // Unreachable sites do not consume the visitor's quota.
            return $this->error($ls, $e->getMessage(), 422, $e->arguments);
        }

        if (!$unlimited && !$this->repository->reserveQuotaSlot($identity, $day, $this->settings->dailyLimit)) {
            return $this->error($ls, 'dailyLimit', 429, [$this->settings->dailyLimit]);
        }

        $language = $request->getAttribute('language');
        $token = $this->repository->create($url, $language instanceof SiteLanguage ? $language->getLocale()->getLanguageCode() : 'de', $state);

        if (random_int(1, 25) === 1) {
            $this->repository->cleanup($this->settings->retentionDays);
        }

        return $this->json([
            'token' => $token,
            'remaining' => $unlimited ? $this->settings->dailyLimit : $this->visitorGuard->remaining($request),
        ]);
    }

    private function step(array $body, LanguageService $ls): ResponseInterface
    {
        $token = (string) ($body['token'] ?? '');
        if ($this->repository->findByToken($token) === null) {
            return $this->error($ls, 'notFound', 404);
        }

        try {
            $status = $this->runner->step($token);
        } catch (AuditException $e) {
            return $this->error($ls, $e->getMessage(), 404);
        }

        return $this->json($status + [
            'phaseLabel' => $ls->sL(self::LL . 'phase.' . $status['phase']) ?: $status['phase'],
            'error' => $status['status'] === AuditRepository::STATUS_FAILED ? $ls->sL(self::LL . 'error.internal') : '',
        ]);
    }

    /**
     * Rejects cross-site form posts (another site spending the visitor's quota).
     */
    private function isSameOrigin(ServerRequestInterface $request): bool
    {
        $origin = $request->getHeaderLine('Origin') ?: $request->getHeaderLine('Referer');
        if ($origin === '') {
            return $request->getHeaderLine('Sec-Fetch-Site') !== 'cross-site';
        }

        return strtolower((string) parse_url($origin, PHP_URL_HOST)) === strtolower($request->getUri()->getHost());
    }

    private function languageService(ServerRequestInterface $request): LanguageService
    {
        $language = $request->getAttribute('language');

        return $language instanceof SiteLanguage
            ? $this->languageServiceFactory->createFromSiteLanguage($language)
            : $this->languageServiceFactory->create('de');
    }

    private function error(LanguageService $ls, string $code, int $status, array $arguments = []): ResponseInterface
    {
        $label = $ls->sL(self::LL . 'error.' . $code);
        if ($label === '') {
            $label = $ls->sL(self::LL . 'error.generic');
        }
        if ($arguments !== []) {
            $label = vsprintf($label, array_map('strval', $arguments));
        }

        return $this->json(['error' => $code, 'message' => $label], $status);
    }

    private function json(array $data, int $status = 200): ResponseInterface
    {
        return (new JsonResponse($data, $status))
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex');
    }
}
