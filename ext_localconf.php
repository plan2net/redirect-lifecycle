<?php

declare(strict_types=1);

use Plan2net\RedirectLifecycle\Hook\LifecycleDataHandlerHook;
use Plan2net\RedirectLifecycle\Form\FormDataProvider\LifecycleFormData;
use Plan2net\RedirectLifecycle\Form\FormDataProvider\LifecycleFormDefaults;
use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRowInitializeNew;
use TYPO3\CMS\Backend\Form\FormDataProvider\InitializeProcessedTca;
use TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsOverrides;
use TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsProcessCommon;

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['redirect_lifecycle'] = LifecycleDataHandlerHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['redirect_lifecycle'] = LifecycleDataHandlerHook::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearCachePostProc']['redirects'] = LifecycleDataHandlerHook::class . '->rebuildRedirectCacheIfNecessary';

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'][LifecycleFormData::class] = [
    'depends' => [TcaColumnsOverrides::class],
    'before' => [TcaColumnsProcessCommon::class],
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'][LifecycleFormDefaults::class] = [
    'depends' => [InitializeProcessedTca::class],
    'before' => [DatabaseRowInitializeNew::class],
];
