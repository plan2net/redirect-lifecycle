<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Service\RedirectService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class LifecycleTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['plan2net/redirect-lifecycle'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Creation.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-01-01 00:00:00 UTC')));
    }

    public function testManualCreationUsesSiteTtlAndStoresGracePeriod(): void
    {
        $this->configureSite('main', 1, 'https://example.test/', 365);
        $record = $this->createRedirect(['pid' => 4]);

        self::assertSame(1, (int)$record['tx_redirectlifecycle_mode']);
        self::assertSame(1798761600, (int)$record['endtime']);
        self::assertSame(1806537600, (int)$record['tx_redirectlifecycle_delete_after']);
    }

    public function testCreationWithoutSiteUsesGlobalFallback(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '365', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect();
        self::assertSame(1, (int)$record['tx_redirectlifecycle_mode']);
        self::assertSame(1798761600, (int)$record['endtime']);
        self::assertSame(1806537600, (int)$record['tx_redirectlifecycle_delete_after']);
    }

    #[DataProvider('siteAssignments')]
    public function testSiteAssignmentPrecedence(array $fields, string $otherBase, int $expectedExpiry): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '10', 'cleanupGracePeriod' => '0'];
        $this->configureSite('main', 1, 'https://example.test/', 365);
        $this->configureSite('other', 3, $otherBase, 0);
        $record = $this->createRedirect($fields);
        self::assertSame($expectedExpiry, (int)$record['endtime']);
        self::assertSame($expectedExpiry, (int)$record['tx_redirectlifecycle_delete_after']);
    }

    public static function siteAssignments(): array
    {
        return [
            'unique host' => [['source_host' => 'example.test'], 'https://other.test/', 1798761600],
            'distinct port does not make host ambiguous' => [['source_host' => 'example.test'], 'https://example.test:8080/', 1798761600],
            'storage wins over host' => [['pid' => 4, 'source_host' => 'other.test'], 'https://other.test/', 1798761600],
            'site zero wins over global' => [['source_host' => 'other.test'], 'https://other.test/', 0],
            'shared host is ambiguous' => [['source_host' => 'example.test'], 'https://example.test/other/', 1768089600],
            'wildcard ignores target site' => [['target' => 'https://example.test/new'], 'https://other.test/', 1768089600],
        ];
    }

    #[DataProvider('portSiteAssignments')]
    public function testRestorationResolvesImportedHostWithPort(string $mainBase, string $otherBase, int $expectedExpiry): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '10', 'cleanupGracePeriod' => '0'];
        $this->configureSite('main', 1, $mainBase, 365);
        $this->configureSite('other', 3, $otherBase, 0);
        $record = $this->createRedirect();
        // Core's form evaluator rejects host:port; imported records can still contain it.
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->update(
            'sys_redirect', ['source_host' => 'example.test:8080'], ['uid' => $record['uid']],
        );
        $deleted = $this->commandRedirect($record, 'delete');
        $restored = $this->commandRedirect($deleted, 'undelete');
        self::assertSame('example.test:8080', $restored['source_host']);
        self::assertSame($expectedExpiry, (int)$restored['endtime']);
        self::assertSame($expectedExpiry, (int)$restored['tx_redirectlifecycle_delete_after']);
    }

    public static function portSiteAssignments(): array
    {
        return [
            'matching non-default port' => ['https://example.test:8080/', 'https://other.test/', 1798761600],
            'port selects site with zero TTL' => ['https://example.test/', 'https://example.test:8080/', 0],
            'shared host and port is ambiguous' => ['https://example.test:8080/', 'https://example.test:8080/other/', 1768089600],
        ];
    }

    #[DataProvider('creationModes')]
    public function testCreationRespectsProtectionAndExplicitModes(array $fields, int $mode, int $expiry, int $deletion): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '365', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect($fields);
        self::assertSame($mode, (int)$record['tx_redirectlifecycle_mode']);
        self::assertSame($expiry, (int)$record['endtime']);
        self::assertSame($deletion, (int)$record['tx_redirectlifecycle_delete_after']);
        self::assertSame((int)($fields['disabled'] ?? 0), (int)$record['disabled']);
    }

    public static function creationModes(): array
    {
        return [
            'protected membership' => [['protected' => 1], 1, 0, 0],
            'fixed expiry' => [['endtime' => 1790000000], 2, 1790000000, 0],
            'protected fixed expiry' => [['protected' => 1, 'endtime' => 1790000000], 2, 1790000000, 0],
            'fixed mode without a date' => [['tx_redirectlifecycle_mode' => 2], 0, 0, 0],
            'explicit opt out' => [['tx_redirectlifecycle_mode' => 0], 0, 0, 0],
            'disabled preserves creation dates' => [['disabled' => 1], 1, 1798761600, 1806537600],
        ];
    }

    public function testSlugChangeCreatesManagedRedirectWithCoreExpiry(): void
    {
        $this->configureSite('main', 1, 'https://example.test/', 365);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [2 => ['slug' => '/new']]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        $record = $this->get(RedirectService::class)->matchRedirect('example.test', '/old');
        self::assertNotNull($record);
        self::assertSame(1, (int)$record['tx_redirectlifecycle_mode']);
        self::assertSame(1798761600, (int)$record['endtime']);
        self::assertSame(1806537600, (int)$record['tx_redirectlifecycle_delete_after']);
    }

    #[DataProvider('excludedTypes')]
    public function testQrCodesAndShortUrlsAreNotManaged(string $type): void
    {
        if (!isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
            self::markTestSkipped('Redirect types were introduced in TYPO3 14.');
        }
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '365', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect(['redirect_type' => $type]);
        self::assertSame(0, (int)$record['tx_redirectlifecycle_mode']);
        self::assertSame(0, (int)$record['endtime']);
        self::assertSame(0, (int)$record['tx_redirectlifecycle_delete_after']);
    }

    public static function excludedTypes(): array
    {
        return [['qrcode'], ['short_url']];
    }

    #[DataProvider('invalidPeriods')]
    public function testInvalidPeriodsAreRejected(string $setting, mixed $value): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = array_replace(
            ['redirectTTL' => '365', 'cleanupGracePeriod' => '90'],
            [$setting => $value],
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->createRedirect();
    }

    public static function invalidPeriods(): array
    {
        return [
            'negative TTL' => ['redirectTTL', '-1'],
            'fractional TTL' => ['redirectTTL', '1.5'],
            'boolean TTL' => ['redirectTTL', true],
            'negative grace' => ['cleanupGracePeriod', '-1'],
            'text grace' => ['cleanupGracePeriod', 'ninety'],
        ];
    }

    public function testInvalidModeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createRedirect(['tx_redirectlifecycle_mode' => 'managed']);
    }

    public function testDefaultLifetimeIsUnlimited(): void
    {
        $record = $this->createRedirect();
        self::assertSame(1, (int)$record['tx_redirectlifecycle_mode']);
        self::assertSame(0, (int)$record['endtime']);
        self::assertSame(0, (int)$record['tx_redirectlifecycle_delete_after']);
    }

    public function testInitialLifetimeUsesCalendarDaysAcrossDaylightSavingChange(): void
    {
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-10-24 12:00:00 Europe/Vienna')));
        $this->configureSite('main', 1, 'https://example.test/', 2);
        $record = $this->createRedirect(['pid' => 4]);
        self::assertSame((new \DateTimeImmutable('2026-10-26 12:00:00 Europe/Vienna'))->getTimestamp(), (int)$record['endtime']);
        self::assertSame((new \DateTimeImmutable('2027-01-24 12:00:00 Europe/Vienna'))->getTimestamp(), (int)$record['tx_redirectlifecycle_delete_after']);
    }

    public function testUpdatesPreserveStoredDatesAfterConfigurationChange(): void
    {
        $this->configureSite('main', 1, 'https://example.test/', 365);
        $record = $this->createRedirect(['pid' => 4]);
        $this->configureSite('main', 1, 'https://example.test/', 0);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['sys_redirect' => [$record['uid'] => ['description' => 'Updated']]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        $updated = BackendUtility::getRecord('sys_redirect', $record['uid']);
        self::assertSame(1798761600, (int)$updated['endtime']);
        self::assertSame(1806537600, (int)$updated['tx_redirectlifecycle_delete_after']);
    }

    public function testChoosingFixedExpiryEndsManagementWithoutReactivation(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '365', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect(['disabled' => 1]);
        $updated = $this->updateRedirect($record, ['tx_redirectlifecycle_mode' => 2, 'endtime' => 1790000000]);
        self::assertSame(2, (int)$updated['tx_redirectlifecycle_mode']);
        self::assertSame(1790000000, (int)$updated['endtime']);
        self::assertSame(0, (int)$updated['tx_redirectlifecycle_delete_after']);
        self::assertSame(1, (int)$updated['disabled']);
    }

    public function testEndingManagementClearsManagedDatesButPreservesDisabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '365', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect(['disabled' => 1]);
        $updated = $this->updateRedirect($record, ['tx_redirectlifecycle_mode' => 0]);
        self::assertSame(0, (int)$updated['endtime']);
        self::assertSame(0, (int)$updated['tx_redirectlifecycle_delete_after']);
        self::assertSame(1, (int)$updated['disabled']);
    }

    public function testRemovingFixedExpiryDoesNotRestoreMembership(): void
    {
        $record = $this->createRedirect(['endtime' => 1790000000]);
        $updated = $this->updateRedirect($record, ['endtime' => 0]);
        self::assertSame(0, (int)$updated['tx_redirectlifecycle_mode']);
        self::assertSame(0, (int)$updated['endtime']);
        self::assertSame(0, (int)$updated['tx_redirectlifecycle_delete_after']);
        $updated = $this->updateRedirect($updated, ['protected' => 1]);
        $updated = $this->updateRedirect($updated, ['protected' => 0]);
        self::assertSame(0, (int)$updated['tx_redirectlifecycle_mode']);
    }

    public function testEndingManagementDoesNotRemoveFixedExpiry(): void
    {
        $record = $this->createRedirect(['endtime' => 1790000000]);
        $updated = $this->updateRedirect($record, ['tx_redirectlifecycle_mode' => 0]);
        self::assertSame(1790000000, (int)$updated['endtime']);
        self::assertSame(0, (int)$updated['tx_redirectlifecycle_delete_after']);
    }

    public function testProtectionPausesManagedDatesAndRemovingItRestartsCurrentLifetime(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '365', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect(['disabled' => 1]);
        $protected = $this->updateRedirect($record, ['protected' => 1]);
        self::assertSame(1, (int)$protected['tx_redirectlifecycle_mode']);
        self::assertSame(0, (int)$protected['endtime']);
        self::assertSame(0, (int)$protected['tx_redirectlifecycle_delete_after']);
        self::assertSame(1, (int)$protected['disabled']);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = '10';
        $updated = $this->updateRedirect($protected, ['protected' => 0]);
        self::assertSame(1768089600, (int)$updated['endtime']);
        self::assertSame(1775865600, (int)$updated['tx_redirectlifecycle_delete_after']);
        self::assertSame(1, (int)$updated['disabled']);
    }

    public function testExplicitEnrollmentStartsCurrentLifetimeAndDoesNotRestartOnOrdinarySave(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '365', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect(['tx_redirectlifecycle_mode' => 0]);
        $updated = $this->updateRedirect($record, ['tx_redirectlifecycle_mode' => 1]);
        self::assertSame(1798761600, (int)$updated['endtime']);
        self::assertSame(1806537600, (int)$updated['tx_redirectlifecycle_delete_after']);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = '10';
        $updated = $this->updateRedirect($updated, ['tx_redirectlifecycle_mode' => 1, 'description' => 'Ordinary save']);
        self::assertSame(1798761600, (int)$updated['endtime']);
        self::assertSame(1806537600, (int)$updated['tx_redirectlifecycle_delete_after']);
    }

    public function testManagedDatesCannotBeOverwrittenByOrdinaryEdits(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '365', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect();
        $updated = $this->updateRedirect($record, ['endtime' => 0, 'tx_redirectlifecycle_delete_after' => 1]);
        self::assertSame(1798761600, (int)$updated['endtime']);
        self::assertSame(1806537600, (int)$updated['tx_redirectlifecycle_delete_after']);
    }

    #[DataProvider('excludedTypes')]
    public function testExcludedTypeRetainsExpiryAsFixedAndSwitchingBackDoesNotEnroll(string $type): void
    {
        if (!isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
            self::markTestSkipped('Redirect types were introduced in TYPO3 14.');
        }
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '365', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect();
        $updated = $this->updateRedirect($record, ['redirect_type' => $type]);
        self::assertSame(2, (int)$updated['tx_redirectlifecycle_mode']);
        self::assertSame(1798761600, (int)$updated['endtime']);
        self::assertSame(0, (int)$updated['tx_redirectlifecycle_delete_after']);
        $updated = $this->updateRedirect($updated, ['redirect_type' => 'default']);
        self::assertSame(2, (int)$updated['tx_redirectlifecycle_mode']);
        self::assertSame(1798761600, (int)$updated['endtime']);
    }

    public function testBackendFormMakesManagedExpiryReadOnlyAndFixedExpiryEditable(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '365', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect();
        $request = new ServerRequest('https://example.test/typo3/', 'GET');
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $compiler = GeneralUtility::makeInstance(FormDataCompiler::class);
        $group = GeneralUtility::makeInstance(TcaDatabaseRecord::class);
        $form = $compiler->compile([
            'request' => $request, 'tableName' => 'sys_redirect',
            'command' => 'edit', 'vanillaUid' => $record['uid'],
        ], $group);
        self::assertTrue($form['processedTca']['columns']['endtime']['config']['readOnly']);
        self::assertSame('select', $form['processedTca']['columns']['tx_redirectlifecycle_mode']['config']['type']);
        $form = $compiler->compile([
            'request' => $request, 'tableName' => 'sys_redirect',
            'command' => 'edit', 'vanillaUid' => $record['uid'],
            'overrideValues' => ['tx_redirectlifecycle_mode' => 2],
        ], $group);
        self::assertFalse($form['processedTca']['columns']['endtime']['config']['readOnly']);
        $form = $compiler->compile([
            'request' => $request, 'tableName' => 'sys_redirect',
            'command' => 'new', 'vanillaUid' => 0,
        ], $group);
        self::assertSame(1, (int)$form['databaseRow']['tx_redirectlifecycle_mode'][0]);
        $form = $compiler->compile([
            'request' => $request, 'tableName' => 'sys_redirect',
            'command' => 'new', 'vanillaUid' => 0,
            'defaultValues' => ['sys_redirect' => ['tx_redirectlifecycle_mode' => 0]],
        ], $group);
        self::assertSame(0, (int)$form['databaseRow']['tx_redirectlifecycle_mode'][0]);
    }

    public function testBackendFormDisplaysAbsentManagedDatesAsEmpty(): void
    {
        $record = $this->createRedirect(['protected' => 1]);
        $request = new ServerRequest('https://example.test/typo3/', 'GET');
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $form = GeneralUtility::makeInstance(FormDataCompiler::class)->compile([
            'request' => $request, 'tableName' => 'sys_redirect',
            'command' => 'edit', 'vanillaUid' => $record['uid'],
        ], GeneralUtility::makeInstance(TcaDatabaseRecord::class));
        // TYPO3 14 normalizes some empty datetime values to null; neither representation is an epoch date.
        self::assertSame('', (string)$form['databaseRow']['endtime']);
        self::assertSame('', (string)$form['databaseRow']['tx_redirectlifecycle_delete_after']);
        foreach (['endtime', 'tx_redirectlifecycle_delete_after'] as $field) {
            $form['renderType'] = 'singleFieldContainer';
            $form['fieldName'] = $field;
            $html = GeneralUtility::makeInstance(NodeFactory::class)->create($form)->render()['html'];
            self::assertStringContainsString('value=""', $html);
            self::assertStringNotContainsString('1970', $html);
        }
        self::assertSame(0, (int)BackendUtility::getRecord('sys_redirect', $record['uid'])['endtime']);
    }

    public function testOrdinaryEditorCannotBypassLifecycleFieldPermissions(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '365', 'cleanupGracePeriod' => '90'];
        $user = $this->setUpBackendUser(2);
        self::assertFalse($user->isAdmin());
        self::assertTrue($user->check('tables_modify', 'sys_redirect'));
        $record = BackendUtility::getRecord('sys_redirect', 100);
        $updated = $this->updateRedirect($record, [
            'source_host' => '*', 'source_path' => '/changed',
            'target' => $record['target'], 'tx_redirectlifecycle_mode' => 1,
        ]);
        self::assertSame('/changed', $updated['source_path']);
        self::assertSame(0, (int)$updated['tx_redirectlifecycle_mode']);
        self::assertSame(0, (int)$updated['endtime']);
    }

    public function testAutomaticCreationRemainsManagedForEditorsWithoutLifecycleFieldPermission(): void
    {
        $this->configureSite('main', 1, 'https://example.test/', 365);
        $this->setUpBackendUser(2);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [2 => ['slug' => '/new']]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        $record = $this->get(RedirectService::class)->matchRedirect('example.test', '/old');
        self::assertNotNull($record);
        self::assertSame(1, (int)$record['tx_redirectlifecycle_mode']);
        self::assertSame(1798761600, (int)$record['endtime']);
        self::assertSame(1806537600, (int)$record['tx_redirectlifecycle_delete_after']);
    }

    public function testManualReactivationRestartsCurrentLifetimeButFixedExpiryRemainsUnchanged(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '365', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect(['disabled' => 1]);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = '10';
        $updated = $this->updateRedirect($record, ['disabled' => 0]);
        self::assertSame(1768089600, (int)$updated['endtime']);
        self::assertSame(1775865600, (int)$updated['tx_redirectlifecycle_delete_after']);
        $fixed = $this->createRedirect(['disabled' => 1, 'endtime' => 1790000000]);
        $updated = $this->updateRedirect($fixed, ['disabled' => 0]);
        self::assertSame(1790000000, (int)$updated['endtime']);
        self::assertSame(2, (int)$updated['tx_redirectlifecycle_mode']);
    }

    public function testFixedExpiryChangeIsEffectiveWithPopulatedRedirectCache(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '365', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect(['source_host' => 'example.test']);
        $service = $this->get(RedirectService::class);
        self::assertNotNull($service->matchRedirect('example.test', '/old'));
        $this->updateRedirect($record, ['tx_redirectlifecycle_mode' => 2, 'endtime' => 1735689600]);
        self::assertNull($service->matchRedirect('example.test', '/old'));
    }

    public function testCleanupDeletedManagedRedirectRestartsLifetimeOnNativeRestore(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '10', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect();
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-04-11 UTC')));
        $cleanup = new CommandTester($this->get(CommandRegistry::class)->getCommandByIdentifier('redirect-lifecycle:cleanup'));
        self::assertSame(0, $cleanup->execute([]));
        self::assertSame(1, (int)BackendUtility::getRecord('sys_redirect', $record['uid'], '*', '', false)['deleted']);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '5', 'cleanupGracePeriod' => '7'];
        $restored = $this->commandRedirect($record, 'undelete');
        self::assertSame(0, (int)$restored['deleted']);
        self::assertSame(1, (int)$restored['tx_redirectlifecycle_mode']);
        self::assertSame((new \DateTimeImmutable('2026-04-16 UTC'))->getTimestamp(), (int)$restored['endtime']);
        self::assertSame((new \DateTimeImmutable('2026-04-23 UTC'))->getTimestamp(), (int)$restored['tx_redirectlifecycle_delete_after']);
        self::assertSame(0, $cleanup->execute([]));
        self::assertSame($restored, BackendUtility::getRecord('sys_redirect', $record['uid']));
    }

    #[DataProvider('restoredStates')]
    public function testRestorationPreservesFlagsAndRespectsLifetimeMode(array $fields, string $ttl, ?string $expiry, ?string $deletion): void
    {
        if (isset($fields['redirect_type']) && !isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
            self::markTestSkipped('Redirect types were introduced in TYPO3 14.');
        }
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '200', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect($fields);
        $deleted = $this->commandRedirect($record, 'delete');
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-04-11 UTC')));
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => $ttl, 'cleanupGracePeriod' => '7'];
        $restored = $this->commandRedirect($record, 'undelete');
        self::assertSame(0, (int)$restored['deleted']);
        self::assertSame($record['protected'], $restored['protected']);
        self::assertSame($record['disabled'], $restored['disabled']);
        self::assertSame($record['tx_redirectlifecycle_mode'], $restored['tx_redirectlifecycle_mode']);
        self::assertSame($expiry === null ? (int)$deleted['endtime'] : (new \DateTimeImmutable($expiry . ' UTC'))->getTimestamp(), (int)$restored['endtime']);
        self::assertSame($deletion === null ? (int)$deleted['tx_redirectlifecycle_delete_after'] : (new \DateTimeImmutable($deletion . ' UTC'))->getTimestamp(), (int)$restored['tx_redirectlifecycle_delete_after']);
    }

    public static function restoredStates(): array
    {
        return [
            'restart may shorten lifetime' => [[], '5', '2026-04-16', '2026-04-23'],
            'disabled managed remains disabled' => [['disabled' => 1], '5', '2026-04-16', '2026-04-23'],
            'protected managed remains unlimited' => [['protected' => 1], '5', '1970-01-01', '1970-01-01'],
            'protected and disabled' => [['protected' => 1, 'disabled' => 1], '5', '1970-01-01', '1970-01-01'],
            'current TTL zero' => [[], '0', '1970-01-01', '1970-01-01'],
            'fixed expiry unchanged' => [['endtime' => 1790000000], '5', null, null],
            'protected fixed expiry unchanged' => [['endtime' => 1790000000, 'protected' => 1], '5', null, null],
            'unmanaged stays unmanaged' => [['tx_redirectlifecycle_mode' => 0], '5', null, null],
            'QR code excluded' => [['redirect_type' => 'qrcode', 'endtime' => 1790000000], '5', null, null],
            'short URL excluded' => [['redirect_type' => 'short_url', 'endtime' => 1790000000], '5', null, null],
        ];
    }

    public function testRepeatedUndeleteDoesNotRestartAnAlreadyRestoredRecord(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '10', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect();
        $this->commandRedirect($record, 'delete');
        $restored = $this->commandRedirect($record, 'undelete');
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-04-11 UTC')));
        self::assertSame($restored, $this->commandRedirect($record, 'undelete'));
    }

    #[DataProvider('restorationSiteTtls')]
    public function testRestorationUsesCurrentSiteTtlIncludingZero(int $ttl, string $expiry, string $deletion): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '5', 'cleanupGracePeriod' => '7'];
        $this->configureSite('main', 1, 'https://example.test/', 200);
        $record = $this->createRedirect(['pid' => 4]);
        $this->commandRedirect($record, 'delete');
        $this->configureSite('main', 1, 'https://example.test/', $ttl);
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-04-11 UTC')));
        $restored = $this->commandRedirect($record, 'undelete');
        self::assertSame((new \DateTimeImmutable($expiry . ' UTC'))->getTimestamp(), (int)$restored['endtime']);
        self::assertSame((new \DateTimeImmutable($deletion . ' UTC'))->getTimestamp(), (int)$restored['tx_redirectlifecycle_delete_after']);
    }

    public static function restorationSiteTtls(): array
    {
        return [[30, '2026-05-11', '2026-05-18'], [0, '1970-01-01', '1970-01-01']];
    }

    public function testRestorationRefreshesPopulatedHostCacheAndWritesNativeHistoryOnce(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '10', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect(['source_host' => 'example.test']);
        $GLOBALS['SIM_ACCESS_TIME'] = (new \DateTimeImmutable('2026-01-01 UTC'))->getTimestamp();
        $service = $this->get(RedirectService::class);
        self::assertNotNull($service->matchRedirect('example.test', '/old'));
        $this->commandRedirect($record, 'delete');
        self::assertNull($service->matchRedirect('example.test', '/old'));
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-04-11 UTC')));
        $GLOBALS['SIM_ACCESS_TIME'] = (new \DateTimeImmutable('2026-04-11 UTC'))->getTimestamp();
        $restored = $this->commandRedirect($record, 'undelete');
        $matched = $service->matchRedirect('example.test', '/old');
        self::assertNotNull($matched);
        self::assertSame((int)$restored['endtime'], (int)$matched['endtime']);
        $history = $this->getConnectionPool()->getConnectionForTable('sys_history')->select(
            ['uid'], 'sys_history', ['tablename' => 'sys_redirect', 'recuid' => $record['uid'], 'actiontype' => 5],
        )->fetchFirstColumn();
        self::assertCount(1, $history);
    }

    #[DataProvider('invalidRestorationSettings')]
    public function testInvalidRestoreConfigurationRollsBackNativeRestorationAndCanBeRetried(string $setting, mixed $value): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '10', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect(['source_host' => 'example.test']);
        $deleted = $this->commandRedirect($record, 'delete');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'][$setting] = $value;
        $failed = false;
        try {
            $this->commandRedirect($record, 'undelete');
        } catch (\InvalidArgumentException) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertSame($deleted, BackendUtility::getRecord('sys_redirect', $record['uid'], '*', '', false));
        self::assertNull($this->get(RedirectService::class)->matchRedirect('example.test', '/old'));
        $history = $this->getConnectionPool()->getConnectionForTable('sys_history')->select(
            ['uid'], 'sys_history', ['tablename' => 'sys_redirect', 'recuid' => $record['uid'], 'actiontype' => 5],
        )->fetchFirstColumn();
        self::assertSame([], $history);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '5', 'cleanupGracePeriod' => '90'];
        self::assertSame(0, (int)$this->commandRedirect($record, 'undelete')['deleted']);
    }

    public static function invalidRestorationSettings(): array
    {
        return [
            'negative TTL' => ['redirectTTL', '-1'],
            'fractional grace' => ['cleanupGracePeriod', '1.5'],
            'expiry outside Core timestamp range' => ['redirectTTL', '50000'],
        ];
    }

    public function testRejectedNativeRestoreLeavesManagedDatesUntouched(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '10', 'cleanupGracePeriod' => '90'];
        $record = $this->createRedirect(['pid' => 4]);
        $deleted = $this->commandRedirect($record, 'delete');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['pages' => [4 => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['sys_redirect' => [$record['uid'] => ['undelete' => 1]]]);
        $dataHandler->process_cmdmap();
        self::assertNotSame([], $dataHandler->errorLog);
        self::assertSame($deleted, BackendUtility::getRecord('sys_redirect', $record['uid'], '*', '', false));
    }

    private function commandRedirect(array $record, string $command): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['sys_redirect' => [$record['uid'] => [$command => 1]]]);
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog);
        return BackendUtility::getRecord('sys_redirect', $record['uid'], '*', '', false);
    }

    private function updateRedirect(array $record, array $fields): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['sys_redirect' => [$record['uid'] => $fields]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        return BackendUtility::getRecord('sys_redirect', $record['uid']);
    }

    private function configureSite(string $identifier, int $rootPageId, string $base, int $ttl): void
    {
        $writer = class_exists(SiteWriter::class) ? $this->get(SiteWriter::class) : $this->get(SiteConfiguration::class);
        $writer->write($identifier, [
            'rootPageId' => $rootPageId,
            'base' => $base,
            'languages' => [[
                'languageId' => 0, 'title' => 'English', 'enabled' => true,
                'base' => '/', 'locale' => 'en_US.UTF-8', 'iso-639-1' => 'en',
            ]],
            'settings' => ['redirects' => ['redirectTTL' => $ttl]],
        ]);
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    private function createRedirect(array $fields = []): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['sys_redirect' => ['NEWredirect' => array_replace([
            'pid' => 0, 'source_host' => '*', 'source_path' => '/old',
            'target' => 'https://target.test/new',
        ], $fields)]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        self::assertArrayHasKey('NEWredirect', $dataHandler->substNEWwithIDs);
        return BackendUtility::getRecord('sys_redirect', $dataHandler->substNEWwithIDs['NEWredirect']);
    }
}
