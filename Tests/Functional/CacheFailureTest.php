<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use Plan2net\RedirectLifecycle\Tests\Functional\Fixtures\FailingCacheBackend;
use Plan2net\RedirectLifecycle\Tests\Functional\Support\TestClock;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Writer\WriterInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Event\RedirectWasHitEvent;
use TYPO3\CMS\Redirects\Http\Middleware\RedirectHandler;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class CacheFailureTest extends FunctionalTestCase
{
    use TestClock;

    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['plan2net/redirect-lifecycle'];
    protected array $configurationToUseInTestInstance = [
        'SYS' => ['caching' => ['cacheConfigurations' => ['pages' => ['backend' => FailingCacheBackend::class]]]],
    ];
    private array $logs = [];

    protected function setUp(): void
    {
        FailingCacheBackend::$transactionFailure = FailingCacheBackend::$outsideFailure = null;
        FailingCacheBackend::$attempts = [];
        FailingCacheBackend::$invalidationFailure = null;
        FailingCacheBackend::$invalidations = [];
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Creation.csv');
        $user = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
        $this->setTime('2026-01-01');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '5', 'cleanupGracePeriod' => '7'];
        $writer = new class(function (LogRecord $record): void { $this->logs[] = $record; }) implements WriterInterface {
            public function __construct(private readonly \Closure $record) {}
            public function writeLog(LogRecord $record): WriterInterface
            {
                ($this->record)($record);
                return $this;
            }
        };
        $this->get(LogManager::class)->getLogger(RedirectLifecycle::class)->addWriter(LogLevel::ERROR, $writer);
    }

    protected function tearDown(): void
    {
        FailingCacheBackend::$transactionFailure = FailingCacheBackend::$outsideFailure = null;
        FailingCacheBackend::$attempts = [];
        FailingCacheBackend::$invalidationFailure = null;
        FailingCacheBackend::$invalidations = [];
        parent::tearDown();
    }

    #[DataProvider('cliActions')]
    public function testCliCacheFailureReportsCommittedChangeWithoutRepeatingIt(string $action): void
    {
        $this->prepare($action);
        $history = $this->historyCount();
        $failure = FailingCacheBackend::$outsideFailure = new \RuntimeException('Cache publication unavailable.');
        FailingCacheBackend::$attempts = [];
        try {
            $this->command($action)->execute($action === 'cleanup' ? [] : ['uids' => ['100'], '--execute' => true]);
            self::fail('A committed write must report cache publication failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame(1791100801, $exception->getCode());
            self::assertSame($failure, $exception->getPrevious());
            self::assertStringContainsString('100', $exception->getMessage());
            self::assertStringContainsString('saved', $exception->getMessage());
        }
        $after = $this->record();
        self::assertSame(1, (int)$after['tx_redirectlifecycle_mode']);
        self::assertSame($action === 'cleanup' ? 1 : 0, (int)$after['deleted']);
        if ($action !== 'cleanup') {
            self::assertSame($this->timestamp('2026-01-15'), (int)$after['endtime']);
            self::assertSame($this->timestamp('2026-01-22'), (int)$after['tx_redirectlifecycle_delete_after']);
        }
        self::assertSame($history + ($action === 'renew' ? 0 : 1), $this->historyCount());
        self::assertCount(1, array_filter(FailingCacheBackend::$attempts, static fn(int $level): bool => $level === 0));
        self::assertSame([0], FailingCacheBackend::$attempts, 'Never publish uncommitted records.');
        $this->assertCacheError($failure);
    }

    public static function cliActions(): array
    {
        return [['adopt'], ['renew'], ['cleanup']];
    }

    #[DataProvider('cliActions')]
    public function testBulkActionPublishesEachHostOnceAfterAllChanges(string $action): void
    {
        $this->prepare($action);
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        foreach ([101 => '', 102 => 'other.test'] as $uid => $host) {
            $connection->insert('sys_redirect', array_replace($this->record(), [
                'uid' => $uid, 'source_host' => $host, 'source_path' => '/record-' . $uid,
            ]));
        }
        $cache = $this->get(RedirectCacheService::class);
        $cache->rebuildForHost('*');
        $cache->rebuildForHost('other.test');
        FailingCacheBackend::$attempts = [];
        FailingCacheBackend::$invalidations = [];
        self::assertSame(0, $this->command($action)->execute($action === 'cleanup' ? [] : [
            'uids' => ['100', '101', '102'], '--execute' => true,
        ]));
        self::assertSame([0, 0], FailingCacheBackend::$attempts);
        self::assertSame([
            'redirects_' . sha1('*'), 'redirects_' . sha1('*'), 'redirects_' . sha1('other.test'),
        ], FailingCacheBackend::$invalidations);
        foreach ([100 => '*', 101 => '*', 102 => 'other.test'] as $uid => $host) {
            $record = BackendUtility::getRecord('sys_redirect', $uid, '*', '', false);
            $cached = $cache->getRedirects($host)['flat'][rtrim($record['source_path'], '/') . '/'][$uid] ?? null;
            if ($action === 'cleanup') {
                self::assertSame(1, (int)$record['deleted']);
                self::assertNull($cached);
            } else {
                self::assertSame($this->timestamp('2026-01-15'), (int)$record['endtime']);
                self::assertSame((int)$record['endtime'], (int)$cached['endtime']);
            }
        }
    }

    public function testFailedInvalidationStopsTheBatchAndRepairsTheCommittedHost(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->insert('sys_redirect',
            array_replace($this->record(), ['uid' => 101, 'source_path' => '/second']));
        $failure = FailingCacheBackend::$invalidationFailure = new \RuntimeException('Invalidation unavailable.');
        FailingCacheBackend::$attempts = [];
        try {
            $this->command('adopt')->execute(['uids' => ['100', '101'], '--execute' => true]);
            self::fail('Failed invalidation must stop further writes.');
        } catch (\RuntimeException $exception) {
            self::assertSame(1791100801, $exception->getCode());
            self::assertSame($failure, $exception->getPrevious());
        }
        self::assertSame([0], FailingCacheBackend::$attempts);
        $cached = $this->get(RedirectCacheService::class)->getRedirects('*')['flat'];
        self::assertSame(1, (int)$cached['/legacy/'][100]['tx_redirectlifecycle_mode']);
        self::assertSame(0, (int)$cached['/second/'][101]['tx_redirectlifecycle_mode']);
        $this->assertCacheError($failure);
    }

    public function testNativeFailureMidBatchKeepsEarlierChangesAndPublishesTheirCache(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        $connection->insert('sys_redirect', array_replace($this->record(), [
            'uid' => 101, 'source_host' => 'other.test', 'source_path' => '/second',
        ]));
        $cache = $this->get(RedirectCacheService::class);
        $cache->rebuildForHost('*');
        $cache->rebuildForHost('other.test');
        $connection->executeStatement("CREATE TRIGGER fail_second BEFORE UPDATE ON sys_redirect WHEN NEW.uid=101 AND NEW.tx_redirectlifecycle_mode=1 BEGIN SELECT RAISE(ABORT, 'Second mutation failed.'); END");
        try {
            $this->command('adopt')->execute(['uids' => ['100', '101'], '--execute' => true]);
            self::fail('The second mutation must fail.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('Second mutation failed.', $exception->getMessage());
        }
        self::assertSame(1, (int)$cache->getRedirects('*')['flat']['/legacy/'][100]['tx_redirectlifecycle_mode']);
        self::assertSame(0, (int)$cache->getRedirects('other.test')['flat']['/second/'][101]['tx_redirectlifecycle_mode']);
        self::assertSame(1, $this->historyCount());
    }

    public function testPublicationFailureStillAttemptsEveryChangedHost(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->insert('sys_redirect',
            array_replace($this->record(), ['uid' => 101, 'source_host' => 'other.test', 'source_path' => '/second']));
        FailingCacheBackend::$outsideFailure = new \RuntimeException('Publication unavailable.');
        FailingCacheBackend::$attempts = [];
        try {
            $this->command('adopt')->execute(['uids' => ['100', '101'], '--execute' => true]);
            self::fail('The committed publication failure must be reported.');
        } catch (\RuntimeException $exception) {
            self::assertSame(1791100801, $exception->getCode());
        }
        self::assertSame([0, 0], FailingCacheBackend::$attempts);
        self::assertCount(2, $this->logs);
        FailingCacheBackend::$outsideFailure = null;
        self::assertSame(1, (int)$this->get(RedirectCacheService::class)->getRedirects('other.test')['flat']['/second/'][101]['tx_redirectlifecycle_mode']);
        // A later command must start with an empty batch, including after a failed publication.
        FailingCacheBackend::$attempts = [];
        self::assertSame(0, $this->command('adopt')->execute(['uids' => ['100', '101'], '--execute' => true]));
        self::assertSame([], FailingCacheBackend::$attempts);
    }

    public function testRestoreCacheFailureUsesNativeErrorLogAndKeepsSingleRestoration(): void
    {
        $this->prepare('restore');
        $history = $this->historyCount();
        $failure = FailingCacheBackend::$outsideFailure = new \RuntimeException('Cache publication unavailable.');
        FailingCacheBackend::$attempts = [];
        $handler = $this->nativeCommand('undelete');
        self::assertCount(1, $handler->errorLog);
        self::assertStringContainsString('saved', $handler->errorLog[0]);
        $after = $this->record();
        self::assertSame(0, (int)$after['deleted']);
        self::assertSame($this->timestamp('2026-01-15'), (int)$after['endtime']);
        self::assertSame($this->timestamp('2026-01-22'), (int)$after['tx_redirectlifecycle_delete_after']);
        self::assertSame($history + 1, $this->historyCount());
        self::assertCount(1, array_filter(FailingCacheBackend::$attempts, static fn(int $level): bool => $level === 0));
        $this->assertCacheError($failure);
        FailingCacheBackend::$outsideFailure = null;
        $this->setTime('2026-02-01');
        self::assertSame([], $this->nativeCommand('undelete')->errorLog);
        self::assertSame($after, $this->record());
        self::assertSame($history + 1, $this->historyCount());
    }

    #[DataProvider('writeActions')]
    public function testFailedCacheRepairCannotReplaceOriginalWriteFailure(string $action): void
    {
        $this->prepare($action);
        $before = $this->record();
        $history = $this->historyCount();
        $condition = match ($action) {
            'adopt' => 'NEW.tx_redirectlifecycle_mode != OLD.tx_redirectlifecycle_mode',
            'renew' => 'NEW.endtime != OLD.endtime',
            default => 'NEW.deleted != OLD.deleted',
        };
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->executeStatement(
            "CREATE TRIGGER fail_mutation BEFORE UPDATE ON sys_redirect WHEN $condition BEGIN SELECT RAISE(ABORT, 'Native mutation failed.'); END",
        );
        $repair = FailingCacheBackend::$outsideFailure = new \RuntimeException('Rollback cache repair failed.');
        try {
            if ($action === 'restore') {
                $this->nativeCommand('undelete');
            } else {
                $this->command($action)->execute($action === 'cleanup' ? [] : ['uids' => ['100'], '--execute' => true]);
            }
            self::fail('The original mutation exception must propagate.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('Native mutation failed.', $exception->getMessage());
            $original = $exception;
        }
        self::assertSame($before, $this->record());
        self::assertSame($history, $this->historyCount());
        $this->assertCacheError($repair);
        self::assertSame($original, $this->logs[0]->getData()['writeException']);
    }

    public static function writeActions(): array
    {
        return [['adopt'], ['renew'], ['cleanup'], ['restore']];
    }

    public function testHitCacheFailureKeepsPersistedDatesInNativeEvent(): void
    {
        $this->prepareHit();
        $failure = FailingCacheBackend::$outsideFailure = new \RuntimeException('Cache publication unavailable.');
        $event = new RedirectWasHitEvent(
            new ServerRequest('https://example.test/legacy', 'GET'),
            (new HtmlResponse('', 307))->withHeader('Location', 'https://target.test/legacy'),
            $this->record(), new Uri('https://target.test/legacy'),
        );
        $this->get(EventDispatcherInterface::class)->dispatch($event);
        $after = $this->record();
        self::assertSame($this->timestamp('2026-06-30'), (int)$after['endtime']);
        self::assertSame($this->timestamp('2026-07-07'), (int)$after['tx_redirectlifecycle_delete_after']);
        self::assertSame((int)$after['endtime'], (int)$event->getMatchedRedirect()['endtime']);
        self::assertSame((int)$after['tx_redirectlifecycle_delete_after'], (int)$event->getMatchedRedirect()['tx_redirectlifecycle_delete_after']);
        $this->assertCacheError($failure);
    }

    public function testHitCacheFailureStillReturnsNativeHttpRedirect(): void
    {
        $this->prepareHit();
        $failure = FailingCacheBackend::$outsideFailure = new \RuntimeException('Cache publication unavailable.');
        $response = $this->get(RedirectHandler::class)->process(new ServerRequest('https://example.test/legacy', 'GET'), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new HtmlResponse('', 404);
            }
        });
        self::assertSame(307, $response->getStatusCode());
        self::assertSame('https://target.test/legacy', $response->getHeaderLine('Location'));
        self::assertSame($this->timestamp('2026-06-30'), (int)$this->record()['endtime']);
        $this->assertCacheError($failure);
    }

    public function testFailingLogWriterCannotInterruptHit(): void
    {
        $this->prepareHit();
        $this->get(LogManager::class)->getLogger(RedirectLifecycle::class)->addWriter(LogLevel::ERROR, new class implements WriterInterface {
            public function writeLog(LogRecord $record): WriterInterface
            {
                throw new \RuntimeException('Log writer unavailable.');
            }
        });
        $failure = FailingCacheBackend::$outsideFailure = new \RuntimeException('Cache publication unavailable.');
        $dates = $this->get(RedirectLifecycle::class)->extendOnHit(100);
        self::assertSame($this->timestamp('2026-06-30'), $dates['endtime']);
        self::assertSame($dates['endtime'], (int)$this->record()['endtime']);
        $this->assertCacheError($failure);
    }

    private function prepare(string $action): void
    {
        if ($action !== 'adopt') {
            self::assertSame(0, $this->command('adopt')->execute(['uids' => ['100'], '--execute' => true]));
        }
        if ($action === 'restore') {
            self::assertSame([], $this->nativeCommand('delete')->errorLog);
        }
        $this->setTime($action === 'cleanup' ? '2026-02-01' : '2026-01-10');
    }

    private function prepareHit(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->update('sys_redirect', [
            'source_host' => 'example.test', 'target_statuscode' => 307, 'disable_hitcount' => 1,
        ], ['uid' => 100]);
        self::assertSame(0, $this->command('adopt')->execute(['uids' => ['100'], '--execute' => true]));
        $this->get(RedirectCacheService::class)->getRedirects('example.test');
    }

    private function assertCacheError(\Throwable $failure): void
    {
        self::assertCount(1, $this->logs);
        self::assertSame(LogLevel::ERROR, $this->logs[0]->getLevel());
        self::assertSame(100, $this->logs[0]->getData()['uid']);
        self::assertSame($failure, $this->logs[0]->getData()['exception']);
    }

    private function command(string $action): CommandTester
    {
        return new CommandTester($this->get(CommandRegistry::class)->getCommandByIdentifier('redirect-lifecycle:' . $action));
    }

    private function nativeCommand(string $command): DataHandler
    {
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start([], ['sys_redirect' => [100 => [$command => 1]]]);
        $handler->process_cmdmap();
        return $handler;
    }

    private function record(): array
    {
        return BackendUtility::getRecord('sys_redirect', 100, '*', '', false);
    }

    private function historyCount(): int
    {
        return $this->getConnectionPool()->getConnectionForTable('sys_history')->count('*', 'sys_history', [
            'tablename' => 'sys_redirect', 'recuid' => 100,
        ]);
    }

    private function timestamp(string $time): int
    {
        return (new \DateTimeImmutable($time . ' UTC'))->getTimestamp();
    }
}
