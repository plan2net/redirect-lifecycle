<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;

final class RedirectLifecycle
{
    public const RENEWAL_ASPECT = 'redirect-lifecycle-renew';

    public const MODE_UNMANAGED = 0;
    public const MODE_MANAGED = 1;
    public const MODE_FIXED = 2;

    private const DEFAULT_MINIMUM_REMAINING_LIFETIME = 90;
    private const DEFAULT_RENEWAL_LIFETIME = 180;

    // Core endtime uses an unsigned 32-bit timestamp on all supported TYPO3 versions.
    private const MAX_CORE_ENDTIME = 4_294_967_295;

    // Fixed 24-hour days for hit renewal and cleanup, independent of daylight saving.
    private const SECONDS_PER_DAY = 24 * 60 * 60;

    private const INVALID_PERIOD_VALUE = 1790928001;
    private const CLEANUP_GRACE_PERIOD_OVERFLOW = 1790928003;
    private const INVALID_MINIMUM_REMAINING_LIFETIME = 1790928004;
    private const CLEANUP_FAILED = 1791014401;
    private const INITIAL_LIFETIME_OUT_OF_RANGE = 1791014402;
    private const LIFETIME_FIELD_PERMISSION_DENIED = 1791014404;
    private const LIFETIME_RESTART_FAILED = 1791014405;
    private const INVALID_RENEWAL_LIFETIME = 1791100802;
    private const COMMITTED_CACHE_FAILURE = 1791100801;

    private bool $restoring = false;

    /** @var \WeakMap<DataHandler, array<int, true>> */
    private \WeakMap $restoredRecords;

    public function __construct(
        private readonly Context $context,
        private readonly SiteFinder $siteFinder,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ConnectionPool $connectionPool,
        private readonly RedirectCacheService $redirectCacheService,
        private readonly LoggerInterface $logger,
    ) {
        $this->restoredRecords = new \WeakMap();
    }

    public function prepareCreation(array $record, ?int $mode = null, ?Site $site = null): array
    {
        $mode ??= (int)($record['endtime'] ?? 0) > 0 ? self::MODE_FIXED : self::MODE_MANAGED;
        return $this->initialize($record, $mode, $site ?? $this->findSite($record));
    }

    public function prepareUpdate(array $previous, array $record, bool $renew): array
    {
        $current = array_replace($previous, $record);
        if (($current['redirect_type'] ?? 'default') !== 'default') {
            $record['endtime'] = (int)$previous['tx_redirectlifecycle_mode'] === self::MODE_MANAGED ? $previous['endtime'] : $current['endtime'];
            $record['tx_redirectlifecycle_mode'] = (int)$record['endtime'] > 0 ? self::MODE_FIXED : self::MODE_UNMANAGED;
            $record['tx_redirectlifecycle_delete_after'] = 0;
            return $record;
        }
        $mode = (int)($record['tx_redirectlifecycle_mode'] ?? $previous['tx_redirectlifecycle_mode']);
        if ($mode !== self::MODE_MANAGED) {
            $record['tx_redirectlifecycle_delete_after'] = 0;
            if ($mode === self::MODE_UNMANAGED && (int)$previous['tx_redirectlifecycle_mode'] === self::MODE_MANAGED) {
                $record['endtime'] = 0;
            }
            if ((int)($record['endtime'] ?? $previous['endtime']) === 0) {
                $record['tx_redirectlifecycle_mode'] = self::MODE_UNMANAGED;
            }
        } elseif ((int)$previous['tx_redirectlifecycle_mode'] !== self::MODE_MANAGED
            || $renew
            || (bool)($record['protected'] ?? $previous['protected']) !== (bool)$previous['protected']
            || (!empty($previous['disabled']) && empty($current['disabled']))
        ) {
            $initialized = $this->initialize($current, self::MODE_MANAGED, $this->findSite($current));
            foreach (['endtime', 'tx_redirectlifecycle_delete_after', 'tx_redirectlifecycle_mode'] as $field) {
                $record[$field] = $initialized[$field];
            }
        } else {
            unset($record['endtime'], $record['tx_redirectlifecycle_delete_after']);
        }
        return $record;
    }

    public function restore(int $uid, mixed $value, DataHandler $dataHandler, bool &$commandIsProcessed): void
    {
        if ($this->restoring || $commandIsProcessed) {
            return;
        }
        $record = BackendUtility::getRecord('sys_redirect', $uid, '*', '', false);
        if (!$record || !$record['deleted']
            || (int)$record['tx_redirectlifecycle_mode'] !== self::MODE_MANAGED
            || ($record['redirect_type'] ?? 'default') !== 'default'
        ) {
            return;
        }
        $commandIsProcessed = true;
        try {
            $this->write($uid, function (array &$record) use ($uid, $value, $dataHandler): ?string {
                $before = $record;
                $this->restoring = true;
                try {
                    // Use the native public command path, preserving permissions, history, and references.
                    $restore = GeneralUtility::makeInstance(DataHandler::class);
                    $restore->start([], ['sys_redirect' => [$uid => ['undelete' => $value]]], $dataHandler->BE_USER);
                    if ($dataHandler->getCorrelationId() !== null) {
                        $restore->setCorrelationId($dataHandler->getCorrelationId());
                    }
                    $restore->process_cmdmap();
                    $dataHandler->errorLog = array_merge($dataHandler->errorLog, $restore->errorLog);
                } finally {
                    $this->restoring = false;
                }
                $restored = BackendUtility::getRecord('sys_redirect', $uid, '*', '', false);
                if ($before && $before['deleted'] && $restored && !$restored['deleted']
                    && (int)$restored['tx_redirectlifecycle_mode'] === self::MODE_MANAGED
                    && ($restored['redirect_type'] ?? 'default') === 'default'
                ) {
                    $initialized = $this->initialize($restored, self::MODE_MANAGED, $this->findSite($restored));
                    $this->connectionPool->getConnectionForTable('sys_redirect')->update('sys_redirect', array_intersect_key($initialized, array_flip([
                        'endtime', 'tx_redirectlifecycle_delete_after',
                    ])), ['uid' => $uid]);
                }
                $record = $restored ?: $record;
                // Nested DataHandler defers its native cache queue until the outer command finishes.
                $this->redirectCacheService->rebuildForHost($record['source_host']);
                return null;
            }, $record);
        } catch (\RuntimeException $exception) {
            if ($exception->getCode() !== self::COMMITTED_CACHE_FAILURE) {
                throw $exception;
            }
            $dataHandler->errorLog[] = $exception->getMessage();
        }
        $this->restoredRecords[$dataHandler] = ($this->restoredRecords[$dataHandler] ?? []) + [$uid => true];
    }

    public function restoreCacheHandled(DataHandler $dataHandler, int $uid): bool
    {
        return isset($this->restoredRecords[$dataHandler][$uid]);
    }

    /** Null means applied; a reason means skipped after checking the locked record. */
    public function cleanup(int $uid, int $now): ?string
    {
        return $this->write($uid, function (array $record) use ($uid, $now): ?string {
            $query = $this->cleanupQuery($now);
            if (!$query->andWhere($query->expr()->eq('uid', $query->createNamedParameter($uid, Connection::PARAM_INT)))
                ->executeQuery()->fetchAssociative()
            ) {
                return 'notDue';
            }
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], ['sys_redirect' => [$uid => ['delete' => 1]]]);
            $dataHandler->process_cmdmap();
            if ($dataHandler->errorLog !== []) {
                throw new \RuntimeException(implode("\n", $dataHandler->errorLog), self::CLEANUP_FAILED);
            }
            return null;
        });
    }

    /** Null means applied; otherwise returns the untranslated skip reason. */
    public function adopt(int $uid): ?string
    {
        return $this->restart($uid, true);
    }

    /** @return array{endtime: int, shortens: bool, state: string} */
    public function previewRenewal(array $record): array
    {
        $preview = $this->prepareCreation($record, self::MODE_MANAGED);
        $expiry = (int)$preview['endtime'];
        $now = $this->context->getAspect('date')->getDateTime()->getTimestamp();
        return [
            'endtime' => $expiry,
            'shortens' => $expiry > 0 && ((int)$record['endtime'] === 0 || $expiry < (int)$record['endtime']),
            // Ignore seconds spent reviewing, but bind confirmation to the record and lifetime rules.
            'state' => hash('sha256', serialize([$record, $expiry > 0 ? $expiry - $now : 0,
                $preview['tx_redirectlifecycle_delete_after'] - $expiry,
            ])),
        ];
    }

    /** Null means applied; otherwise returns the untranslated skip reason, including a changed preview. */
    public function renew(int $uid, ?string $expectedState = null): ?string
    {
        return $this->restart($uid, false, $expectedState);
    }

    private function restart(int $uid, bool $adopt, ?string $expectedState = null): ?string
    {
        return $this->write($uid, function (array $record) use ($uid, $adopt, $expectedState): ?string {
            $reason = $this->actionReason($record, $adopt);
            if ($reason !== 'eligible') {
                return $reason;
            }
            if ($expectedState !== null && !hash_equals($this->previewRenewal($record)['state'], $expectedState)) {
                return 'changed';
            }
            if (!$GLOBALS['BE_USER']->isAdmin() && !$GLOBALS['BE_USER']->check('non_exclude_fields', 'sys_redirect:tx_redirectlifecycle_mode')) {
                throw new \RuntimeException($GLOBALS['LANG']->sL('LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:action.permission'), self::LIFETIME_FIELD_PERMISSION_DENIED);
            }
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            // Native non-admin validation requires source and target, even when unchanged.
            $dataHandler->start(['sys_redirect' => [$uid => [
                'tx_redirectlifecycle_mode' => self::MODE_MANAGED, 'source_host' => $record['source_host'], 'target' => $record['target'],
            ]]], []);
            if (!$adopt && $dataHandler->getCorrelationId() !== null) {
                $dataHandler->setCorrelationId($dataHandler->getCorrelationId()->withAspects(self::RENEWAL_ASPECT));
            }
            $dataHandler->process_datamap();
            if ($dataHandler->errorLog !== []) {
                throw new \RuntimeException(implode("\n", $dataHandler->errorLog), self::LIFETIME_RESTART_FAILED);
            }
            return null;
        });
    }

    private function write(int $uid, \Closure $operation, array $record = []): ?string
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_redirect');
        $record = $record ?: BackendUtility::getRecord('sys_redirect', $uid, '*', '', false) ?? [];
        $sourceHost = $record['source_host'] ?? '';
        $connection->beginTransaction();
        try {
            // A no-op write locks the row on every supported database, including SQLite.
            $connection->update('sys_redirect', ['uid' => $uid], ['uid' => $uid]);
            $record = BackendUtility::getRecord('sys_redirect', $uid, '*', '', false) ?? [];
            $reason = $operation($record);
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            try {
                $this->redirectCacheService->rebuildForHost($record['source_host'] ?? $sourceHost);
            } catch (\Throwable $cacheException) {
                $this->logCacheFailure($uid, $cacheException, ['writeException' => $exception]);
            }
            throw $exception;
        }
        if ($reason === null) {
            // DataHandler rebuilds inside the transaction; refresh again after committing.
            try {
                $this->redirectCacheService->rebuildForHost($record['source_host'] ?? $sourceHost);
            } catch (\Throwable $exception) {
                $this->logCacheFailure($uid, $exception);
                throw new \RuntimeException(sprintf(
                    $GLOBALS['LANG']->sL('LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:cache.committedFailure'),
                    $uid,
                ), self::COMMITTED_CACHE_FAILURE, $exception);
            }
        }
        return $reason;
    }

    public function cleanupCandidates(int $now): array
    {
        return $this->cleanupQuery($now)->executeQuery()->fetchAllAssociative();
    }

    private function cleanupQuery(int $now): QueryBuilder
    {
        $query = $this->connectionPool->getQueryBuilderForTable('sys_redirect');
        // Expired records are the candidates, so native enable-field restrictions must be removed.
        $query->getRestrictions()->removeAll();
        $query->select('*')->from('sys_redirect')->orderBy('uid')->where(
            $query->expr()->eq('deleted', 0),
            $query->expr()->eq('disabled', 0),
            $query->expr()->eq('protected', 0),
            $query->expr()->eq('tx_redirectlifecycle_mode', self::MODE_MANAGED),
            $query->expr()->gt('endtime', 0),
            $query->expr()->lt('endtime', $query->createNamedParameter($now, Connection::PARAM_INT)),
            $query->expr()->gte('tx_redirectlifecycle_delete_after', 'endtime'),
            $query->expr()->lte('tx_redirectlifecycle_delete_after', $query->createNamedParameter($now, Connection::PARAM_INT)),
        );
        if (isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
            $query->andWhere($query->expr()->eq('redirect_type', $query->createNamedParameter('default')));
        }
        return $query;
    }

    public function actionReason(array $record, bool $adopt): string
    {
        if (!isset($record['tx_redirectlifecycle_mode'])) {
            return 'missing';
        }
        if ($record['deleted']) {
            return 'deleted';
        }
        if (($record['redirect_type'] ?? 'default') !== 'default') {
            return 'excludedType';
        }
        if ($adopt) {
            if ((int)$record['tx_redirectlifecycle_mode'] === self::MODE_MANAGED) {
                return 'alreadyManaged';
            }
            if ((int)$record['tx_redirectlifecycle_mode'] !== self::MODE_UNMANAGED || (int)$record['endtime'] !== 0) {
                return 'fixed';
            }
            if ($record['protected']) {
                return 'protected';
            }
            if ($record['disabled']) {
                return 'disabled';
            }
            if ((int)$record['starttime'] > $this->context->getAspect('date')->getDateTime()->getTimestamp()) {
                return 'notStarted';
            }
        } elseif ((int)$record['tx_redirectlifecycle_mode'] !== self::MODE_MANAGED) {
            return (int)$record['tx_redirectlifecycle_mode'] === self::MODE_FIXED || (int)$record['endtime'] > 0 ? 'fixed' : 'unmanaged';
        }
        return 'eligible';
    }

    public function extendOnHit(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        $now = $this->context->getAspect('date')->getDateTime()->getTimestamp();
        $connection = $this->connectionPool->getConnectionForTable('sys_redirect');
        $query = $connection->createQueryBuilder();
        // The generic endtime restriction excludes the final second; Core redirect matching includes it.
        $query->getRestrictions()->removeAll();
        $query->select('*')->from('sys_redirect')
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid, Connection::PARAM_INT)));
        do {
            $record = $query->executeQuery()->fetchAssociative();
            if (!$record || (int)$record['tx_redirectlifecycle_mode'] !== self::MODE_MANAGED
                || $record['protected'] || $record['disabled'] || $record['deleted']
                || (int)$record['starttime'] > $now || (int)$record['endtime'] === 0 || (int)$record['endtime'] < $now
                || ($record['redirect_type'] ?? 'default') !== 'default'
            ) {
                return null;
            }
            $settings = $this->extensionConfiguration->get('redirect_lifecycle') + [
                'minimumRemainingLifetime' => self::DEFAULT_MINIMUM_REMAINING_LIFETIME,
                'renewalLifetime' => self::DEFAULT_RENEWAL_LIFETIME,
            ];
            $minimum = $this->nonNegativeInteger($settings['minimumRemainingLifetime'], 'minimumRemainingLifetime');
            if ($minimum === 0 || $minimum > intdiv(min(PHP_INT_MAX, self::MAX_CORE_ENDTIME) - $now, self::SECONDS_PER_DAY)) {
                throw new \InvalidArgumentException('Minimum remaining lifetime must be positive and fit the timestamp range.', self::INVALID_MINIMUM_REMAINING_LIFETIME);
            }
            $renewal = $this->nonNegativeInteger($settings['renewalLifetime'], 'renewalLifetime');
            if ($renewal <= $minimum || $renewal > intdiv(min(PHP_INT_MAX, self::MAX_CORE_ENDTIME) - $now, self::SECONDS_PER_DAY)) {
                throw new \InvalidArgumentException('Renewal lifetime must exceed the minimum remaining lifetime and fit the timestamp range.', self::INVALID_RENEWAL_LIFETIME);
            }
            if ($now + $minimum * self::SECONDS_PER_DAY <= (int)$record['endtime']) {
                return null;
            }
            $expiry = $now + $renewal * self::SECONDS_PER_DAY;
            $grace = $this->nonNegativeInteger($settings['cleanupGracePeriod'], 'cleanupGracePeriod');
            if ($grace > intdiv(PHP_INT_MAX - $expiry, self::SECONDS_PER_DAY)) {
                throw new \InvalidArgumentException('Cleanup grace period exceeds the supported timestamp range.', self::CLEANUP_GRACE_PERIOD_OVERFLOW);
            }
            $dates = [
                'endtime' => $expiry,
                'tx_redirectlifecycle_delete_after' => max((int)$record['tx_redirectlifecycle_delete_after'], $expiry + $grace * self::SECONDS_PER_DAY),
            ];
            // Retry from fresh data if another request or backend change wins the update.
            $criteria = array_intersect_key($record, array_flip([
                'uid', 'tx_redirectlifecycle_mode', 'protected', 'disabled', 'deleted',
                'starttime', 'endtime', 'tx_redirectlifecycle_delete_after', 'redirect_type', 'source_host',
            ]));
        } while ($connection->update('sys_redirect', $dates, $criteria) === 0);

        try {
            $this->redirectCacheService->rebuildForHost($record['source_host']);
        } catch (\Throwable $exception) {
            $this->logCacheFailure($uid, $exception);
        }
        return $dates;
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

    private function initialize(array $record, int $mode, ?Site $site): array
    {
        if ($mode === self::MODE_FIXED && (int)($record['endtime'] ?? 0) === 0) {
            $mode = self::MODE_UNMANAGED;
        }
        if (($record['redirect_type'] ?? 'default') !== 'default') {
            $mode = (int)($record['endtime'] ?? 0) > 0 ? self::MODE_FIXED : self::MODE_UNMANAGED;
        }
        $record['tx_redirectlifecycle_mode'] = $mode;
        $record['tx_redirectlifecycle_delete_after'] = 0;
        if ($mode !== self::MODE_MANAGED) {
            return $record;
        }
        $record['endtime'] = 0;
        if (!empty($record['protected'])) {
            return $record;
        }
        $settings = $this->extensionConfiguration->get('redirect_lifecycle');
        $ttl = $this->nonNegativeInteger($site?->getSettings()->get('redirects.redirectTTL', 0) ?? $settings['redirectTTL'], 'redirectTTL');
        $grace = $this->nonNegativeInteger($settings['cleanupGracePeriod'], 'cleanupGracePeriod');
        $record['endtime'] = $ttl > 0
            ? $this->context->getAspect('date')->getDateTime()->modify('+' . $ttl . ' days')->getTimestamp()
            : 0;
        if ($record['endtime'] < 0 || $record['endtime'] > min(PHP_INT_MAX, self::MAX_CORE_ENDTIME)) {
            throw new \InvalidArgumentException('Initial lifetime exceeds the Core timestamp range.', self::INITIAL_LIFETIME_OUT_OF_RANGE);
        }
        if ($record['endtime'] > 0 && $grace > intdiv(PHP_INT_MAX - $record['endtime'], self::SECONDS_PER_DAY)) {
            throw new \InvalidArgumentException('Cleanup grace period exceeds the supported timestamp range.', self::CLEANUP_GRACE_PERIOD_OVERFLOW);
        }
        $record['tx_redirectlifecycle_delete_after'] = $record['endtime'] > 0 ? $record['endtime'] + $grace * self::SECONDS_PER_DAY : 0;
        return $record;
    }

    private function nonNegativeInteger(mixed $value, string $setting): int
    {
        if ((!is_int($value) && (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
        ) {
            throw new \InvalidArgumentException($setting . ' must be a non-negative integer number of days.', self::INVALID_PERIOD_VALUE);
        }
        return (int)$value;
    }

    private function findSite(array $record): ?Site
    {
        try {
            return $this->siteFinder->getSiteByPageId((int)$record['pid']);
        } catch (SiteNotFoundException) {
            $matches = [];
            foreach ($this->siteFinder->getAllSites() as $site) {
                foreach ($site->getAllLanguages() as $language) {
                    $base = $language->getBase();
                    $port = $base->getPort();
                    $host = $base->getHost() . ($port ? ':' . $port : '');
                    if (strcasecmp($host, $record['source_host']) === 0) {
                        $matches[$site->getIdentifier()] = $site;
                    }
                }
            }
            return count($matches) === 1 ? reset($matches) : null;
        }
    }
}
