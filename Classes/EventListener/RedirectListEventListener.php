<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\EventListener;

use Plan2net\RedirectLifecycle\Backend\LifetimeModeFilter;
use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Redirects\Event\ModifyRedirectManagementControllerViewDataEvent;

final class RedirectListEventListener
{
    public function __construct(private readonly PageRenderer $pageRenderer, private readonly UriBuilder $uriBuilder) {}

    public function __invoke(ModifyRedirectManagementControllerViewDataEvent $event): void
    {
        if ((new Typo3Version())->getMajorVersion() < 14) {
            return;
        }
        if ($event->getDemand()->getRedirectType() !== 'default') {
            return;
        }
        $modes = [];
        foreach ([RedirectLifecycle::MODE_UNMANAGED, RedirectLifecycle::MODE_MANAGED, RedirectLifecycle::MODE_FIXED] as $mode) {
            $modes[$mode] = BackendUtility::getProcessedValue('sys_redirect', 'tx_redirectlifecycle_mode', $mode);
        }
        $labels = [];
        foreach ($event->getRedirects() as $redirect) {
            if ($redirect['redirect_type'] !== 'default') {
                continue;
            }
            $labels[$redirect['uid']] = $modes[(int)$redirect['tx_redirectlifecycle_mode']] ?? '';
        }
        $this->pageRenderer->getJavaScriptRenderer()->addJavaScriptModuleInstruction(
            JavaScriptModuleInstruction::create('@plan2net/redirect-lifecycle/redirect-list.js')->invoke(null, $labels, [
                'label' => $GLOBALS['LANG']->sL($GLOBALS['TCA']['sys_redirect']['columns']['tx_redirectlifecycle_mode']['label']),
                'all' => $GLOBALS['LANG']->sL('LLL:EXT:redirects/Resources/Private/Language/locallang_module_redirect.xlf:filter.source_host.showAll'),
                'modes' => $modes,
                'value' => LifetimeModeFilter::fromRequest($event->getRequest()),
                'url' => (string)$this->uriBuilder->buildUriFromRoute('redirects'),
            ]),
        );
    }
}
