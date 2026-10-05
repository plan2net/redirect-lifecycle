<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Hook;

use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Redirects\Hooks\DataHandlerCacheFlushingHook;
use TYPO3\CMS\Redirects\Service\SlugService;

final class LifecycleDataHandlerHook
{
    private const INVALID_LIFECYCLE_MODE = 1790928002;

    public function __construct(private readonly RedirectLifecycle $lifecycle) {}

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
            && !in_array($input['tx_redirectlifecycle_mode'], [
                RedirectLifecycle::MODE_UNMANAGED, RedirectLifecycle::MODE_MANAGED, RedirectLifecycle::MODE_FIXED,
                (string)RedirectLifecycle::MODE_UNMANAGED, (string)RedirectLifecycle::MODE_MANAGED, (string)RedirectLifecycle::MODE_FIXED,
            ], true)
        ) {
            throw new \InvalidArgumentException('Lifecycle mode must be 0 (unmanaged), 1 (managed), or 2 (fixed).', self::INVALID_LIFECYCLE_MODE);
        }
        $aspects = $dataHandler->getCorrelationId()?->getAspects() ?? [];
        if ($status === 'update') {
            $previous = BackendUtility::getRecord($table, (int)$id);
            if ($previous === null) {
                return;
            }
            // Core removes unchanged fields before this hook; explicit renewal still needs field permission.
            $renew = in_array(RedirectLifecycle::RENEWAL_ASPECT, $aspects, true)
                && in_array($input['tx_redirectlifecycle_mode'] ?? null, [RedirectLifecycle::MODE_MANAGED, (string)RedirectLifecycle::MODE_MANAGED], true)
                && ($dataHandler->BE_USER->isAdmin() || $dataHandler->BE_USER->check('non_exclude_fields', 'sys_redirect:tx_redirectlifecycle_mode'));
            $record = $this->lifecycle->prepareUpdate($previous, $record, $renew);
            return;
        }
        // Core-created metadata is trusted; editor changes use the fields permitted by DataHandler.
        $automatic = in_array(SlugService::CORRELATION_ID_IDENTIFIER, $aspects, true) && in_array('redirect', $aspects, true);
        $mode = isset($input['tx_redirectlifecycle_mode'])
            ? (int)($automatic ? $input['tx_redirectlifecycle_mode'] : $record['tx_redirectlifecycle_mode'])
            : null;
        $record = $this->lifecycle->prepareCreation($record, $mode);
    }

    public function rebuildRedirectCacheIfNecessary(array $parameters, DataHandler $dataHandler): void
    {
        if (($parameters['table'] ?? '') === 'sys_redirect'
            && $this->lifecycle->handlesCachePublication($dataHandler, (int)($parameters['uid'] ?? 0))
        ) {
            return;
        }
        (new DataHandlerCacheFlushingHook())->rebuildRedirectCacheIfNecessary($parameters, $dataHandler);
    }

    public function processCmdmap(
        string $command,
        string $table,
        int|string $id,
        mixed $value,
        bool &$commandIsProcessed,
        DataHandler $dataHandler,
    ): void {
        if ($commandIsProcessed || $command !== 'undelete' || $table !== 'sys_redirect') {
            return;
        }
        $this->lifecycle->restore((int)$id, $value, $dataHandler, $commandIsProcessed);
    }
}
