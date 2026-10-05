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
    public static ?\Throwable $invalidationFailure = null;
    public static array $invalidations = [];
    private bool $publishing = false;

    public function remove($entryIdentifier): bool
    {
        if (!$this->publishing && str_starts_with($entryIdentifier, 'redirects_')) {
            self::$invalidations[] = $entryIdentifier;
            if (self::$invalidationFailure !== null) {
                throw self::$invalidationFailure;
            }
        }
        return parent::remove($entryIdentifier);
    }

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
        $this->publishing = true;
        try {
            parent::set($entryIdentifier, $data, $tags, $lifetime);
        } finally {
            $this->publishing = false;
        }
    }
}
