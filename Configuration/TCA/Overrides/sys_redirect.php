<?php

declare(strict_types=1);

use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addTCAcolumns('sys_redirect', [
    'tx_redirectlifecycle_mode' => [
        'exclude' => true,
        'label' => 'LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:mode',
        'description' => 'LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:mode.description',
        'onChange' => 'reload',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'default' => RedirectLifecycle::MODE_UNMANAGED,
            'fieldControl' => [
                'renewLifetime' => ['renderType' => 'redirectLifecycleRenew'],
            ],
            'items' => [
                ['label' => 'LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:mode.unmanaged', 'value' => RedirectLifecycle::MODE_UNMANAGED],
                ['label' => 'LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:mode.managed', 'value' => RedirectLifecycle::MODE_MANAGED],
                ['label' => 'LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:mode.fixed', 'value' => RedirectLifecycle::MODE_FIXED],
            ],
        ],
    ],
    'tx_redirectlifecycle_delete_after' => [
        'exclude' => true,
        'label' => 'LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:deleteAfter',
        'displayCond' => 'FIELD:tx_redirectlifecycle_mode:=:' . RedirectLifecycle::MODE_MANAGED,
        'config' => ['type' => 'datetime', 'readOnly' => true],
    ],
]);

if (isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
    $GLOBALS['TCA']['sys_redirect']['columns']['tx_redirectlifecycle_mode']['displayCond'] = 'FIELD:redirect_type:=:default';
}
$GLOBALS['TCA']['sys_redirect']['palettes']['visibility']['showitem'] =
    'disabled, --linebreak--, tx_redirectlifecycle_mode, --linebreak--, starttime, endtime, --linebreak--, tx_redirectlifecycle_delete_after';
