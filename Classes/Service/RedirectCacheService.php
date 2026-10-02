<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Service\RedirectCacheService as CoreRedirectCacheService;

final class RedirectCacheService extends CoreRedirectCacheService
{
    private const CACHE_LOCK_FAILED = 1791100803;

    public function rebuildForHost(string $sourceHost): array
    {
        // Core matching uses '*' for both wildcard representations, including legacy empty hosts.
        $sourceHost = $sourceHost === '' ? '*' : $sourceHost;
        // DataHandler also rebuilds inside our write transactions. Locking here would invert
        // the database/cache lock order; lifecycle commands rebuild again after committing.
        if (GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_redirect')->getTransactionNestingLevel() > 0) {
            return parent::rebuildForHost($sourceHost);
        }
        // Serialize Core callers too, including cache misses and ordinary backend changes.
        // ponytail: Core locks coordinate one server; clustered deployments need a shared locking strategy.
        $lock = GeneralUtility::makeInstance(LockFactory::class)->createLocker('redirect-lifecycle-cache-' . sha1($sourceHost));
        if (!$lock->acquire()) {
            throw new \RuntimeException('Unable to lock the redirect cache.', self::CACHE_LOCK_FAILED);
        }
        try {
            return parent::rebuildForHost($sourceHost);
        } finally {
            $lock->release();
        }
    }
}
