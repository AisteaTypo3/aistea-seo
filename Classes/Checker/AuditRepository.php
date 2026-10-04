<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Checker;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Storage for public audits and the per-visitor daily quota. Plain DBAL instead of Extbase:
 * the step endpoint needs atomic lock updates and runs outside of an Extbase request.
 */
final class AuditRepository
{
    public const TABLE = 'tx_aisteaseo_checker_audit';
    public const QUOTA_TABLE = 'tx_aisteaseo_checker_quota';

    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    private const LOCK_SECONDS = 120;

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    public function create(string $url, string $language, array $state): string
    {
        $token = bin2hex(random_bytes(16));
        $now = time();
        $this->connection()->insert(self::TABLE, [
            'token' => $token,
            'url' => mb_substr($url, 0, 2000),
            'host' => UrlTools::host($url),
            'language' => $language,
            'status' => self::STATUS_RUNNING,
            'phase' => 'site',
            'progress' => 5,
            'state' => json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'created' => $now,
            'updated' => $now,
        ]);

        return $token;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token, bool $withState = false): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $columns = ['uid', 'token', 'url', 'host', 'language', 'status', 'phase', 'progress', 'score', 'result', 'error_message', 'created', 'finished'];
        if ($withState) {
            $columns[] = 'state';
        }
        $qb = $this->connection()->createQueryBuilder();
        $row = $qb->select(...$columns)
            ->from(self::TABLE)
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Atomically acquires the step lock. Only one request can process an audit at a time,
     * so parallel tabs or double clicks never work on the same state.
     */
    public function acquireLock(string $token): bool
    {
        $now = time();
        $qb = $this->connection()->createQueryBuilder();
        $affected = $qb->update(self::TABLE)
            ->set('locked_until', $now + self::LOCK_SECONDS)
            ->where(
                $qb->expr()->eq('token', $qb->createNamedParameter($token)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_RUNNING)),
                $qb->expr()->lt('locked_until', $qb->createNamedParameter($now, Connection::PARAM_INT))
            )
            ->executeStatement();

        return $affected === 1;
    }

    public function saveProgress(string $token, string $phase, int $progress, array $state): void
    {
        $this->connection()->update(self::TABLE, [
            'phase' => $phase,
            'progress' => $progress,
            'state' => json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'updated' => time(),
            'locked_until' => 0,
        ], ['token' => $token]);
    }

    public function complete(string $token, array $result): void
    {
        $this->connection()->update(self::TABLE, [
            'status' => self::STATUS_DONE,
            'phase' => 'done',
            'progress' => 100,
            'score' => (int) ($result['score'] ?? 0),
            'result' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'state' => '',
            'updated' => time(),
            'finished' => time(),
            'locked_until' => 0,
        ], ['token' => $token]);
    }

    public function fail(string $token, string $errorCode): void
    {
        $this->connection()->update(self::TABLE, [
            'status' => self::STATUS_FAILED,
            'error_message' => mb_substr($errorCode, 0, 1000),
            'state' => '',
            'updated' => time(),
            'finished' => time(),
            'locked_until' => 0,
        ], ['token' => $token]);
    }

    public function countCreatedSince(int $since, string $host = ''): int
    {
        $qb = $this->connection()->createQueryBuilder();
        $qb->count('uid')
            ->from(self::TABLE)
            ->where($qb->expr()->gte('created', $qb->createNamedParameter($since, Connection::PARAM_INT)));
        if ($host !== '') {
            $qb->andWhere($qb->expr()->eq('host', $qb->createNamedParameter($host)));
        }

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * Reserves one of the visitor's daily slots. Slots are unique rows, so concurrent requests
     * cannot both take the last slot. Returns false when the quota is used up.
     */
    public function reserveQuotaSlot(string $identity, string $day, int $limit): bool
    {
        for ($slot = 1; $slot <= $limit; $slot++) {
            try {
                $this->connectionPool->getConnectionForTable(self::QUOTA_TABLE)->insert(self::QUOTA_TABLE, [
                    'identity' => $identity,
                    'day' => $day,
                    'slot' => $slot,
                    'created' => time(),
                ]);

                return true;
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        return false;
    }

    public function usedQuota(string $identity, string $day): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::QUOTA_TABLE);

        return (int) $qb->count('uid')
            ->from(self::QUOTA_TABLE)
            ->where(
                $qb->expr()->eq('identity', $qb->createNamedParameter($identity)),
                $qb->expr()->eq('day', $qb->createNamedParameter($day))
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array{audits: int, quota: int}
     */
    public function cleanup(int $retentionDays): array
    {
        $qb = $this->connection()->createQueryBuilder();
        $audits = $qb->delete(self::TABLE)
            ->where($qb->expr()->lt('created', $qb->createNamedParameter(time() - $retentionDays * 86400, Connection::PARAM_INT)))
            ->executeStatement();

        // Abandoned audits (tab closed mid-run) keep a large state blob; mark them failed after a day.
        $qb = $this->connection()->createQueryBuilder();
        $qb->update(self::TABLE)
            ->set('status', self::STATUS_FAILED)
            ->set('error_message', 'abandoned')
            ->set('state', '')
            ->where(
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_RUNNING)),
                $qb->expr()->lt('updated', $qb->createNamedParameter(time() - 86400, Connection::PARAM_INT))
            )
            ->executeStatement();

        $qb = $this->connectionPool->getQueryBuilderForTable(self::QUOTA_TABLE);
        $quota = $qb->delete(self::QUOTA_TABLE)
            ->where($qb->expr()->lt('created', $qb->createNamedParameter(time() - 2 * 86400, Connection::PARAM_INT)))
            ->executeStatement();

        return ['audits' => $audits, 'quota' => $quota];
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
