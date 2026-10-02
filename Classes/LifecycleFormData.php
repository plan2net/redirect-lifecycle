<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle;

use TYPO3\CMS\Backend\Form\FormDataProviderInterface;

final class LifecycleFormData implements FormDataProviderInterface
{
    public function addData(array $result): array
    {
        if ($result['tableName'] === 'sys_redirect') {
            $row = $result['databaseRow'];
            $result['processedTca']['columns']['endtime']['config']['readOnly'] =
                ($row['redirect_type'] ?? 'default') === 'default' && (int)$row['tx_redirectlifecycle_mode'] !== 2;
            foreach (['endtime', 'tx_redirectlifecycle_delete_after'] as $field) {
                // Core read-only datetime rendering treats zero as the Unix epoch.
                if (!empty($result['processedTca']['columns'][$field]['config']['readOnly']) && (int)($row[$field] ?? 0) === 0) {
                    $result['databaseRow'][$field] = '';
                }
            }
        }
        return $result;
    }
}
