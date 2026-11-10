<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use Plan2net\RedirectLifecycle\Tests\Functional\Support\TestClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Service\RedirectService;
use TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ActionTest extends FunctionalTestCase
{
    use TestClock;

    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['plan2net/redirect-lifecycle'];
    // Retain cache entries across time jumps so cache misses cannot hide stale data.
    protected array $configurationToUseInTestInstance = [
        'SYS' => ['caching' => ['cacheConfigurations' => [
            'pages' => ['backend' => Typo3DatabaseBackend::class, 'options' => ['defaultLifetime' => 0]],
        ]]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Creation.csv');
        $user = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
        $this->setTime('2026-04-11 UTC');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '5', 'cleanupGracePeriod' => '7'];
    }

    public function testAdoptionCanRunAsSchedulerTaskAndStillRequiresExecute(): void
    {
        self::assertArrayHasKey('redirect-lifecycle:adopt', iterator_to_array($this->get(CommandRegistry::class)->getSchedulableCommands()));
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->insert('sys_redirect', [
            'uid' => 101, 'source_host' => '*', 'source_path' => '/fixed-expiry', 'target' => 'https://target.test/', 'endtime' => 2000000000,
        ]);
        $fixedBefore = $this->record(101);
        $before = $this->record(100);
        $task = GeneralUtility::makeInstance(ExecuteSchedulableCommandTask::class);
        if (method_exists($task, 'setTaskType')) {
            $task->setTaskType('redirect-lifecycle:adopt');
        } else {
            $task->setCommandIdentifier('redirect-lifecycle:adopt');
        }
        self::assertTrue($task->execute());
        self::assertSame($before, $this->record(100));
        self::assertSame($fixedBefore, $this->record(101));

        if (method_exists($task, 'setTaskType')) {
            $task->setTaskParameters(['options' => ['execute' => true]]);
        } else {
            $task->setOptions(['execute' => true]);
            $task->setOptionValues(['execute' => true]);
        }
        self::assertTrue($task->execute());
        $adopted = $this->record(100);
        self::assertSame(1, (int)$adopted['tx_redirectlifecycle_mode']);
        self::assertSame((new \DateTimeImmutable('2026-04-16 UTC'))->getTimestamp(), (int)$adopted['endtime']);
        self::assertSame((new \DateTimeImmutable('2026-04-23 UTC'))->getTimestamp(), (int)$adopted['tx_redirectlifecycle_delete_after']);
        self::assertSame(2, (int)$this->record(101)['tx_redirectlifecycle_mode']);
        self::assertSame($fixedBefore['endtime'], $this->record(101)['endtime']);
    }

    public function testAdoptionDefaultsToPreviewWithoutChangingLegacyRecord(): void
    {
        $before = $this->record(100);
        $tester = $this->command('adopt');
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('/legacy', $tester->getDisplay());
        self::assertStringContainsString('Eligible', $tester->getDisplay());
        self::assertSame($before, $this->record(100));
    }

    public function testExplicitAdoptionStartsFullCurrentLifetimeAndIsIdempotent(): void
    {
        self::assertSame(0, $this->command('adopt')->execute(['--execute' => true]));
        $adopted = $this->record(100);
        self::assertSame(1, (int)$adopted['tx_redirectlifecycle_mode']);
        self::assertSame((new \DateTimeImmutable('2026-04-16 UTC'))->getTimestamp(), (int)$adopted['endtime']);
        self::assertSame((new \DateTimeImmutable('2026-04-23 UTC'))->getTimestamp(), (int)$adopted['tx_redirectlifecycle_delete_after']);
        $this->setTime('2026-05-01 UTC');
        self::assertSame(0, $this->command('adopt')->execute(['--execute' => true]));
        self::assertSame($adopted, $this->record(100));
    }

    public function testExplicitRenewalRestartsExpiredManagedLifetime(): void
    {
        self::assertSame(0, $this->command('adopt')->execute(['uids' => ['100'], '--execute' => true]));
        $before = $this->record(100);
        $this->setTime('2026-05-01 UTC');
        self::assertSame(0, $this->command('renew')->execute(['uids' => ['100']]));
        self::assertSame($before, $this->record(100));
        self::assertSame(0, $this->command('renew')->execute(['uids' => ['100'], '--execute' => true]));
        $renewed = $this->record(100);
        self::assertSame((new \DateTimeImmutable('2026-05-06 UTC'))->getTimestamp(), (int)$renewed['endtime']);
        self::assertSame((new \DateTimeImmutable('2026-05-13 UTC'))->getTimestamp(), (int)$renewed['tx_redirectlifecycle_delete_after']);
    }

    #[DataProvider('legacyFixedExpiries')]
    public function testAdoptionMarksLegacyExpiryAsFixedWithoutChangingDates(array $fields): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->update('sys_redirect', $fields, ['uid' => 100]);
        $before = $this->record(100);
        $tester = $this->command('adopt');
        self::assertSame(0, $tester->execute(['uids' => ['100']]));
        self::assertSame($before, $this->record(100));
        self::assertStringContainsString('Mark as fixed expiry', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['uids' => ['100'], '--execute' => true]));
        $after = $this->record(100);
        self::assertSame(2, (int)$after['tx_redirectlifecycle_mode']);
        foreach (['endtime', 'starttime', 'protected', 'disabled', 'source_host', 'source_path', 'target'] as $field) {
            self::assertSame($before[$field], $after[$field], $field);
        }
        self::assertSame(0, (int)$after['tx_redirectlifecycle_delete_after']);
        self::assertStringContainsString('1 redirects updated.', $tester->getDisplay());
        $this->setTime('2026-05-01 UTC');
        self::assertSame(0, $tester->execute(['uids' => ['100'], '--execute' => true]));
        self::assertSame($after, $this->record(100));
    }

    public static function legacyFixedExpiries(): array
    {
        return [
            'future expiry' => [['endtime' => 2000000000]],
            'expired' => [['endtime' => 1735689600]],
            'protected' => [['endtime' => 2000000000, 'protected' => 1]],
            'disabled' => [['endtime' => 2000000000, 'disabled' => 1]],
            'not yet active' => [['endtime' => 2000000000, 'starttime' => 1900000000]],
            'protected disabled and not yet active' => [['endtime' => 2000000000, 'protected' => 1, 'disabled' => 1, 'starttime' => 1900000000]],
        ];
    }

    #[DataProvider('adoptionExclusions')]
    public function testAdoptionReportsAndPreservesSkippedRecords(array $fields, string $reason): void
    {
        if (isset($fields['redirect_type']) && !isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
            self::markTestSkipped('Redirect types were introduced in TYPO3 14.');
        }
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->update('sys_redirect', $fields, ['uid' => 100]);
        $before = $this->record(100);
        $tester = $this->command('adopt');
        self::assertSame(0, $tester->execute(['uids' => ['100']]));
        self::assertStringContainsString($reason, $tester->getDisplay());
        $this->executeReadOnly($tester);
        self::assertSame($before, $this->record(100));
    }

    public static function adoptionExclusions(): array
    {
        return [
            'protected' => [['protected' => 1], 'Protected'],
            'disabled' => [['disabled' => 1], 'Manually disabled'],
            'deleted' => [['deleted' => 1], 'Deleted'],
            'deleted legacy expiry' => [['endtime' => 2000000000, 'deleted' => 1], 'Deleted'],
            'fixed mode without date' => [['tx_redirectlifecycle_mode' => 2], 'Fixed expiry'],
            'already managed' => [['tx_redirectlifecycle_mode' => 1, 'endtime' => 2000000000, 'tx_redirectlifecycle_delete_after' => 2000000000], 'Already managed'],
            'not yet active' => [['starttime' => 2000000000], 'Not yet active'],
            'QR code' => [['redirect_type' => 'qrcode'], 'Excluded redirect type'],
            'QR code with expiry' => [['redirect_type' => 'qrcode', 'endtime' => 2000000000], 'Excluded redirect type'],
            'short URL' => [['redirect_type' => 'short_url'], 'Excluded redirect type'],
        ];
    }

    public function testAdoptionStillRechecksEligibilityAfterAcquiringTheLock(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        // Simulate protection being added between the fresh precheck and the locked read.
        $connection->executeStatement('CREATE TEMP TRIGGER protect_on_lock AFTER UPDATE OF uid ON sys_redirect
            WHEN NEW.uid = 100 BEGIN UPDATE sys_redirect SET protected = 1 WHERE uid = 100; END');
        try {
            $tester = $this->command('adopt');
            self::assertSame(0, $tester->execute(['uids' => ['100'], '--execute' => true]));
        } finally {
            $connection->executeStatement('DROP TRIGGER protect_on_lock');
        }
        self::assertStringContainsString('Protected', $tester->getDisplay());
        self::assertStringContainsString('0 redirects updated.', $tester->getDisplay());
        self::assertSame(1, (int)$this->record(100)['protected']);
        self::assertSame(0, (int)$this->record(100)['tx_redirectlifecycle_mode']);
    }

    public function testAdoptionReconsidersAnEarlierIneligiblePreview(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        $connection->update('sys_redirect', ['protected' => 1], ['uid' => 100]);
        $lifecycle = $this->get(RedirectLifecycle::class);
        self::assertSame('protected', $lifecycle->actionCandidates([100], true)[0]['reason']);
        $connection->update('sys_redirect', ['protected' => 0], ['uid' => 100]);
        self::assertNull($lifecycle->adopt(100));
        self::assertSame(1, (int)$this->record(100)['tx_redirectlifecycle_mode']);
        self::assertSame((new \DateTimeImmutable('2026-04-16 UTC'))->getTimestamp(), (int)$this->record(100)['endtime']);
    }

    #[DataProvider('renewalStates')]
    public function testRenewalReplacesDatesAndPreservesManagementAndActivation(array $fields, string $initialTtl, string $currentTtl, bool $unlimited): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = $initialTtl;
        $this->command('adopt')->execute(['uids' => ['100'], '--execute' => true]);
        if ($fields !== []) {
            $this->getConnectionPool()->getConnectionForTable('sys_redirect')->update('sys_redirect', $fields, ['uid' => 100]);
        }
        $before = $this->record(100);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = $currentTtl;
        $this->setTime('2026-05-01 UTC');
        $tester = $this->command('renew');
        self::assertSame(0, $tester->execute(['uids' => ['100', '100'], '--execute' => true]));
        self::assertStringContainsString('1 redirects updated.', $tester->getDisplay());
        $after = $this->record(100);
        self::assertSame(1, (int)$after['tx_redirectlifecycle_mode']);
        self::assertSame($before['protected'], $after['protected']);
        self::assertSame($before['disabled'], $after['disabled']);
        self::assertSame($before['starttime'], $after['starttime']);
        self::assertSame($unlimited ? 0 : (new \DateTimeImmutable('2026-05-06 UTC'))->getTimestamp(), (int)$after['endtime']);
        self::assertSame($unlimited ? 0 : (new \DateTimeImmutable('2026-05-13 UTC'))->getTimestamp(), (int)$after['tx_redirectlifecycle_delete_after']);
    }

    public static function renewalStates(): array
    {
        return [
            'may shorten long lifetime' => [[], '200', '5', false],
            'zero current TTL' => [[], '200', '0', true],
            'previously unlimited' => [[], '0', '5', false],
            'disabled managed' => [['disabled' => 1], '5', '5', false],
            'protected managed' => [['protected' => 1], '5', '5', true],
            'protected and disabled managed' => [['protected' => 1, 'disabled' => 1], '5', '5', true],
            'future activation preserved' => [['starttime' => 2000000000], '5', '5', false],
        ];
    }

    public function testAdoptionWithZeroTtlEstablishesUnlimitedMembership(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = '0';
        self::assertSame(0, $this->command('adopt')->execute(['uids' => ['100'], '--execute' => true]));
        $after = $this->record(100);
        self::assertSame(1, (int)$after['tx_redirectlifecycle_mode']);
        self::assertSame(0, (int)$after['endtime']);
        self::assertSame(0, (int)$after['tx_redirectlifecycle_delete_after']);
    }

    #[DataProvider('renewalExclusions')]
    public function testRenewalDoesNotEnrollOrChangeIneligibleRecords(array $fields, string $reason): void
    {
        if (isset($fields['redirect_type']) && !isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
            self::markTestSkipped('Redirect types were introduced in TYPO3 14.');
        }
        if ($fields !== []) {
            $this->getConnectionPool()->getConnectionForTable('sys_redirect')->update('sys_redirect', $fields, ['uid' => 100]);
        }
        $before = $this->record(100);
        $tester = $this->command('renew');
        $this->executeReadOnly($tester);
        self::assertStringContainsString($reason, $tester->getDisplay());
        self::assertSame($before, $this->record(100));
    }

    /** Skipped records must not open a transaction or write, so execution succeeds without write access. */
    private function executeReadOnly(CommandTester $tester): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        $connection->executeStatement('PRAGMA query_only = ON');
        try {
            self::assertSame(0, $tester->execute(['uids' => ['100'], '--execute' => true]));
            self::assertSame(0, $connection->getTransactionNestingLevel());
        } finally {
            $connection->executeStatement('PRAGMA query_only = OFF');
        }
    }

    public static function renewalExclusions(): array
    {
        return [
            'unmanaged' => [[], 'Unmanaged'],
            'fixed mode' => [['tx_redirectlifecycle_mode' => 2, 'endtime' => 2000000000], 'Fixed expiry'],
            'legacy fixed' => [['endtime' => 2000000000], 'Fixed expiry'],
            'deleted managed' => [['deleted' => 1, 'tx_redirectlifecycle_mode' => 1], 'Deleted'],
            'managed QR code' => [['redirect_type' => 'qrcode', 'tx_redirectlifecycle_mode' => 1], 'Excluded redirect type'],
            'managed short URL' => [['redirect_type' => 'short_url', 'tx_redirectlifecycle_mode' => 1], 'Excluded redirect type'],
        ];
    }

    #[DataProvider('invalidUids')]
    public function testInvalidUidIsRejectedBeforeAnyMutation(string $uid): void
    {
        $before = $this->record(100);
        $failed = false;
        try {
            $this->command('adopt')->execute(['uids' => ['100', $uid], '--execute' => true]);
        } catch (\InvalidArgumentException) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertSame($before, $this->record(100));
    }

    public static function invalidUids(): array
    {
        return [['0'], ['-1'], ['1.5'], ['invalid']];
    }

    public function testMissingUidIsReportedAndRenewalIsNotSchedulable(): void
    {
        $tester = $this->command('renew');
        self::assertSame(0, $tester->execute(['uids' => ['999'], '--execute' => true]));
        self::assertStringContainsString('Record missing', $tester->getDisplay());
        $commands = iterator_to_array($this->get(CommandRegistry::class)->getSchedulableCommands());
        self::assertArrayHasKey('redirect-lifecycle:cleanup', $commands);
        self::assertArrayNotHasKey('redirect-lifecycle:renew', $commands);
    }

    public function testAdoptionIgnoresAgeAndLastHitAndActionsRefreshPopulatedCache(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->update('sys_redirect', ['createdon' => 1, 'lasthiton' => 1, 'hitcount' => 12], ['uid' => 100]);
        $service = $this->get(RedirectService::class);
        self::assertNotNull($service->matchRedirect('example.test', '/legacy'));
        self::assertTrue($this->get(CacheManager::class)->getCache('pages')->has('redirects_' . sha1('example.test')));
        self::assertSame(0, $this->command('adopt')->execute(['uids' => ['100'], '--execute' => true]));
        $matched = $service->matchRedirect('example.test', '/legacy');
        self::assertNotNull($matched);
        self::assertSame((new \DateTimeImmutable('2026-04-16 UTC'))->getTimestamp(), (int)$matched['endtime']);
        self::assertSame(12, (int)$this->record(100)['hitcount']);
        $this->setTime('2026-05-01 UTC');
        self::assertNull($service->matchRedirect('example.test', '/legacy'));
        self::assertTrue($this->get(CacheManager::class)->getCache('pages')->has('redirects_' . sha1('example.test')));
        self::assertSame(0, $this->command('renew')->execute(['uids' => ['100'], '--execute' => true]));
        $matched = $service->matchRedirect('example.test', '/legacy');
        self::assertNotNull($matched);
        self::assertSame((new \DateTimeImmutable('2026-05-06 UTC'))->getTimestamp(), (int)$matched['endtime']);
    }

    #[DataProvider('actionNames')]
    public function testInvalidConfigurationRollsBackAction(string $action): void
    {
        if ($action === 'renew') {
            $this->command('adopt')->execute(['uids' => ['100'], '--execute' => true]);
        }
        $before = $this->record(100);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = '-1';
        $failed = false;
        try {
            $this->command($action)->execute(['uids' => ['100'], '--execute' => true]);
        } catch (\InvalidArgumentException) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertSame($before, $this->record(100));
    }

    public static function actionNames(): array
    {
        return [['adopt'], ['renew']];
    }

    #[DataProvider('adoptionPermissionStates')]
    public function testPreviewDoesNotAuthenticateCliUserAndExecuteKeepsFieldPermissions(array $fields): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->update('sys_redirect', $fields, ['uid' => 100]);
        $before = $this->record(100);
        // Native session authentication uses wall-clock time, independently of lifecycle test time.
        $GLOBALS['EXEC_TIME'] = time();
        $this->setUpBackendUser(2);
        $this->setTime('2026-04-11 UTC');
        $failed = false;
        try {
            $this->command('adopt')->execute(['uids' => ['100'], '--execute' => true]);
        } catch (\RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertSame($before, $this->record(100));
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        self::assertSame(0, $this->command('adopt')->execute([]));
        self::assertSame(0, $this->command('renew')->execute(['uids' => ['100']]));
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
        self::assertSame($before, $this->record(100));
    }

    public static function adoptionPermissionStates(): array
    {
        return [
            'enrollment' => [['endtime' => 0]],
            'fixed classification' => [['endtime' => 2000000000]],
        ];
    }

    public function testActionsUseCurrentStorageSiteTtlIncludingAuthoritativeZero(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->update('sys_redirect', ['pid' => 4], ['uid' => 100]);
        $writer = class_exists(SiteWriter::class) ? $this->get(SiteWriter::class) : $this->get(SiteConfiguration::class);
        $configuration = [
            'rootPageId' => 1, 'base' => 'https://example.test/',
            'languages' => [[
                'languageId' => 0, 'title' => 'English', 'enabled' => true, 'base' => '/',
                'locale' => 'en_US.UTF-8', 'iso-639-1' => 'en',
            ]],
            'settings' => ['redirects' => ['redirectTTL' => 30]],
        ];
        $writer->write('main', $configuration);
        $this->get(SiteFinder::class)->getAllSites(false);
        self::assertSame(0, $this->command('adopt')->execute(['uids' => ['100'], '--execute' => true]));
        self::assertSame((new \DateTimeImmutable('2026-05-11 UTC'))->getTimestamp(), (int)$this->record(100)['endtime']);
        $configuration['settings']['redirects']['redirectTTL'] = 0;
        $writer->write('main', $configuration);
        $this->get(SiteFinder::class)->getAllSites(false);
        self::assertSame(0, $this->command('renew')->execute(['uids' => ['100'], '--execute' => true]));
        self::assertSame(0, (int)$this->record(100)['endtime']);
        self::assertSame(0, (int)$this->record(100)['tx_redirectlifecycle_delete_after']);
    }

    public function testRenewalMarkerCannotBypassFieldPermissions(): void
    {
        $this->command('adopt')->execute(['uids' => ['100'], '--execute' => true]);
        $before = $this->record(100);
        // Native session authentication uses wall-clock time, independently of lifecycle test time.
        $GLOBALS['EXEC_TIME'] = time();
        $this->setUpBackendUser(2);
        $this->setTime('2026-04-11 UTC');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['sys_redirect' => [100 => [
            'tx_redirectlifecycle_mode' => 1, 'source_host' => '*',
            'target' => $before['target'], 'source_path' => '/changed',
        ]]], []);
        $dataHandler->setCorrelationId($dataHandler->getCorrelationId()->withAspects(RedirectLifecycle::RENEWAL_ASPECT));
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = '200';
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        self::assertSame('/changed', $this->record(100)['source_path']);
        self::assertSame($before['endtime'], $this->record(100)['endtime']);
        self::assertSame($before['tx_redirectlifecycle_delete_after'], $this->record(100)['tx_redirectlifecycle_delete_after']);
    }

    #[DataProvider('changedCandidates')]
    public function testActionsRecheckChangedCandidatesAfterPreview(string $action, array $fields, string $reason, array $initialFields = []): void
    {
        if ($initialFields !== []) {
            $this->getConnectionPool()->getConnectionForTable('sys_redirect')->update('sys_redirect', $initialFields, ['uid' => 100]);
        }
        if ($action === 'renew') {
            $this->command('adopt')->execute(['uids' => ['100'], '--execute' => true]);
        }
        $current = null;
        $change = function () use ($fields, &$current): void {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start(['sys_redirect' => [100 => $fields]], []);
            $dataHandler->process_datamap();
            self::assertSame([], $dataHandler->errorLog);
            $current = $this->record(100);
        };
        $output = new class($change) extends BufferedOutput {
            private bool $changed = false;
            public function __construct(private readonly \Closure $change)
            {
                parent::__construct();
            }
            protected function doWrite(string $message, bool $newline): void
            {
                if (!$this->changed && str_contains($message, '/legacy')) {
                    $this->changed = true;
                    ($this->change)();
                }
                parent::doWrite($message, $newline);
            }
        };
        $command = $this->get(CommandRegistry::class)->getCommandByIdentifier('redirect-lifecycle:' . $action);
        self::assertSame(0, $command->run(new ArrayInput(['uids' => ['100'], '--execute' => true]), $output));
        self::assertNotNull($current);
        self::assertSame($current, $this->record(100));
        self::assertStringContainsString($reason, $output->fetch());
    }

    public static function changedCandidates(): array
    {
        return [
            'adoption protection' => ['adopt', ['protected' => 1], 'Protected'],
            'adoption disabled' => ['adopt', ['disabled' => 1], 'Manually disabled'],
            'adoption fixed' => ['adopt', ['tx_redirectlifecycle_mode' => 2, 'endtime' => 2000000000], 'Fixed expiry'],
            'adoption already enrolled' => ['adopt', ['tx_redirectlifecycle_mode' => 1], 'Already managed'],
            'legacy expiry enrolled during preview' => ['adopt', ['tx_redirectlifecycle_mode' => 1], 'Already managed', ['endtime' => 2000000000]],
            'renewal no longer managed' => ['renew', ['tx_redirectlifecycle_mode' => 0], 'Unmanaged'],
            'renewal fixed' => ['renew', ['tx_redirectlifecycle_mode' => 2, 'endtime' => 2000000000], 'Fixed expiry'],
        ];
    }

    private function command(string $action): CommandTester
    {
        return new CommandTester($this->get(CommandRegistry::class)->getCommandByIdentifier('redirect-lifecycle:' . $action));
    }

    private function record(int $uid): array
    {
        return BackendUtility::getRecord('sys_redirect', $uid, '*', '', false);
    }
}
