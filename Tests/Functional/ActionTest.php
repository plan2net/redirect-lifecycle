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
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Service\RedirectService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ActionTest extends FunctionalTestCase
{
    use TestClock;

    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['plan2net/redirect-lifecycle'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Creation.csv');
        $user = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
        $this->setTime('2026-04-11 UTC');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '5', 'cleanupGracePeriod' => '7'];
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
        self::assertSame(0, $tester->execute(['uids' => ['100'], '--execute' => true]));
        self::assertSame($before, $this->record(100));
    }

    public static function adoptionExclusions(): array
    {
        return [
            'protected' => [['protected' => 1], 'Protected'],
            'disabled' => [['disabled' => 1], 'Manually disabled'],
            'deleted' => [['deleted' => 1], 'Deleted'],
            'legacy fixed expiry' => [['endtime' => 2000000000], 'Fixed expiry'],
            'expired legacy fixed expiry' => [['endtime' => 1735689600], 'Fixed expiry'],
            'fixed mode without date' => [['tx_redirectlifecycle_mode' => 2], 'Fixed expiry'],
            'already managed' => [['tx_redirectlifecycle_mode' => 1, 'endtime' => 2000000000, 'tx_redirectlifecycle_delete_after' => 2000000000], 'Already managed'],
            'not yet active' => [['starttime' => 2000000000], 'Not yet active'],
            'QR code' => [['redirect_type' => 'qrcode'], 'Excluded redirect type'],
            'short URL' => [['redirect_type' => 'short_url'], 'Excluded redirect type'],
        ];
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
        self::assertSame(0, $tester->execute(['uids' => ['100'], '--execute' => true]));
        self::assertStringContainsString($reason, $tester->getDisplay());
        self::assertSame($before, $this->record(100));
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

    public function testMissingUidIsReportedAndActionsAreNotSchedulable(): void
    {
        $tester = $this->command('renew');
        self::assertSame(0, $tester->execute(['uids' => ['999'], '--execute' => true]));
        self::assertStringContainsString('Record missing', $tester->getDisplay());
        $commands = iterator_to_array($this->get(CommandRegistry::class)->getSchedulableCommands());
        self::assertArrayHasKey('redirect-lifecycle:cleanup', $commands);
        self::assertArrayNotHasKey('redirect-lifecycle:adopt', $commands);
        self::assertArrayNotHasKey('redirect-lifecycle:renew', $commands);
    }

    public function testAdoptionIgnoresAgeAndLastHitAndActionsRefreshPopulatedCache(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->update('sys_redirect', ['createdon' => 1, 'lasthiton' => 1, 'hitcount' => 12], ['uid' => 100]);
        $service = $this->get(RedirectService::class);
        self::assertNotNull($service->matchRedirect('example.test', '/legacy'));
        self::assertSame(0, $this->command('adopt')->execute(['uids' => ['100'], '--execute' => true]));
        $matched = $service->matchRedirect('example.test', '/legacy');
        self::assertNotNull($matched);
        self::assertSame((new \DateTimeImmutable('2026-04-16 UTC'))->getTimestamp(), (int)$matched['endtime']);
        self::assertSame(12, (int)$this->record(100)['hitcount']);
        $this->setTime('2026-05-01 UTC');
        self::assertNull($service->matchRedirect('example.test', '/legacy'));
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

    public function testPreviewDoesNotAuthenticateCliUserAndExecuteKeepsFieldPermissions(): void
    {
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
    public function testActionsRecheckChangedCandidatesAfterPreview(string $action, array $fields, string $reason): void
    {
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
