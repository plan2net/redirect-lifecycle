<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Tests\Functional\Fixtures;

use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class FailingCacheBackend extends Typo3DatabaseBackend
{
    public static ?\Throwable $transactionFailure = null;
    public static ?\Throwable $outsideFailure = null;
    public static array $attempts = [];

    // Keep the broad TYPO3 12 parameters while satisfying TYPO3 14's void return.
    public function set($entryIdentifier, $data, array $tags = [], $lifetime = null): void
    {
        if (str_starts_with($entryIdentifier, 'redirects_')) {
            $level = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('sys_redirect')->getTransactionNestingLevel();
            self::$attempts[] = $level;
            $failure = $level > 0 ? self::$transactionFailure : self::$outsideFailure;
            if ($failure !== null) {
                throw $failure;
            }
        }
        parent::set($entryIdentifier, $data, $tags, $lifetime);
    }
}
