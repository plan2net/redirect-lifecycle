<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Event\ModifyAutoCreateRedirectRecordBeforePersistingEvent;
use TYPO3\CMS\Redirects\Event\RedirectWasHitEvent;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;
use TYPO3\CMS\Redirects\Service\SlugService;

final class RedirectLifecycle
{
    public const RENEWAL_ASPECT = 'redirect-lifecycle-renew';

    private bool $restoring = false;

    public function __construct(
        private readonly Context $context,
        private readonly SiteFinder $siteFinder,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ConnectionPool $connectionPool,
        private readonly RedirectCacheService $redirectCacheService,
    ) {}

    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        int|string $id,
        array &$record,
        DataHandler $dataHandler,
    ): void {
        if ($table !== 'sys_redirect') {
            return;
        }
        $input = $dataHandler->datamap[$table][$id];
        if (isset($input['tx_redirectlifecycle_mode'])
            && !in_array($input['tx_redirectlifecycle_mode'], [0, 1, 2, '0', '1', '2'], true)
        ) {
            throw new \InvalidArgumentException('Lifecycle mode must be 0 (unmanaged), 1 (managed), or 2 (fixed).', 1790928002);
        }
        if ($status === 'update') {
            $previous = BackendUtility::getRecord($table, (int)$id);
            if ($previous === null) {
                return;
            }
            $current = array_replace($previous, $record);
            // Core removes unchanged fields before this hook; explicit renewal still needs field permission.
            $renew = in_array(self::RENEWAL_ASPECT, $dataHandler->getCorrelationId()?->getAspects() ?? [], true)
                && in_array($input['tx_redirectlifecycle_mode'] ?? null, [1, '1'], true)
                && ($dataHandler->BE_USER->isAdmin() || $dataHandler->BE_USER->check('non_exclude_fields', 'sys_redirect:tx_redirectlifecycle_mode'));
            if (($current['redirect_type'] ?? 'default') !== 'default') {
                $record['endtime'] = (int)$previous['tx_redirectlifecycle_mode'] === 1 ? $previous['endtime'] : $current['endtime'];
                $record['tx_redirectlifecycle_mode'] = (int)$record['endtime'] > 0 ? 2 : 0;
                $record['tx_redirectlifecycle_delete_after'] = 0;
                return;
            }
            $mode = (int)($record['tx_redirectlifecycle_mode'] ?? $previous['tx_redirectlifecycle_mode']);
            if ($mode !== 1) {
                $record['tx_redirectlifecycle_delete_after'] = 0;
                if ($mode === 0 && (int)$previous['tx_redirectlifecycle_mode'] === 1) {
                    $record['endtime'] = 0;
                }
                if ((int)($record['endtime'] ?? $previous['endtime']) === 0) {
                    $record['tx_redirectlifecycle_mode'] = 0;
                }
            } elseif ((int)$previous['tx_redirectlifecycle_mode'] !== 1
                || $renew
                || (bool)($record['protected'] ?? $previous['protected']) !== (bool)$previous['protected']
                || (!empty($previous['disabled']) && empty($current['disabled']))
            ) {
                $initialized = $this->initialize($current, 1, $this->findSite($current));
                foreach (['endtime', 'tx_redirectlifecycle_delete_after', 'tx_redirectlifecycle_mode'] as $field) {
                    $record[$field] = $initialized[$field];
                }
            } else {
                unset($record['endtime'], $record['tx_redirectlifecycle_delete_after']);
            }
            return;
        }
        $aspects = $dataHandler->getCorrelationId()?->getAspects() ?? [];
        // Core-created metadata is trusted; editor changes use the fields permitted by DataHandler.
        $automatic = in_array(SlugService::CORRELATION_ID_IDENTIFIER, $aspects, true) && in_array('redirect', $aspects, true);
        $mode = isset($input['tx_redirectlifecycle_mode'])
            ? (int)($automatic ? $input['tx_redirectlifecycle_mode'] : $record['tx_redirectlifecycle_mode'])
            : ((int)($record['endtime'] ?? 0) > 0 ? 2 : 1);
        $record = $this->initialize($record, $mode, $this->findSite($record));
    }

    public function processCmdmap(
        string $command,
        string $table,
        int|string $id,
        mixed $value,
        bool &$commandIsProcessed,
        DataHandler $dataHandler,
    ): void {
        if ($this->restoring || $commandIsProcessed || $command !== 'undelete' || $table !== 'sys_redirect') {
            return;
        }
        $record = BackendUtility::getRecord($table, (int)$id, '*', '', false);
        if (!$record || !$record['deleted'] || (int)$record['tx_redirectlifecycle_mode'] !== 1
            || ($record['redirect_type'] ?? 'default') !== 'default'
        ) {
            return;
        }
        $commandIsProcessed = true;
        $connection = $this->connectionPool->getConnectionForTable($table);
        $connection->beginTransaction();
        try {
            // Keep restoration and its lifetime restart atomic with respect to cleanup and hits.
            $connection->update($table, ['uid' => (int)$id], ['uid' => (int)$id]);
            $before = BackendUtility::getRecord($table, (int)$id, '*', '', false);
            $this->restoring = true;
            try {
                // Use the native public command path, preserving permissions, history, and references.
                $restore = GeneralUtility::makeInstance(DataHandler::class);
                $restore->start([], [$table => [$id => ['undelete' => $value]]], $dataHandler->BE_USER);
                if ($dataHandler->getCorrelationId() !== null) {
                    $restore->setCorrelationId($dataHandler->getCorrelationId());
                }
                $restore->process_cmdmap();
                $dataHandler->errorLog = array_merge($dataHandler->errorLog, $restore->errorLog);
            } finally {
                $this->restoring = false;
            }
            $restored = BackendUtility::getRecord($table, (int)$id, '*', '', false);
            if ($before && $before['deleted'] && $restored && !$restored['deleted']
                && (int)$restored['tx_redirectlifecycle_mode'] === 1
                && ($restored['redirect_type'] ?? 'default') === 'default'
            ) {
                $initialized = $this->initialize($restored, 1, $this->findSite($restored));
                $connection->update($table, array_intersect_key($initialized, array_flip([
                    'endtime', 'tx_redirectlifecycle_delete_after',
                ])), ['uid' => (int)$id]);
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            $this->redirectCacheService->rebuildForHost($record['source_host']);
            throw $exception;
        }
        $this->redirectCacheService->rebuildForHost($restored['source_host'] ?? $record['source_host']);
    }

    public function onAutomaticCreation(ModifyAutoCreateRedirectRecordBeforePersistingEvent $event): void
    {
        $record = $event->getRedirectRecord();
        // Core-generated expiry is managed, even though it is already present.
        // TYPO3 13+ includes the unmanaged database default in this record.
        $event->setRedirectRecord($this->initialize($record, 1, $event->getSlugRedirectChangeItem()->getSite()));
    }

    public function onHit(RedirectWasHitEvent $event): void
    {
        $uid = (int)($event->getMatchedRedirect()['uid'] ?? 0);
        if ($uid <= 0) {
            return;
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
            if (!$record || (int)$record['tx_redirectlifecycle_mode'] !== 1
                || $record['protected'] || $record['disabled'] || $record['deleted']
                || (int)$record['starttime'] > $now || (int)$record['endtime'] === 0 || (int)$record['endtime'] < $now
                || ($record['redirect_type'] ?? 'default') !== 'default'
            ) {
                return;
            }
            $settings = $this->extensionConfiguration->get('redirect_lifecycle') + ['minimumRemainingLifetime' => 90];
            $minimum = $this->nonNegativeInteger($settings['minimumRemainingLifetime'], 'minimumRemainingLifetime');
            // Core endtime is an unsigned 32-bit timestamp on all supported versions.
            if ($minimum === 0 || $minimum > intdiv(min(PHP_INT_MAX, 4294967295) - $now, 86400)) {
                throw new \InvalidArgumentException('Minimum remaining lifetime must be positive and fit the timestamp range.', 1790928004);
            }
            $expiry = $now + $minimum * 86400;
            if ($expiry <= (int)$record['endtime']) {
                return;
            }
            $grace = $this->nonNegativeInteger($settings['cleanupGracePeriod'], 'cleanupGracePeriod');
            if ($grace > intdiv(PHP_INT_MAX - $expiry, 86400)) {
                throw new \InvalidArgumentException('Cleanup grace period exceeds the supported timestamp range.', 1790928003);
            }
            $dates = [
                'endtime' => $expiry,
                'tx_redirectlifecycle_delete_after' => max((int)$record['tx_redirectlifecycle_delete_after'], $expiry + $grace * 86400),
            ];
            // Retry from fresh data if another request or backend change wins the update.
            $criteria = array_intersect_key($record, array_flip([
                'uid', 'tx_redirectlifecycle_mode', 'protected', 'disabled', 'deleted',
                'starttime', 'endtime', 'tx_redirectlifecycle_delete_after', 'redirect_type', 'source_host',
            ]));
        } while ($connection->update('sys_redirect', $dates, $criteria) === 0);

        $this->redirectCacheService->rebuildForHost($record['source_host']);
        $event->setMatchedRedirect(array_replace($event->getMatchedRedirect(), $dates));
    }

    private function initialize(array $record, int $mode, ?Site $site): array
    {
        if ($mode === 2 && (int)($record['endtime'] ?? 0) === 0) {
            $mode = 0;
        }
        if (($record['redirect_type'] ?? 'default') !== 'default') {
            $mode = (int)($record['endtime'] ?? 0) > 0 ? 2 : 0;
        }
        $record['tx_redirectlifecycle_mode'] = $mode;
        $record['tx_redirectlifecycle_delete_after'] = 0;
        if ($mode !== 1) {
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
        if ($record['endtime'] < 0 || $record['endtime'] > min(PHP_INT_MAX, 4294967295)) {
            throw new \InvalidArgumentException('Initial lifetime exceeds the Core timestamp range.', 1791014402);
        }
        if ($record['endtime'] > 0 && $grace > intdiv(PHP_INT_MAX - $record['endtime'], 86400)) {
            throw new \InvalidArgumentException('Cleanup grace period exceeds the supported timestamp range.', 1790928003);
        }
        $record['tx_redirectlifecycle_delete_after'] = $record['endtime'] > 0 ? $record['endtime'] + $grace * 86400 : 0;
        return $record;
    }

    private function nonNegativeInteger(mixed $value, string $setting): int
    {
        if ((!is_int($value) && (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
        ) {
            throw new \InvalidArgumentException($setting . ' must be a non-negative integer number of days.', 1790928001);
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
