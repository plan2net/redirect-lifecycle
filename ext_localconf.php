<?php

declare(strict_types=1);

use Plan2net\RedirectLifecycle\RedirectLifecycle;
use Plan2net\RedirectLifecycle\LifecycleFormData;
use Plan2net\RedirectLifecycle\LifecycleFormDefaults;
use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRowInitializeNew;
use TYPO3\CMS\Backend\Form\FormDataProvider\InitializeProcessedTca;
use TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsOverrides;
use TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsProcessCommon;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['redirect_lifecycle'] = RedirectLifecycle::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['redirect_lifecycle'] = RedirectLifecycle::class;

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'][LifecycleFormData::class] = [
    'depends' => [TcaColumnsOverrides::class],
    'before' => [TcaColumnsProcessCommon::class],
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'][LifecycleFormDefaults::class] = [
    'depends' => [InitializeProcessedTca::class],
    'before' => [DatabaseRowInitializeNew::class],
];
