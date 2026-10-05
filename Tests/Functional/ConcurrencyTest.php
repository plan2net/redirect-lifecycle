<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Tests\Functional;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use Plan2net\RedirectLifecycle\Tests\Functional\Support\TestClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Http\Middleware\RedirectHandler;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ConcurrencyTest extends FunctionalTestCase
{
    use TestClock;

    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['plan2net/redirect-lifecycle'];
    protected array $configurationToUseInTestInstance = [
        'SYS' => ['caching' => ['cacheConfigurations' => [
            'pages' => ['backend' => Typo3DatabaseBackend::class, 'options' => ['defaultLifetime' => 0]],
        ]]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('Parallel checks require the pcntl extension (available in DDEV).');
        }
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Creation.csv');
        $user = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = [
            'redirectTTL' => '10', 'cleanupGracePeriod' => '90',
        ];
        $this->setTime((new \DateTimeImmutable('2026-01-01 UTC'))->getTimestamp());
    }

    private function createRedirect(): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['sys_redirect' => ['NEWparallel' => [
            'pid' => 0, 'source_host' => 'parallel.test', 'source_path' => '/old',
            'target' => 'https://target.test/new', 'target_statuscode' => 307, 'disable_hitcount' => 1,
        ]]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        $this->get(RedirectCacheService::class)->getRedirects('parallel.test');
        return (int)$dataHandler->substNEWwithIDs['NEWparallel'];
    }

    public function testOlderHitRetriesWhenAnotherProcessRenewsAfterItsRead(): void
    {
        $uid = $this->createRedirect();
        $now = (new \DateTimeImmutable('2026-01-01 UTC'))->getTimestamp();
        $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertNotFalse($barrier);
        foreach ($barrier as $socket) {
            stream_set_timeout($socket, 10);
        }
        try {
            $this->parallel(2, function (int $worker) use ($uid, $now, $barrier): void {
                $this->setTime($now + $worker);
                if ($worker === 1) {
                    self::assertSame("selected\n", fgets($barrier[1]));
                    self::assertNotNull($this->get(RedirectLifecycle::class)->extendOnHit($uid));
                    fwrite($barrier[1], "renewed\n");
                    return;
                }
                $configuration = $this->createMock(ExtensionConfiguration::class);
                $paused = false;
                $configuration->method('get')->willReturnCallback(function () use ($barrier, &$paused): array {
                    // Configuration is read after SELECT and before the conditional UPDATE.
                    if (!$paused) {
                        $paused = true;
                        fwrite($barrier[0], "selected\n");
                        self::assertSame("renewed\n", fgets($barrier[0]));
                    }
                    return $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'];
                });
                $lifecycle = new RedirectLifecycle(
                    $this->get(Context::class), $this->get(SiteFinder::class), $configuration,
                    $this->get(ConnectionPool::class), $this->get(RedirectCacheService::class),
                );
                self::assertNull($lifecycle->extendOnHit($uid));
                self::assertTrue($paused);
            });
        } finally {
            fclose($barrier[0]);
            fclose($barrier[1]);
        }
        $record = BackendUtility::getRecord('sys_redirect', $uid);
        self::assertSame($now + 1 + 180 * 86400, (int)$record['endtime']);
        self::assertSame($now + 1 + 270 * 86400, (int)$record['tx_redirectlifecycle_delete_after']);
        $this->assertCurrentCache($uid, $record);
    }

    public function testConcurrentHitUsesCommittedDatesBeforeTheBatchFinishes(): void
    {
        $uid = $this->createRedirect();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = '20';
        $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertNotFalse($barrier);
        foreach ($barrier as $socket) {
            stream_set_timeout($socket, 10);
        }
        try {
            $this->parallel(2, function (int $worker) use ($uid, $barrier): void {
                if ($worker === 0) {
                    $this->get(RedirectLifecycle::class)->batch(function () use ($uid, $barrier): void {
                        self::assertNull($this->get(RedirectLifecycle::class)->renew($uid));
                        self::assertFalse($this->get(CacheManager::class)->getCache('pages')->has('redirects_' . sha1('parallel.test')));
                        fwrite($barrier[0], "committed\n");
                        self::assertSame("redirected\n", fgets($barrier[0]));
                    });
                    return;
                }
                self::assertSame("committed\n", fgets($barrier[1]));
                $cached = $this->get(RedirectCacheService::class)->getRedirects('parallel.test')['flat']['/old/'][$uid];
                self::assertSame((new \DateTimeImmutable('2026-01-21 UTC'))->getTimestamp(), (int)$cached['endtime']);
                $response = $this->get(RedirectHandler::class)->process(
                    new ServerRequest('https://parallel.test/old', 'GET'),
                    new class implements RequestHandlerInterface {
                        public function handle(ServerRequestInterface $request): ResponseInterface
                        {
                            return new HtmlResponse('', 404);
                        }
                    },
                );
                self::assertSame(307, $response->getStatusCode());
                fwrite($barrier[1], "redirected\n");
            });
        } finally {
            fclose($barrier[0]);
            fclose($barrier[1]);
        }
        $record = BackendUtility::getRecord('sys_redirect', $uid);
        self::assertSame((new \DateTimeImmutable('2026-06-30 UTC'))->getTimestamp(), (int)$record['endtime']);
        $this->assertCurrentCache($uid, $record);
    }

    public function testOverlappingCleanupRunsDeleteOnlyOnce(): void
    {
        $uid = $this->createRedirect();
        $this->setTime((new \DateTimeImmutable('2026-04-11 UTC'))->getTimestamp());
        $this->parallel(2, function (): void {
            $tester = new CommandTester($this->get(CommandRegistry::class)->getCommandByIdentifier('redirect-lifecycle:cleanup'));
            if ($tester->execute([]) !== 0) {
                throw new \RuntimeException('Cleanup failed.');
            }
        });
        $record = BackendUtility::getRecord('sys_redirect', $uid, '*', '', false);
        self::assertSame(1, (int)$record['deleted']);
        self::assertSame(1, $this->get(ConnectionPool::class)->getConnectionForTable('sys_history')->count('*', 'sys_history', [
            'tablename' => 'sys_redirect', 'recuid' => $uid, 'actiontype' => 4,
        ]));
        self::assertArrayNotHasKey('flat', $this->get(RedirectCacheService::class)->getRedirects('parallel.test'));
    }

    public function testParallelAdoptionStartsOnlyOneLifetime(): void
    {
        $uid = $this->createRedirect();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['sys_redirect' => [$uid => ['tx_redirectlifecycle_mode' => 0]]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        $history = $this->get(ConnectionPool::class)->getConnectionForTable('sys_history');
        $criteria = ['tablename' => 'sys_redirect', 'recuid' => $uid, 'actiontype' => 2];
        $changesBefore = $history->count('*', 'sys_history', $criteria);
        $now = (new \DateTimeImmutable('2026-01-02 UTC'))->getTimestamp();
        $this->parallel(4, function (int $worker) use ($uid, $now): void {
            $this->setTime($now + $worker);
            $tester = new CommandTester($this->get(CommandRegistry::class)->getCommandByIdentifier('redirect-lifecycle:adopt'));
            if ($tester->execute(['uids' => [(string)$uid], '--execute' => true]) !== 0) {
                throw new \RuntimeException('Adoption failed.');
            }
        });
        $record = BackendUtility::getRecord('sys_redirect', $uid);
        self::assertSame(1, (int)$record['tx_redirectlifecycle_mode']);
        self::assertContains((int)$record['endtime'], range($now + 10 * 86400, $now + 10 * 86400 + 3));
        self::assertSame((int)$record['endtime'] + 90 * 86400, (int)$record['tx_redirectlifecycle_delete_after']);
        self::assertSame($changesBefore + 1, $this->get(ConnectionPool::class)->getConnectionForTable('sys_history')->count('*', 'sys_history', $criteria));
        $this->assertCurrentCache($uid, $record);
        $this->setTime($now + 86400);
        $tester = new CommandTester($this->get(CommandRegistry::class)->getCommandByIdentifier('redirect-lifecycle:adopt'));
        self::assertSame(0, $tester->execute(['uids' => [(string)$uid], '--execute' => true]));
        self::assertSame($record, BackendUtility::getRecord('sys_redirect', $uid));
    }

    public function testParallelExplicitRenewalReplacesBothDatesAndLeavesCurrentCache(): void
    {
        $uid = $this->createRedirect();
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('sys_redirect');
        // A restart may shorten a hit-extended lifetime; concurrent writes must keep the dates paired.
        $now = (new \DateTimeImmutable('2026-01-02 UTC'))->getTimestamp();
        $connection->update('sys_redirect', [
            'endtime' => $now + 200 * 86400, 'tx_redirectlifecycle_delete_after' => $now + 290 * 86400,
        ], ['uid' => $uid]);
        $this->get(RedirectCacheService::class)->rebuildForHost('parallel.test');
        $this->parallel(4, function (int $worker) use ($uid, $now): void {
            $this->setTime($now + $worker);
            $tester = new CommandTester($this->get(CommandRegistry::class)->getCommandByIdentifier('redirect-lifecycle:renew'));
            if ($tester->execute(['uids' => [(string)$uid], '--execute' => true]) !== 0) {
                throw new \RuntimeException('Renewal failed.');
            }
        });
        $record = BackendUtility::getRecord('sys_redirect', $uid);
        self::assertContains((int)$record['endtime'], range($now + 10 * 86400, $now + 10 * 86400 + 3));
        self::assertSame((int)$record['endtime'] + 90 * 86400, (int)$record['tx_redirectlifecycle_delete_after']);
        self::assertSame(1, (int)$record['tx_redirectlifecycle_mode']);
        self::assertSame(0, (int)$record['deleted']);
        $this->assertCurrentCache($uid, $record);
    }

    public function testParallelRestorationRestartsOnlyOnceAndLeavesCurrentCache(): void
    {
        $uid = $this->createRedirect();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['sys_redirect' => [$uid => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog);
        $now = (new \DateTimeImmutable('2026-04-11 UTC'))->getTimestamp();
        $this->parallel(4, function (int $worker) use ($uid, $now): void {
            $this->setTime($now + $worker);
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], ['sys_redirect' => [$uid => ['undelete' => 1]]]);
            $dataHandler->process_cmdmap();
            if ($dataHandler->errorLog !== []) {
                throw new \RuntimeException(implode("\n", $dataHandler->errorLog));
            }
        });
        $record = BackendUtility::getRecord('sys_redirect', $uid);
        self::assertNotNull($record);
        self::assertSame(0, (int)$record['deleted']);
        self::assertSame(1, (int)$record['tx_redirectlifecycle_mode']);
        self::assertSame(0, (int)$record['protected']);
        self::assertSame(0, (int)$record['disabled']);
        self::assertContains((int)$record['endtime'], range($now + 10 * 86400, $now + 10 * 86400 + 3));
        self::assertSame((int)$record['endtime'] + 90 * 86400, (int)$record['tx_redirectlifecycle_delete_after']);
        self::assertSame(1, $this->get(ConnectionPool::class)->getConnectionForTable('sys_history')->count('*', 'sys_history', [
            'tablename' => 'sys_redirect', 'recuid' => $uid, 'actiontype' => 5,
        ]));
        $this->assertCurrentCache($uid, $record);
    }

    public function testLegacyEmptyHostRenewsTheWildcardCacheUsedByCore(): void
    {
        $uid = $this->createRedirect();
        // Legacy installations may store an empty host instead of the current '*' default.
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_redirect')->update('sys_redirect', ['source_host' => ''], ['uid' => $uid]);
        // The direct SQL import bypasses native invalidation; publish the imported state first.
        $this->get(RedirectCacheService::class)->rebuildForHost('*');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new HtmlResponse('', 404);
            }
        };
        $this->setTime((new \DateTimeImmutable('2026-01-02 UTC'))->getTimestamp());
        self::assertSame(307, $this->get(RedirectHandler::class)->process(new ServerRequest('https://other.test/old', 'GET'), $handler)->getStatusCode());
        // The populated wildcard cache must remain usable beyond the original ten-day expiry.
        $this->setTime((new \DateTimeImmutable('2026-01-12 UTC'))->getTimestamp());
        self::assertTrue($this->get(CacheManager::class)->getCache('pages')->has('redirects_' . sha1('*')));
        self::assertSame(307, $this->get(RedirectHandler::class)->process(new ServerRequest('https://other.test/old', 'GET'), $handler)->getStatusCode());
        $record = BackendUtility::getRecord('sys_redirect', $uid);
        $cached = $this->get(RedirectCacheService::class)->getRedirects('*')['flat']['/old/'][$uid];
        self::assertSame((int)$record['endtime'], (int)$cached['endtime']);
        self::assertSame((int)$record['tx_redirectlifecycle_delete_after'], (int)$cached['tx_redirectlifecycle_delete_after']);
    }

    #[DataProvider('concurrentChanges')]
    public function testCleanupRechecksChangesFromAnotherProcess(array $fields): void
    {
        $uid = $this->createRedirect();
        $this->setTime((new \DateTimeImmutable('2026-04-11 UTC'))->getTimestamp());
        $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertNotFalse($barrier);
        foreach ($barrier as $socket) {
            stream_set_timeout($socket, 10);
        }
        try {
            $this->parallel(2, function (int $worker) use ($uid, $fields, $barrier): void {
                if ($worker === 1) {
                    if (fgets($barrier[1]) !== "selected\n") {
                        throw new \RuntimeException('Cleanup selection barrier timed out.');
                    }
                    $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                    $dataHandler->start(['sys_redirect' => [$uid => $fields]], []);
                    $dataHandler->process_datamap();
                    if ($dataHandler->errorLog !== []) {
                        throw new \RuntimeException(implode("\n", $dataHandler->errorLog));
                    }
                    fwrite($barrier[1], "changed\n");
                    return;
                }
                $output = new class($barrier[0]) extends BufferedOutput {
                    private bool $selected = false;

                    public function __construct(private readonly mixed $barrier)
                    {
                        parent::__construct();
                    }

                    protected function doWrite(string $message, bool $newline): void
                    {
                        if (!$this->selected && str_contains($message, '/old')) {
                            $this->selected = true;
                            fwrite($this->barrier, "selected\n");
                            if (fgets($this->barrier) !== "changed\n") {
                                throw new \RuntimeException('Backend change barrier timed out.');
                            }
                        }
                        parent::doWrite($message, $newline);
                    }
                };
                $command = $this->get(CommandRegistry::class)->getCommandByIdentifier('redirect-lifecycle:cleanup');
                if ($command->run(new ArrayInput([]), $output) !== 0) {
                    throw new \RuntimeException('Cleanup failed.');
                }
            });
        } finally {
            fclose($barrier[0]);
            fclose($barrier[1]);
        }
        $record = BackendUtility::getRecord('sys_redirect', $uid, '*', '', false);
        self::assertSame(0, (int)$record['deleted']);
        foreach ($fields as $field => $value) {
            self::assertSame($value, (int)$record[$field]);
        }
        if (!empty($fields['disabled'])) {
            self::assertArrayNotHasKey('flat', $this->get(RedirectCacheService::class)->getRedirects('parallel.test'));
        } else {
            $this->assertCurrentCache($uid, $record);
        }
    }

    public static function concurrentChanges(): array
    {
        return [
            'protection' => [['protected' => 1]],
            'disabling' => [['disabled' => 1]],
            'ending management' => [['tx_redirectlifecycle_mode' => 0]],
            'fixed expiry' => [['tx_redirectlifecycle_mode' => 2, 'endtime' => 2000000000]],
        ];
    }

    private function assertCurrentCache(int $uid, array $record): void
    {
        $cached = $this->get(RedirectCacheService::class)->getRedirects('parallel.test')['flat']['/old/'][$uid];
        self::assertSame((int)$record['endtime'], (int)$cached['endtime']);
        self::assertSame((int)$record['tx_redirectlifecycle_delete_after'], (int)$cached['tx_redirectlifecycle_delete_after']);
    }

    private function parallel(int $count, \Closure $operation): void
    {
        // Close before forking: each child must open its own SQLite connection.
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('sys_redirect')->close();
        $pool->resetConnections();
        $workers = [];
        try {
            for ($worker = 0; $worker < $count; ++$worker) {
                $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                self::assertNotFalse($sockets);
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if ($pid === 0) {
                    fclose($sockets[0]);
                    fwrite($sockets[1], "ready\n");
                    fgets($sockets[1]);
                    try {
                        $operation($worker);
                        fwrite($sockets[1], "ok\n");
                        exit(0);
                    } catch (\Throwable $exception) {
                        fwrite($sockets[1], get_class($exception) . ': ' . $exception->getMessage() . "\n");
                        exit(1);
                    }
                }
                fclose($sockets[1]);
                stream_set_timeout($sockets[0], 30);
                $workers[$pid] = $sockets[0];
                self::assertSame("ready\n", fgets($sockets[0]));
            }
            foreach ($workers as $socket) {
                fwrite($socket, "go\n");
            }
            foreach ($workers as $pid => $socket) {
                self::assertSame("ok\n", fgets($socket), 'Child process failed.');
                self::assertSame($pid, pcntl_waitpid($pid, $status));
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
                fclose($socket);
                unset($workers[$pid]);
            }
        } finally {
            foreach ($workers as $pid => $socket) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                fclose($socket);
            }
        }
    }
}
