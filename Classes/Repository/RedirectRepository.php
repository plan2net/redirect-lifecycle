<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Repository;

use Plan2net\RedirectLifecycle\Backend\LifetimeModeFilter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Redirects\Repository\Demand;
use TYPO3\CMS\Redirects\Repository\RedirectRepository as CoreRedirectRepository;

final class RedirectRepository extends CoreRedirectRepository
{
    // The Core has no query event; this shared method covers both rows and counts.
    protected function getQueryBuilderForDemand(Demand $demand, bool $createCountQuery = false): QueryBuilder
    {
        $queryBuilder = parent::getQueryBuilderForDemand($demand, $createCountQuery);
        $mode = LifetimeModeFilter::fromRequest($GLOBALS['TYPO3_REQUEST'] ?? null);
        if ($mode !== null && $demand->getRedirectType() === 'default') {
            $queryBuilder->andWhere($queryBuilder->expr()->eq(
                'tx_redirectlifecycle_mode', $queryBuilder->createNamedParameter($mode, Connection::PARAM_INT),
            ));
        }
        return $queryBuilder;
    }
}
