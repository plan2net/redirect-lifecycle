<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Service\RedirectCacheService as CoreRedirectCacheService;

final class RedirectCacheService extends CoreRedirectCacheService
{
    public const COMMITTED_CACHE_FAILURE = 1791100801;

    private const CACHE_LOCK_FAILED = 1791100803;

    /** @var array<string, int>|null Hosts to publish, with a redirect UID for error reporting. */
    private ?array $pendingCaches = null;

    public function __construct(private readonly LoggerInterface $logger, ?CacheManager $cacheManager = null)
    {
        parent::__construct($cacheManager);
    }

    /** Invalidate each committed change immediately, then publish each host once, including after failure. */
    public function batch(\Closure $operation): void
    {
        if ($this->pendingCaches !== null) {
            $operation();
            return;
        }
        $this->pendingCaches = [];
        $failure = null;
        try {
            $operation();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        $hosts = $this->pendingCaches;
        $this->pendingCaches = null;
        foreach ($hosts as $host => $uid) {
            try {
                $this->rebuildForHost((string)$host);
            } catch (\Throwable $exception) {
                $this->logCacheFailure($uid, $exception);
                $failure ??= $this->committedCacheFailure($uid, $exception);
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function refreshAfterCommit(string $sourceHost, int $uid): void
    {
        try {
            if ($this->pendingCaches !== null) {
                $sourceHost = $sourceHost === '' ? '*' : $sourceHost;
                // Queue before invalidation so batch completion also repairs failed invalidations.
                $this->pendingCaches[$sourceHost] = $uid;
                $this->invalidateForHost($sourceHost);
            } else {
                $this->rebuildForHost($sourceHost);
            }
        } catch (\Throwable $exception) {
            $this->logCacheFailure($uid, $exception);
            throw $this->committedCacheFailure($uid, $exception);
        }
    }

    public function repairAfterRollback(string $sourceHost, int $uid, \Throwable $writeException): void
    {
        try {
            $this->rebuildForHost($sourceHost);
        } catch (\Throwable $exception) {
            $this->logCacheFailure($uid, $exception, ['writeException' => $writeException]);
        }
    }

    public function refreshAfterHit(string $sourceHost, int $uid): void
    {
        try {
            $this->rebuildForHost($sourceHost);
        } catch (\Throwable $exception) {
            $this->logCacheFailure($uid, $exception);
        }
    }

    public function rebuildForHost(string $sourceHost): array
    {
        // Core matching uses '*' for both wildcard representations, including legacy empty hosts.
        $sourceHost = $sourceHost === '' ? '*' : $sourceHost;
        // DataHandler also rebuilds inside our write transactions. Locking here would invert
        // the database/cache lock order; lifecycle commands rebuild again after committing.
        if (GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_redirect')->getTransactionNestingLevel() > 0) {
            return parent::rebuildForHost($sourceHost);
        }
        return $this->withHostLock($sourceHost, fn(): array => parent::rebuildForHost($sourceHost));
    }

    private function invalidateForHost(string $sourceHost): void
    {
        $sourceHost = $sourceHost === '' ? '*' : $sourceHost;
        $this->withHostLock($sourceHost, fn() => $this->cache->remove('redirects_' . sha1($sourceHost)));
    }

    private function committedCacheFailure(int $uid, \Throwable $exception): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            $GLOBALS['LANG']->sL('LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:cache.committedFailure'), $uid,
        ), self::COMMITTED_CACHE_FAILURE, $exception);
    }

    private function logCacheFailure(int $uid, \Throwable $exception, array $context = []): void
    {
        try {
            $this->logger->error('Redirect cache rebuilding failed.', $context + ['uid' => $uid, 'exception' => $exception]);
        } catch (\Throwable $loggingException) {
            // A failing log writer must not replace the write error or interrupt a redirect.
            error_log(sprintf('Redirect %d cache rebuilding failed: %s; logging failed: %s', $uid, $exception->getMessage(), $loggingException->getMessage()));
        }
    }

    private function withHostLock(string $sourceHost, \Closure $operation): mixed
    {
        // Serialize Core callers too, including cache misses and ordinary backend changes.
        // ponytail: Core locks coordinate one server; clustered deployments need a shared locking strategy.
        $lock = GeneralUtility::makeInstance(LockFactory::class)->createLocker('redirect-lifecycle-cache-' . sha1($sourceHost));
        if (!$lock->acquire()) {
            throw new \RuntimeException('Unable to lock the redirect cache.', self::CACHE_LOCK_FAILED);
        }
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
