<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Backend;

use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Core\Information\Typo3Version;

final class LifetimeModeFilter
{
    public static function fromRequest(?ServerRequestInterface $request): ?int
    {
        $module = $request?->getAttribute('module');
        if ((new Typo3Version())->getMajorVersion() < 14
            || !$module instanceof ModuleInterface || $module->getIdentifier() !== 'redirects'
        ) {
            return null;
        }
        return match ($request->getQueryParams()['lifecycleMode'] ?? $request->getParsedBody()['lifecycleMode'] ?? null) {
            '0' => RedirectLifecycle::MODE_UNMANAGED,
            '1' => RedirectLifecycle::MODE_MANAGED,
            '2' => RedirectLifecycle::MODE_FIXED,
            default => null,
        };
    }
}
