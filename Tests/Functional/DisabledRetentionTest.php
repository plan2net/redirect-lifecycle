<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use Plan2net\RedirectLifecycle\Tests\Functional\Support\TestClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DisabledRetentionTest extends FunctionalTestCase
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
        $this->setTime('2026-01-01 UTC');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = [
            'redirectTTL' => '10', 'cleanupGracePeriod' => '90', 'disabledRetentionDays' => '7',
        ];
    }

    #[DataProvider('lifetimeModes')]
    public function testDisablingStartsCountdownWithoutChangingLifetimeAndEditsDoNotResetIt(int $mode, int $expiry): void
    {
        $before = $this->createRedirect(['tx_redirectlifecycle_mode' => $mode, 'endtime' => $expiry]);
        $this->setTime('2026-01-02 UTC');
        $disabled = $this->update($before, ['disabled' => 1]);
        $deadline = (new \DateTimeImmutable('2026-01-09 UTC'))->getTimestamp();
        self::assertSame($deadline, (int)$disabled['tx_redirectlifecycle_delete_after']);
        self::assertSame(1, (int)$disabled['tx_redirectlifecycle_disabled_retention']);
        self::assertSame($mode, (int)$disabled['tx_redirectlifecycle_mode']);
        self::assertSame($before['endtime'], $disabled['endtime']);

        $this->setTime('2026-01-03 UTC');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '14';
        $edited = $this->update($disabled, ['source_path' => '/edited']);
        self::assertSame($deadline, (int)$edited['tx_redirectlifecycle_delete_after']);
        self::assertSame(1, (int)$edited['disabled']);
        self::assertSame($before['endtime'], $edited['endtime']);
    }

    public static function lifetimeModes(): array
    {
        return [
            'managed' => [1, 0],
            'fixed' => [2, 1768867200],
            'unmanaged' => [0, 0],
        ];
    }

    #[DataProvider('lifetimeModes')]
    public function testAdoptInitializesExistingDisabledCountdownOnce(int $mode, int $expiry): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '0';
        $before = $this->createRedirect(['tx_redirectlifecycle_mode' => $mode, 'endtime' => $expiry, 'disabled' => 1]);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '7';
        $this->setTime('2026-01-03 UTC');
        $tester = $this->command('adopt');
        self::assertSame('startDisabledRetention', $this->get(RedirectLifecycle::class)->actionReason($before, true));
        self::assertSame(0, $tester->execute(['uids' => [(string)$before['uid']]]));
        self::assertSame($before, $this->record((int)$before['uid']));
        self::assertStringContainsString('Start disabled retention', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['uids' => [(string)$before['uid']], '--execute' => true]));
        $after = $this->record((int)$before['uid']);
        self::assertSame((new \DateTimeImmutable('2026-01-10 UTC'))->getTimestamp(), (int)$after['tx_redirectlifecycle_delete_after']);
        self::assertSame(1, (int)$after['tx_redirectlifecycle_disabled_retention']);
        self::assertSame($mode, (int)$after['tx_redirectlifecycle_mode']);
        self::assertSame($before['endtime'], $after['endtime']);
        self::assertSame(1, (int)$after['disabled']);
        self::assertSame('disabledRetentionActive', $this->get(RedirectLifecycle::class)->actionReason($after, true));
        $this->setTime('2026-01-04 UTC');
        self::assertSame(0, $tester->execute(['uids' => [(string)$before['uid']], '--execute' => true]));
        self::assertSame($after, $this->record((int)$before['uid']));
    }

    #[DataProvider('lifetimeModes')]
    public function testProtectedDisabledRedirectsStayProtectedWithoutCountdown(int $mode, int $expiry): void
    {
        $before = $this->createRedirect(['tx_redirectlifecycle_mode' => $mode, 'endtime' => $expiry, 'disabled' => 1, 'protected' => 1]);
        $this->setTime('2026-01-03 UTC');
        self::assertSame(0, $this->command('adopt')->execute(['uids' => [(string)$before['uid']], '--execute' => true]));
        $after = $this->record((int)$before['uid']);
        self::assertSame($before, $after);
        self::assertSame(1, (int)$after['protected']);
        self::assertSame(0, (int)$after['tx_redirectlifecycle_disabled_retention']);
        $this->setTime('2027-01-01 UTC');
        self::assertSame(0, $this->command('cleanup')->execute([]));
        self::assertSame($before, $this->record((int)$before['uid']));
    }

    #[DataProvider('lifetimeModes')]
    public function testCleanupUsesDisabledDeadlineInsteadOfExpiryAndCanBeTurnedOff(int $mode, int $expiry): void
    {
        $record = $this->createRedirect(['tx_redirectlifecycle_mode' => $mode, 'endtime' => $expiry, 'disabled' => 1]);
        $deadline = (new \DateTimeImmutable('2026-01-08 UTC'))->getTimestamp();
        $this->setTime($deadline - 1);
        $tester = $this->command('cleanup');
        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertStringNotContainsString('/disabled', $tester->getDisplay());
        $this->setTime($deadline);
        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('/disabled', $tester->getDisplay());
        self::assertStringContainsString((string)$deadline, $tester->getDisplay());
        self::assertSame($record, $this->record((int)$record['uid']));
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '0';
        self::assertSame(0, $tester->execute([]));
        self::assertSame($record, $this->record((int)$record['uid']));
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '7';
        self::assertSame(0, $tester->execute([]));
        $deleted = $this->record((int)$record['uid']);
        self::assertSame(1, (int)$deleted['deleted']);
        self::assertSame(1, (int)$deleted['disabled']);
        self::assertSame($record['endtime'], $deleted['endtime']);
    }

    #[DataProvider('lifetimeModes')]
    public function testReactivationCancelsDisabledDeadlineAndDisablingAgainStartsFresh(int $mode, int $expiry): void
    {
        $record = $this->createRedirect(['tx_redirectlifecycle_mode' => $mode, 'endtime' => $expiry, 'disabled' => 1]);
        $this->setTime('2026-01-03 UTC');
        $enabled = $this->update($record, ['disabled' => 0]);
        self::assertSame(0, (int)$enabled['tx_redirectlifecycle_disabled_retention']);
        self::assertSame($mode, (int)$enabled['tx_redirectlifecycle_mode']);
        if ($mode === 1) {
            self::assertSame((new \DateTimeImmutable('2026-01-13 UTC'))->getTimestamp(), (int)$enabled['endtime']);
            self::assertSame((new \DateTimeImmutable('2026-04-13 UTC'))->getTimestamp(), (int)$enabled['tx_redirectlifecycle_delete_after']);
        } else {
            self::assertSame($record['endtime'], $enabled['endtime']);
            self::assertSame(0, (int)$enabled['tx_redirectlifecycle_delete_after']);
        }
        $this->setTime('2026-01-04 UTC');
        $disabled = $this->update($enabled, ['disabled' => 1]);
        self::assertSame((new \DateTimeImmutable('2026-01-11 UTC'))->getTimestamp(), (int)$disabled['tx_redirectlifecycle_delete_after']);
        self::assertSame($enabled['endtime'], $disabled['endtime']);
    }

    public function testAddingProtectionCancelsCountdownAndRemovingProtectionStartsFresh(): void
    {
        $record = $this->createRedirect(['tx_redirectlifecycle_mode' => 2, 'endtime' => 1768867200, 'disabled' => 1]);
        $this->setTime('2026-01-03 UTC');
        $protected = $this->update($record, ['protected' => 1]);
        self::assertSame(1, (int)$protected['protected']);
        self::assertSame(1, (int)$protected['disabled']);
        self::assertSame(0, (int)$protected['tx_redirectlifecycle_disabled_retention']);
        self::assertSame(0, (int)$protected['tx_redirectlifecycle_delete_after']);
        self::assertSame($record['endtime'], $protected['endtime']);
        $this->setTime('2026-01-04 UTC');
        self::assertSame(0, $this->command('adopt')->execute(['uids' => [(string)$record['uid']], '--execute' => true]));
        self::assertSame($protected, $this->record((int)$record['uid']));
        $unprotected = $this->update($protected, ['protected' => 0]);
        self::assertSame((new \DateTimeImmutable('2026-01-11 UTC'))->getTimestamp(), (int)$unprotected['tx_redirectlifecycle_delete_after']);
        self::assertSame(1, (int)$unprotected['tx_redirectlifecycle_disabled_retention']);
    }

    public function testOldManagedDeletionDateIsNotMistakenForDisabledCountdown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '0';
        $record = $this->createRedirect(['disabled' => 1]);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '7';
        $this->setTime('2027-01-01 UTC');
        self::assertSame(0, $this->command('cleanup')->execute([]));
        self::assertSame($record, $this->record((int)$record['uid']));
        self::assertSame(0, $this->command('adopt')->execute(['uids' => [(string)$record['uid']], '--execute' => true]));
        $after = $this->record((int)$record['uid']);
        self::assertSame((new \DateTimeImmutable('2027-01-08 UTC'))->getTimestamp(), (int)$after['tx_redirectlifecycle_delete_after']);
        self::assertSame($record['endtime'], $after['endtime']);
    }

    #[DataProvider('lifetimeModes')]
    public function testRestoringDisabledRedirectGrantsFreshRetentionWithoutReactivation(int $mode, int $expiry): void
    {
        $record = $this->createRedirect(['tx_redirectlifecycle_mode' => $mode, 'endtime' => $expiry, 'disabled' => 1]);
        $this->setTime('2026-01-08 UTC');
        self::assertSame(0, $this->command('cleanup')->execute([]));
        self::assertSame(1, (int)$this->record((int)$record['uid'])['deleted']);
        $this->setTime('2026-01-09 UTC');
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start([], ['sys_redirect' => [$record['uid'] => ['undelete' => 1]]]);
        $handler->process_cmdmap();
        self::assertSame([], $handler->errorLog);
        $restored = $this->record((int)$record['uid']);
        self::assertSame(0, (int)$restored['deleted']);
        self::assertSame(1, (int)$restored['disabled']);
        self::assertSame(1, (int)$restored['tx_redirectlifecycle_disabled_retention']);
        self::assertSame((new \DateTimeImmutable('2026-01-16 UTC'))->getTimestamp(), (int)$restored['tx_redirectlifecycle_delete_after']);
        if ($mode !== 1) {
            self::assertSame($record['endtime'], $restored['endtime']);
        }
        self::assertSame(0, $this->command('cleanup')->execute([]));
        self::assertSame(0, (int)$this->record((int)$record['uid'])['deleted']);
    }

    public function testLegacyFixedClassificationAlsoStartsRetentionWithoutUnprotectingRecords(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '0';
        $record = $this->createRedirect(['tx_redirectlifecycle_mode' => 0, 'endtime' => 1768867200, 'disabled' => 1]);
        $protected = $this->createRedirect(['tx_redirectlifecycle_mode' => 0, 'endtime' => 1768867200, 'disabled' => 1, 'protected' => 1, 'source_path' => '/protected']);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '7';
        $this->setTime('2026-01-03 UTC');
        $input = ['uids' => [(string)$record['uid'], (string)$protected['uid']], '--execute' => true];
        self::assertSame(0, $this->command('adopt')->execute($input));
        $after = $this->record((int)$record['uid']);
        self::assertSame(2, (int)$after['tx_redirectlifecycle_mode']);
        self::assertSame(1, (int)$after['tx_redirectlifecycle_disabled_retention']);
        self::assertSame((new \DateTimeImmutable('2026-01-10 UTC'))->getTimestamp(), (int)$after['tx_redirectlifecycle_delete_after']);
        $protectedAfter = $this->record((int)$protected['uid']);
        self::assertSame(2, (int)$protectedAfter['tx_redirectlifecycle_mode']);
        self::assertSame(1, (int)$protectedAfter['protected']);
        self::assertSame(1, (int)$protectedAfter['disabled']);
        self::assertSame($protected['endtime'], $protectedAfter['endtime']);
        self::assertSame(0, (int)$protectedAfter['tx_redirectlifecycle_disabled_retention']);
        self::assertSame(0, (int)$protectedAfter['tx_redirectlifecycle_delete_after']);
        $this->setTime('2026-01-04 UTC');
        self::assertSame(0, $this->command('adopt')->execute($input));
        self::assertSame($after, $this->record((int)$record['uid']));
        self::assertSame($protectedAfter, $this->record((int)$protected['uid']));
    }

    public function testSchedulerAdoptionInitializesCountdownAndPreservesProtectedDisabledRecords(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '0';
        $record = $this->createRedirect(['tx_redirectlifecycle_mode' => 0, 'disabled' => 1]);
        $protected = $this->createRedirect(['tx_redirectlifecycle_mode' => 0, 'disabled' => 1, 'protected' => 1, 'source_path' => '/protected']);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '7';
        $this->setTime('2026-01-03 UTC');
        $task = GeneralUtility::makeInstance(ExecuteSchedulableCommandTask::class);
        if (method_exists($task, 'setTaskType')) {
            $task->setTaskType('redirect-lifecycle:adopt');
            $task->setTaskParameters(['options' => ['execute' => true]]);
        } else {
            $task->setCommandIdentifier('redirect-lifecycle:adopt');
            $task->setOptions(['execute' => true]);
            $task->setOptionValues(['execute' => true]);
        }
        self::assertTrue($task->execute());
        self::assertSame((new \DateTimeImmutable('2026-01-10 UTC'))->getTimestamp(), (int)$this->record((int)$record['uid'])['tx_redirectlifecycle_delete_after']);
        self::assertSame($protected, $this->record((int)$protected['uid']));
    }

    public function testAdoptionInitializationRequiresLifetimeFieldPermission(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '0';
        $record = $this->createRedirect(['tx_redirectlifecycle_mode' => 0, 'disabled' => 1]);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '7';
        $GLOBALS['EXEC_TIME'] = time();
        $this->setUpBackendUser(2);
        $this->setTime('2026-01-03 UTC');
        $this->expectException(\RuntimeException::class);
        try {
            $this->command('adopt')->execute(['uids' => [(string)$record['uid']], '--execute' => true]);
        } finally {
            self::assertSame($record, $this->record((int)$record['uid']));
        }
    }

    #[DataProvider('invalidPeriods')]
    public function testInvalidOrOverflowingPeriodDoesNotChangeLegacyRecord(mixed $period): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '0';
        $record = $this->createRedirect(['tx_redirectlifecycle_mode' => 0, 'disabled' => 1]);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = $period;
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->command('adopt')->execute(['uids' => [(string)$record['uid']], '--execute' => true]);
        } finally {
            self::assertSame($record, $this->record((int)$record['uid']));
        }
    }

    public static function invalidPeriods(): array
    {
        return [['-1'], ['invalid'], [true], [(string)PHP_INT_MAX]];
    }

    #[DataProvider('changesDuringCleanup')]
    public function testCleanupRechecksProtectionAndReactivation(array $fields): void
    {
        $record = $this->createRedirect(['tx_redirectlifecycle_mode' => 2, 'endtime' => 1768867200, 'disabled' => 1]);
        $this->setTime('2026-01-08 UTC');
        $change = function () use ($record, $fields): void {
            $this->update($record, $fields);
        };
        $output = new class($change) extends BufferedOutput {
            private bool $changed = false;
            public function __construct(private readonly \Closure $change) { parent::__construct(); }
            protected function doWrite(string $message, bool $newline): void
            {
                if (!$this->changed && str_contains($message, '/disabled')) {
                    $this->changed = true;
                    ($this->change)();
                }
                parent::doWrite($message, $newline);
            }
        };
        $command = $this->get(CommandRegistry::class)->getCommandByIdentifier('redirect-lifecycle:cleanup');
        self::assertSame(0, $command->run(new ArrayInput([]), $output));
        $after = $this->record((int)$record['uid']);
        self::assertSame(0, (int)$after['deleted']);
        self::assertSame(0, (int)$after['tx_redirectlifecycle_disabled_retention']);
        foreach ($fields as $field => $value) {
            self::assertSame($value, (int)$after[$field]);
        }
    }

    public static function changesDuringCleanup(): array
    {
        return ['protection' => [['protected' => 1]], 'reactivation' => [['disabled' => 0]]];
    }

    #[DataProvider('excludedTypes')]
    public function testExcludedRedirectTypesNeverGetDisabledRetention(string $type): void
    {
        if (!isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
            self::markTestSkipped('Redirect types were introduced in TYPO3 14.');
        }
        $record = $this->createRedirect(['disabled' => 1]);
        $excluded = $this->update($record, ['redirect_type' => $type]);
        self::assertSame(0, (int)$excluded['tx_redirectlifecycle_disabled_retention']);
        self::assertSame(0, (int)$excluded['tx_redirectlifecycle_delete_after']);
        self::assertSame(0, $this->command('adopt')->execute(['uids' => [(string)$record['uid']], '--execute' => true]));
        $this->setTime('2027-01-01 UTC');
        self::assertSame(0, $this->command('cleanup')->execute([]));
        self::assertSame($excluded, $this->record((int)$record['uid']));
    }

    public static function excludedTypes(): array
    {
        return [['qrcode'], ['short_url']];
    }

    public function testBackendFormShowsDisabledDeadlineForUnmanagedRedirect(): void
    {
        $record = $this->createRedirect(['tx_redirectlifecycle_mode' => 0, 'disabled' => 1]);
        $request = new ServerRequest('https://example.test/typo3/', 'GET');
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $compiler = GeneralUtility::makeInstance(FormDataCompiler::class);
        $group = GeneralUtility::makeInstance(TcaDatabaseRecord::class);
        $input = ['request' => $request, 'tableName' => 'sys_redirect', 'command' => 'edit', 'vanillaUid' => $record['uid']];
        $form = $compiler->compile($input, $group);
        self::assertArrayHasKey('tx_redirectlifecycle_delete_after', $form['processedTca']['columns']);
        self::assertTrue($form['processedTca']['columns']['tx_redirectlifecycle_delete_after']['config']['readOnly']);
        $this->update($record, ['disabled' => 0]);
        $form = $compiler->compile($input, $group);
        self::assertArrayNotHasKey('tx_redirectlifecycle_delete_after', $form['processedTca']['columns']);
    }

    public function testInternalMarkerCannotBeUsedToCancelOrForgeCountdown(): void
    {
        $record = $this->createRedirect(['tx_redirectlifecycle_mode' => 0, 'disabled' => 1]);
        $after = $this->update($record, ['tx_redirectlifecycle_disabled_retention' => 0, 'tx_redirectlifecycle_delete_after' => 1]);
        self::assertSame(1, (int)$after['tx_redirectlifecycle_disabled_retention']);
        self::assertSame($record['tx_redirectlifecycle_delete_after'], $after['tx_redirectlifecycle_delete_after']);

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '0';
        $legacy = $this->createRedirect(['tx_redirectlifecycle_mode' => 0, 'disabled' => 1, 'source_path' => '/legacy-disabled']);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['disabledRetentionDays'] = '7';
        $forged = $this->update($legacy, ['tx_redirectlifecycle_disabled_retention' => 1, 'tx_redirectlifecycle_delete_after' => 1]);
        self::assertSame(0, (int)$forged['tx_redirectlifecycle_disabled_retention']);
        self::assertSame(0, (int)$forged['tx_redirectlifecycle_delete_after']);
    }

    private function createRedirect(array $fields = []): array
    {
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start(['sys_redirect' => ['NEWdisabled' => array_replace([
            'pid' => 0, 'source_host' => '*', 'source_path' => '/disabled', 'target' => 'https://target.test/',
        ], $fields)]], []);
        $handler->process_datamap();
        self::assertSame([], $handler->errorLog);
        return $this->record((int)$handler->substNEWwithIDs['NEWdisabled']);
    }

    private function update(array $record, array $fields): array
    {
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start(['sys_redirect' => [$record['uid'] => $fields]], []);
        $handler->process_datamap();
        self::assertSame([], $handler->errorLog);
        return $this->record((int)$record['uid']);
    }

    private function record(int $uid): array
    {
        return BackendUtility::getRecord('sys_redirect', $uid, '*', '', false);
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester($this->get(CommandRegistry::class)->getCommandByIdentifier('redirect-lifecycle:' . $name));
    }
}
