<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Plan2net\RedirectLifecycle\Tests\Functional\Support\TestClock;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Event\RedirectWasHitEvent;
use TYPO3\CMS\Redirects\Service\RedirectService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SluggiIntegrationTest extends FunctionalTestCase
{
    use TestClock;

    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['wazum/sluggi', 'plan2net/redirect-lifecycle'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Creation.csv');
        $user = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
        $this->setTime('2026-01-01 UTC');
        $writer = class_exists(SiteWriter::class) ? $this->get(SiteWriter::class) : $this->get(SiteConfiguration::class);
        $writer->write('main', [
            'rootPageId' => 1, 'base' => 'https://example.test/',
            'languages' => [[
                'languageId' => 0, 'title' => 'English', 'enabled' => true,
                'base' => '/', 'locale' => 'en_US.UTF-8', 'iso-639-1' => 'en',
            ]],
            'settings' => ['redirects' => ['redirectTTL' => 10]],
        ]);
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['tx_sluggi_sync' => 1], ['uid' => 2]);
    }

    #[DataProvider('slugChanges')]
    public function testSluggiChangesCreateManagedRedirectsThatRenewAndRespectProtection(array $fields): void
    {
        $this->updatePage($fields);
        self::assertSame('/renamed', BackendUtility::getRecord('pages', 2)['slug']);
        $record = $this->get(RedirectService::class)->matchRedirect('example.test', '/old');
        self::assertNotNull($record);
        self::assertSame(1, (int)$record['tx_redirectlifecycle_mode']);
        self::assertSame(1768089600, (int)$record['endtime']);
        self::assertSame(1775865600, (int)$record['tx_redirectlifecycle_delete_after']);
        $this->get(EventDispatcherInterface::class)->dispatch(new RedirectWasHitEvent(
            new ServerRequest('https://example.test/old', 'GET'), new HtmlResponse('', 307),
            $record, new Uri('https://example.test/renamed'),
        ));
        $renewed = $this->get(RedirectService::class)->matchRedirect('example.test', '/old');
        self::assertSame((new \DateTimeImmutable('2026-06-30 UTC'))->getTimestamp(), (int)$renewed['endtime']);
        self::assertSame((new \DateTimeImmutable('2026-09-28 UTC'))->getTimestamp(), (int)$renewed['tx_redirectlifecycle_delete_after']);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['sys_redirect' => [$record['uid'] => ['protected' => 1]]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        $protected = $this->get(RedirectService::class)->matchRedirect('example.test', '/old');
        self::assertSame(1, (int)$protected['tx_redirectlifecycle_mode']);
        self::assertSame(0, (int)$protected['endtime']);
        self::assertSame(0, (int)$protected['tx_redirectlifecycle_delete_after']);
    }

    public static function slugChanges(): array
    {
        return ['title synchronization' => [['title' => 'Renamed']], 'explicit slug' => [['slug' => '/renamed']]];
    }

    public function testSluggiChildCascadeCreatesManagedRedirects(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 5, 'pid' => 2, 'title' => 'Child', 'slug' => '/old/child', 'tx_sluggi_sync' => 1,
        ]);
        $this->updatePage(['title' => 'Renamed']);
        self::assertSame('/renamed/child', BackendUtility::getRecord('pages', 5)['slug']);
        $record = $this->get(RedirectService::class)->matchRedirect('example.test', '/old/child');
        self::assertNotNull($record);
        self::assertSame(1, (int)$record['tx_redirectlifecycle_mode']);
        self::assertSame(1768089600, (int)$record['endtime']);
        self::assertSame(1775865600, (int)$record['tx_redirectlifecycle_delete_after']);
    }

    #[DataProvider('suppressedRedirects')]
    public function testSluggiSuppressionDoesNotCreateLifecycleRecords(bool $hidden): void
    {
        if ($hidden) {
            $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['hidden' => 1], ['uid' => 2]);
        } else {
            $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.test/typo3/', 'POST'))
                ->withAttribute('applicationType', 2)
                ->withParsedBody(['data' => ['pages' => [2 => ['tx_sluggi_redirect' => 0]]]]);
        }
        $this->updatePage(['slug' => '/renamed']);
        self::assertSame('/renamed', BackendUtility::getRecord('pages', 2)['slug']);
        self::assertNull($this->get(RedirectService::class)->matchRedirect('example.test', '/old'));
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable('sys_redirect')->count('*', 'sys_redirect', ['source_path' => '/old']));
    }

    public static function suppressedRedirects(): array
    {
        return ['unpublished page' => [true], 'editor declines redirects' => [false]];
    }

    private function updatePage(array $fields): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [2 => $fields]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
    }
}
