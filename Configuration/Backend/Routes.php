<?php

use Plan2net\RedirectLifecycle\Controller\RenewController;

return [
    'redirect_lifecycle_renew' => [
        'path' => '/redirect-lifecycle/renew',
        'methods' => ['GET', 'POST'],
        'target' => RenewController::class . '::handle',
    ],
];
