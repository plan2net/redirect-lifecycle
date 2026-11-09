<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Controller\ManagementController;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RedirectListTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['plan2net/redirect-lifecycle'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Creation.csv');
        $user = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
    }

    #[DataProvider('lifetimeModes')]
    public function testListShowsLifetimeModeOnlyOnTypo3Fourteen(int $mode, bool $protected, string $label): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->update('sys_redirect', [
            'tx_redirectlifecycle_mode' => $mode,
            'protected' => (int)$protected,
            'endtime' => $mode === 2 ? 1798761600 : 0,
        ], ['uid' => 100]);

        $response = $this->get(ManagementController::class)->handleRequest($this->request());
        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();
        self::assertStringContainsString('/legacy', $html);
        if ((new Typo3Version())->getMajorVersion() < 14) {
            self::assertStringNotContainsString('@plan2net/redirect-lifecycle/redirect-list.js', $html);
        } else {
            self::assertStringContainsString('@plan2net/redirect-lifecycle/redirect-list.js', $html);
            self::assertStringContainsString('"100":"' . $label . '"', $html);
        }
    }

    public static function lifetimeModes(): array
    {
        return [
            'unmanaged' => [0, false, 'No automatic management'],
            'managed with unlimited validity' => [1, false, 'Automatic management'],
            'protected retains managed membership' => [1, true, 'Automatic management'],
            'fixed expiry' => [2, false, 'Fixed expiry time'],
        ];
    }

    private function request(): ServerRequest
    {
        $moduleIdentifier = (new Typo3Version())->getMajorVersion() < 14 ? 'site_redirects' : 'redirects';
        $serverParams = ['HTTP_HOST' => 'example.test', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/index.php'];
        $params = new NormalizedParams($serverParams, $GLOBALS['TYPO3_CONF_VARS']['SYS'], '/var/www/html/public/index.php', '/var/www/html/public');
        if ((new Typo3Version())->getMajorVersion() < 14) {
            GeneralUtility::setIndpEnv('TYPO3_REQUEST_HOST', $params->getRequestHost());
            GeneralUtility::setIndpEnv('TYPO3_SITE_URL', $params->getSiteUrl());
            GeneralUtility::setIndpEnv('TYPO3_SITE_PATH', $params->getSitePath());
        }
        $request = (new ServerRequest('https://example.test/typo3/module/site/redirects', 'GET', 'php://input', [], $serverParams))
            ->withAttribute('route', $this->get(Router::class)->getRoute($moduleIdentifier))
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule($moduleIdentifier))
            ->withAttribute('moduleData', new ModuleData($moduleIdentifier, []))
            ->withAttribute('site', new Site('main', 1, ['base' => 'https://example.test/']))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('backend.user', $GLOBALS['BE_USER'])
            ->withAttribute('normalizedParams', $params);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $request;
    }
}
