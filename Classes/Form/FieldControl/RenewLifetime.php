<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Form\FieldControl;

use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

final class RenewLifetime extends AbstractNode
{
    public function __construct() {}

    // TYPO3 12 supports this forward-compatible initialization path too.
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function render(): array
    {
        $uid = $this->data['databaseRow']['uid'] ?? 0;
        if ($this->data['tableName'] !== 'sys_redirect' || $this->data['command'] !== 'edit' || !is_numeric($uid) || (int)$uid <= 0) {
            return [];
        }
        $record = BackendUtility::getRecord('sys_redirect', (int)$uid);
        $user = $GLOBALS['BE_USER'];
        if (!$record || GeneralUtility::makeInstance(RedirectLifecycle::class)->actionReason($record, false) !== 'eligible'
            || (!$user->isAdmin() && (!$user->check('tables_modify', 'sys_redirect')
                || !$user->check('non_exclude_fields', 'sys_redirect:tx_redirectlifecycle_mode')))
        ) {
            return [];
        }
        $title = 'LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:backend.renew';
        $id = StringUtility::getUniqueId('t3js-formengine-fieldcontrol-');
        return [
            'iconIdentifier' => 'actions-refresh',
            'title' => $title,
            'linkAttributes' => [
                'id' => $id,
                'href' => (string)GeneralUtility::makeInstance(UriBuilder::class)->buildUriFromRoute('redirect_lifecycle_renew', [
                    'uid' => (int)$uid, 'returnUrl' => $this->data['returnUrl'] ?? '',
                ]),
                'aria-label' => $GLOBALS['LANG']->sL($title),
            ],
            'javaScriptModules' => [
                JavaScriptModuleInstruction::create('@typo3/backend/form-engine/field-control/list-module.js')->instance('#' . $id),
            ],
        ];
    }
}
