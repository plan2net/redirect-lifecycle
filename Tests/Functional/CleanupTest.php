<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Tests\Functional;

use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Psr\EventDispatcher\EventDispatcherInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Plan2net\RedirectLifecycle\Tests\Functional\Support\TestClock;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;
use TYPO3\CMS\Redirects\Event\RedirectWasHitEvent;
use TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class CleanupTest extends FunctionalTestCase
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
        $this->setTime('2026-01-01');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '10', 'cleanupGracePeriod' => '90'];
    }

    public function testDryRunListsDueRedirectWithoutChangingRecords(): void
    {
        $record = $this->createRedirect();
        $this->setTime('2026-04-11');
        $tester = $this->command();
        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('/cleanup', $tester->getDisplay());
        self::assertStringContainsString((string)$record['uid'], $tester->getDisplay());
        self::assertSame($record, $this->record((int)$record['uid']));
    }

    public function testDueRedirectIsSoftDeletedWithHistoryAndStoredDatesPreserved(): void
    {
        $record = $this->createRedirect();
        $this->setTime('2026-04-11');
        self::assertSame(0, $this->command()->execute([]));
        $deleted = $this->record((int)$record['uid']);
        self::assertSame(1, (int)$deleted['deleted']);
        self::assertSame(0, (int)$deleted['disabled']);
        self::assertSame($record['endtime'], $deleted['endtime']);
        self::assertSame($record['tx_redirectlifecycle_delete_after'], $deleted['tx_redirectlifecycle_delete_after']);
        self::assertNull(BackendUtility::getRecord('sys_redirect', $record['uid']));
        $history = $this->getConnectionPool()->getConnectionForTable('sys_history')->select(
            ['actiontype'], 'sys_history', ['tablename' => 'sys_redirect', 'recuid' => $record['uid']],
        )->fetchFirstColumn();
        self::assertContains(4, array_map('intval', $history));
        self::assertSame(0, $this->command()->execute([]));
        self::assertSame($deleted, $this->record((int)$record['uid']));
    }

    #[DataProvider('cleanupBoundaries')]
    public function testCleanupRespectsStoredDeletionTimeAndInclusiveExpiry(string $grace, string $time, int $deleted): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['cleanupGracePeriod'] = $grace;
        $record = $this->createRedirect();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['cleanupGracePeriod'] = '365';
        $this->setTime($time);
        self::assertSame(0, $this->command()->execute([]));
        $after = $this->record((int)$record['uid']);
        self::assertSame($deleted, (int)$after['deleted']);
        self::assertSame($record['endtime'], $after['endtime']);
        self::assertSame($record['tx_redirectlifecycle_delete_after'], $after['tx_redirectlifecycle_delete_after']);
    }

    public static function cleanupBoundaries(): array
    {
        return [
            'before deletion time' => ['90', '2026-04-10 23:59:59', 0],
            'at deletion time' => ['90', '2026-04-11 00:00:00', 1],
            'delayed first run' => ['90', '2026-05-01 00:00:00', 1],
            'zero grace final valid second' => ['0', '2026-01-11 00:00:00', 0],
            'zero grace first expired second' => ['0', '2026-01-11 00:00:01', 1],
        ];
    }

    #[DataProvider('ineligibleRecords')]
    public function testIneligibleRecordsAreNeitherListedNorDeleted(array $fields): void
    {
        if (isset($fields['redirect_type']) && !isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
            self::markTestSkipped('Redirect types were introduced in TYPO3 14.');
        }
        $record = $this->createRedirect();
        // Represent imported/inconsistent records as well as normal editor-created states.
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->update('sys_redirect', $fields, ['uid' => $record['uid']]);
        $before = $this->record((int)$record['uid']);
        $this->setTime('2026-04-11');
        $tester = $this->command();
        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertStringNotContainsString('/cleanup', $tester->getDisplay());
        self::assertSame(0, $this->command()->execute([]));
        self::assertSame($before, $this->record((int)$record['uid']));
    }

    public static function ineligibleRecords(): array
    {
        return [
            'protected' => [['protected' => 1]],
            'manually disabled' => [['disabled' => 1]],
            'unlimited' => [['endtime' => 0]],
            'unmanaged' => [['tx_redirectlifecycle_mode' => 0]],
            'fixed expiry' => [['tx_redirectlifecycle_mode' => 2]],
            'already deleted' => [['deleted' => 1]],
            'missing deletion time' => [['tx_redirectlifecycle_delete_after' => 0]],
            'invalid deletion before expiry' => [['tx_redirectlifecycle_delete_after' => 1]],
            'still valid' => [['endtime' => 2000000000, 'tx_redirectlifecycle_delete_after' => 2000000000]],
            'QR code' => [['redirect_type' => 'qrcode']],
            'short URL' => [['redirect_type' => 'short_url']],
        ];
    }

    public function testNativeSchedulerTaskCanRunCleanupAndDryRun(): void
    {
        $record = $this->createRedirect();
        $this->setTime('2026-04-11');
        $task = GeneralUtility::makeInstance(ExecuteSchedulableCommandTask::class);
        if (method_exists($task, 'setTaskType')) {
            $task->setTaskType('redirect-lifecycle:cleanup');
            $task->setTaskParameters(['options' => ['dry-run' => true]]);
        } else {
            $task->setCommandIdentifier('redirect-lifecycle:cleanup');
            $task->setOptions(['dry-run' => true]);
            $task->setOptionValues(['dry-run' => true]);
        }
        self::assertTrue($task->execute());
        self::assertSame($record, $this->record((int)$record['uid']));
        if (method_exists($task, 'setTaskType')) {
            $task->setTaskParameters([]);
        } else {
            $task->setOptions([]);
        }
        self::assertTrue($task->execute());
        self::assertSame(1, (int)$this->record((int)$record['uid'])['deleted']);
    }

    public function testCleanupRemovesDeletedRedirectFromPopulatedHostCacheAndKeepsSibling(): void
    {
        $record = $this->createRedirect();
        $sibling = $this->createRedirect(['source_path' => '/sibling', 'tx_redirectlifecycle_mode' => 0]);
        $cache = $this->get(RedirectCacheService::class);
        self::assertStringContainsString('/cleanup', json_encode($cache->getRedirects('example.test'), JSON_THROW_ON_ERROR));
        $this->setTime('2026-04-11');
        self::assertTrue($this->get(CacheManager::class)->getCache('pages')->has('redirects_' . sha1('example.test')));
        self::assertSame(0, $this->command()->execute([]));
        $after = json_encode($cache->getRedirects('example.test'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('/cleanup', $after);
        self::assertStringContainsString('/sibling', $after);
        self::assertSame(1, (int)$this->record((int)$record['uid'])['deleted']);
        self::assertSame($sibling, $this->record((int)$sibling['uid']));
    }

    #[DataProvider('changesAfterSelection')]
    public function testCleanupRechecksChangesAfterCandidateSelection(array $fields): void
    {
        if (isset($fields['redirect_type']) && !isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
            self::markTestSkipped('Redirect types were introduced in TYPO3 14.');
        }
        $record = $this->createRedirect();
        $this->setTime('2026-04-11');
        $change = function () use ($record, $fields): void {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start(['sys_redirect' => [$record['uid'] => $fields]], []);
            $dataHandler->process_datamap();
            self::assertSame([], $dataHandler->errorLog);
        };
        // Simulate an editor save while the public command prints its selected candidates.
        $output = new class($change) extends BufferedOutput {
            private bool $changed = false;
            public function __construct(private readonly \Closure $change)
            {
                parent::__construct();
            }
            protected function doWrite(string $message, bool $newline): void
            {
                if (!$this->changed && str_contains($message, '/cleanup')) {
                    $this->changed = true;
                    ($this->change)();
                }
                parent::doWrite($message, $newline);
            }
        };
        $command = $this->get(CommandRegistry::class)->getCommandByIdentifier('redirect-lifecycle:cleanup');
        self::assertSame(0, $command->run(new ArrayInput([]), $output));
        self::assertSame(0, (int)$this->record((int)$record['uid'])['deleted']);
    }

    public static function changesAfterSelection(): array
    {
        return [
            'protection' => [['protected' => 1]],
            'manual disabling' => [['disabled' => 1]],
            'ending management' => [['tx_redirectlifecycle_mode' => 0]],
            'fixed expiry' => [['tx_redirectlifecycle_mode' => 2, 'endtime' => 2000000000]],
            'QR code' => [['redirect_type' => 'qrcode']],
            'short URL' => [['redirect_type' => 'short_url']],
        ];
    }

    public function testCliCleanupAuthenticatesNativeUserOnlyForDeletion(): void
    {
        $record = $this->createRedirect();
        $this->setTime('2026-04-11');
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        self::assertSame(0, $this->command()->execute(['--dry-run' => true]));
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
        self::assertSame(0, $this->command()->execute([]));
        self::assertSame('_cli_', $GLOBALS['BE_USER']->user['username']);
        self::assertSame(1, (int)$this->record((int)$record['uid'])['deleted']);
    }

    public function testCleanupDoesNotDeleteCandidateRenewedByInFlightValidHit(): void
    {
        $record = $this->createRedirect();
        $this->setTime('2026-04-11');
        $renew = function () use ($record): void {
            // The request matched in the final valid second and completes after cleanup selection.
            $this->setTime('2026-01-11');
            $this->get(EventDispatcherInterface::class)->dispatch(new RedirectWasHitEvent(
                new ServerRequest('https://example.test/cleanup', 'GET'),
                new HtmlResponse('', 307), $record, new Uri('https://target.test/new'),
            ));
            $this->setTime('2026-04-11');
        };
        $output = new class($renew) extends BufferedOutput {
            private bool $renewed = false;
            public function __construct(private readonly \Closure $renew)
            {
                parent::__construct();
            }
            protected function doWrite(string $message, bool $newline): void
            {
                if (!$this->renewed && str_contains($message, '/cleanup')) {
                    $this->renewed = true;
                    ($this->renew)();
                }
                parent::doWrite($message, $newline);
            }
        };
        $command = $this->get(CommandRegistry::class)->getCommandByIdentifier('redirect-lifecycle:cleanup');
        self::assertSame(0, $command->run(new ArrayInput([]), $output));
        $after = $this->record((int)$record['uid']);
        self::assertSame(0, (int)$after['deleted']);
        self::assertSame((new \DateTimeImmutable('2026-07-10 UTC'))->getTimestamp(), (int)$after['endtime']);
        self::assertSame((new \DateTimeImmutable('2026-10-08 UTC'))->getTimestamp(), (int)$after['tx_redirectlifecycle_delete_after']);
    }

    public function testDeniedDeletionRollsBackAndReportsFailure(): void
    {
        $record = $this->createRedirect();
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups', ['tables_modify' => 'pages'], ['uid' => 1]);
        // Native session authentication uses wall-clock time, independently of lifecycle test time.
        $GLOBALS['EXEC_TIME'] = time();
        $this->setUpBackendUser(2);
        $this->setTime('2026-04-11');
        $failed = false;
        try {
            $this->command()->execute([]);
        } catch (\RuntimeException $exception) {
            $failed = true;
            self::assertNotSame('', $exception->getMessage());
        }
        self::assertTrue($failed, 'Cleanup must report a denied deletion.');
        self::assertSame($record, $this->record((int)$record['uid']));
    }

    private function command(): CommandTester
    {
        return new CommandTester($this->get(CommandRegistry::class)->getCommandByIdentifier('redirect-lifecycle:cleanup'));
    }

    private function createRedirect(array $fields = []): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['sys_redirect' => ['NEWredirect' => array_replace([
            'pid' => 0, 'source_host' => 'example.test', 'source_path' => '/cleanup',
            'target' => 'https://target.test/new', 'target_statuscode' => 307,
        ], $fields)]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        return $this->record((int)$dataHandler->substNEWwithIDs['NEWredirect']);
    }

    private function record(int $uid): array
    {
        return BackendUtility::getRecord('sys_redirect', $uid, '*', '', false);
    }
}
