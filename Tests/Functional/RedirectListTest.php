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

    #[DataProvider('modeFilters')]
    public function testLifetimeModeFilterOnlyAppliesOnTypo3Fourteen(mixed $value, array $expectedUids): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        $connection->insert('sys_redirect', ['uid' => 101, 'source_host' => '*', 'source_path' => '/managed', 'target' => 'https://target.test/', 'tx_redirectlifecycle_mode' => 1]);
        $connection->insert('sys_redirect', ['uid' => 102, 'source_host' => '*', 'source_path' => '/fixed', 'target' => 'https://target.test/', 'tx_redirectlifecycle_mode' => 2, 'endtime' => 1798761600]);

        $html = (string)$this->get(ManagementController::class)->handleRequest($this->request(['lifecycleMode' => $value]))->getBody();
        if ((new Typo3Version())->getMajorVersion() < 14) {
            $expectedUids = [100, 101, 102];
        }
        self::assertEqualsCanonicalizing($expectedUids, $this->rowUids($html));
    }

    public static function modeFilters(): array
    {
        return [
            'unmanaged' => ['0', [100]],
            'managed' => ['1', [101]],
            'fixed' => ['2', [102]],
            'all' => ['', [100, 101, 102]],
            'invalid' => ['invalid', [100, 101, 102]],
            'array input' => [['1'], [100, 101, 102]],
        ];
    }

    public function testModeFilterCombinesWithNativeFiltersBeforePagination(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        for ($i = 0; $i < 55; ++$i) {
            foreach (['example.test' => 200, 'other.test' => 400] as $host => $firstUid) {
                $connection->insert('sys_redirect', [
                    'uid' => $firstUid + $i, 'source_host' => $host, 'source_path' => sprintf('/filter-%03d', 100 + $i),
                    'target' => 'https://target.test/', 'tx_redirectlifecycle_mode' => 1,
                ]);
            }
        }
        for ($i = 0; $i < 60; ++$i) {
            $connection->insert('sys_redirect', [
                'uid' => 600 + $i, 'source_host' => 'example.test', 'source_path' => sprintf('/filter-%03d', $i), 'target' => 'https://target.test/',
            ]);
        }
        $connection->insert('sys_redirect', ['uid' => 700, 'source_host' => 'example.test', 'source_path' => '/filter-080', 'target' => 'https://target.test/', 'tx_redirectlifecycle_mode' => 2]);
        $html = (string)$this->get(ManagementController::class)->handleRequest($this->request([
            'lifecycleMode' => '1', 'page' => '2',
            'demand' => ['source_host' => 'example.test', 'source_path' => '/filter-', 'target' => 'target.test'],
        ]))->getBody();

        $expectedUids = range(250, 254);
        $expectedPages = '2';
        if ((new Typo3Version())->getMajorVersion() < 14) {
            $expectedUids = array_merge(range(650, 659), [700], range(200, 238));
            $expectedPages = '3';
        }
        self::assertSame($expectedUids, $this->rowUids($html));
        self::assertSame($expectedPages, $this->xpath($html)->evaluate('string(//input[@name="paginator-target-page"]/@max)'));
    }

    public function testEmptyFilteredListRetainsFilterAndAcceptsPost(): void
    {
        $html = (string)$this->get(ManagementController::class)->handleRequest($this->request([], ['lifecycleMode' => '1']))->getBody();
        if ((new Typo3Version())->getMajorVersion() < 14) {
            self::assertSame([100], $this->rowUids($html));
        } else {
            self::assertSame([], $this->rowUids($html));
            self::assertTrue(str_contains($html, '@plan2net/redirect-lifecycle/redirect-list.js'), 'Empty filtered lists must retain the filter.');
            self::assertTrue(str_contains($html, '"value":1'), 'The selected mode must be retained after POST.');
        }
    }

    public function testModeFilterPreservesEditorRecordPermissions(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        $connection->insert('sys_redirect', ['uid' => 101, 'source_host' => '*', 'source_path' => '/allowed', 'target' => 'https://target.test/', 'tx_redirectlifecycle_mode' => 1]);
        $connection->insert('sys_redirect', ['uid' => 102, 'source_host' => '*', 'source_path' => '/denied', 'target' => 't3://page?uid=3', 'tx_redirectlifecycle_mode' => 1]);
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_userid' => 1, 'perms_user' => 0], ['uid' => 3]);
        $user = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);

        $html = (string)$this->get(ManagementController::class)->handleRequest($this->request(['lifecycleMode' => '1']))->getBody();
        $expected = (new Typo3Version())->getMajorVersion() < 14 ? [100, 101] : [101];
        self::assertEqualsCanonicalizing($expected, $this->rowUids($html));
    }

    private function rowUids(string $html): array
    {
        return array_map(static fn(\DOMNode $node): int => (int)$node->nodeValue, iterator_to_array($this->xpath($html)->query('//tr/@data-uid')));
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        return new \DOMXPath($document);
    }

    private function request(array $query = [], array $body = []): ServerRequest
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
        $request = $request->withQueryParams($query);
        if ($body !== []) {
            $request = $request->withMethod('POST')->withParsedBody($body);
        }
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $request;
    }
}
