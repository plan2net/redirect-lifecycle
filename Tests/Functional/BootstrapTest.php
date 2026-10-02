<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Tests\Functional;

use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class BootstrapTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];

    protected array $testExtensionsToLoad = ['plan2net/redirect-lifecycle'];

    public function testExtensionBootstrapsWithRedirectsAndScheduler(): void
    {
        $packageManager = $this->get(PackageManager::class);

        self::assertTrue($packageManager->isPackageActive('redirect_lifecycle'));
        self::assertTrue($packageManager->isPackageActive('redirects'));
        self::assertTrue($packageManager->isPackageActive('scheduler'));
        self::assertArrayHasKey('sys_redirect', $GLOBALS['TCA']);
    }
}
