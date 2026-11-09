<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\EventListener;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Redirects\Event\ModifyRedirectManagementControllerViewDataEvent;

final class RedirectListEventListener
{
    public function __construct(private readonly PageRenderer $pageRenderer) {}

    public function __invoke(ModifyRedirectManagementControllerViewDataEvent $event): void
    {
        if ((new Typo3Version())->getMajorVersion() < 14) {
            return;
        }
        $labels = [];
        foreach ($event->getRedirects() as $redirect) {
            if ($redirect['redirect_type'] !== 'default') {
                continue;
            }
            $labels[$redirect['uid']] = BackendUtility::getProcessedValue(
                'sys_redirect', 'tx_redirectlifecycle_mode', $redirect['tx_redirectlifecycle_mode'],
            );
        }
        if ($labels !== []) {
            $this->pageRenderer->getJavaScriptRenderer()->addJavaScriptModuleInstruction(
                JavaScriptModuleInstruction::create('@plan2net/redirect-lifecycle/redirect-list.js')->invoke(null, $labels),
            );
        }
    }
}
