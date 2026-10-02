<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Form\FormDataProvider;

use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use TYPO3\CMS\Backend\Form\FormDataProviderInterface;

final class LifecycleFormDefaults implements FormDataProviderInterface
{
    public function addData(array $result): array
    {
        if ($result['tableName'] === 'sys_redirect' && $result['command'] === 'new') {
            // Form defaults must not change the database default for existing records.
            $result['processedTca']['columns']['tx_redirectlifecycle_mode']['config']['default'] = RedirectLifecycle::MODE_MANAGED;
        }
        return $result;
    }
}
