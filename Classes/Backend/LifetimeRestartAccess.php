<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Backend;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

final class LifetimeRestartAccess
{
    public function isAllowed(array $record): bool
    {
        $user = $GLOBALS['BE_USER'];
        if (empty($user->user['uid'])) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        if (!$user->check('tables_select', 'sys_redirect') || !$user->check('tables_modify', 'sys_redirect')
            || !$user->check('non_exclude_fields', 'sys_redirect:tx_redirectlifecycle_mode')
        ) {
            return false;
        }
        if ((int)$record['pid'] > 0 && !BackendUtility::getRecord('pages', (int)$record['pid'], 'uid', ' AND ' . $user->getPagePermsClause(Permission::CONTENT_EDIT))) {
            return false;
        }
        return method_exists($user, 'checkRecordEditAccess')
            ? $user->checkRecordEditAccess('sys_redirect', $record)->isAllowed
            : $user->recordEditAccessInternals('sys_redirect', $record);
    }
}
