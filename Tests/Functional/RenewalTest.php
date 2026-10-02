<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Tests\Functional;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Plan2net\RedirectLifecycle\Tests\Functional\Fixtures\FailingCacheBackend;
use Plan2net\RedirectLifecycle\Tests\Functional\Support\TestClock;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Http\Middleware\RedirectHandler;
use TYPO3\CMS\Redirects\Event\RedirectWasHitEvent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RenewalTest extends FunctionalTestCase
{
    use TestClock;

    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['plan2net/redirect-lifecycle'];
    // Keep the native cache warm across time jumps to isolate lifecycle-triggered writes.
    protected array $configurationToUseInTestInstance = [
        'SYS' => ['caching' => ['cacheConfigurations' => ['pages' => ['backend' => FailingCacheBackend::class, 'options' => ['defaultLifetime' => 0]]]]],
    ];

    protected function setUp(): void
    {
        FailingCacheBackend::$transactionFailure = FailingCacheBackend::$outsideFailure = null;
        FailingCacheBackend::$attempts = [];
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Creation.csv');
        $user = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
        $this->setTime('2026-01-01 00:00:00 UTC');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '10', 'cleanupGracePeriod' => '90'];
    }

    protected function tearDown(): void
    {
        FailingCacheBackend::$transactionFailure = FailingCacheBackend::$outsideFailure = null;
        FailingCacheBackend::$attempts = [];
        parent::tearDown();
    }

    #[DataProvider('disabledHitCounting')]
    public function testValidRequestRenewsToOneHundredEightyDaysWithoutHitCounting(int $disableHitcount, bool $globalFeature): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['redirects.hitCount'] = $globalFeature;
        $record = $this->createRedirect(['disable_hitcount' => $disableHitcount]);
        $response = $this->request();
        self::assertSame(307, $response->getStatusCode());
        self::assertSame('https://target.test/new', $response->getHeaderLine('Location'));
        $updated = BackendUtility::getRecord('sys_redirect', $record['uid']);
        self::assertSame($this->timestamp('2026-06-30'), (int)$updated['endtime']);
        self::assertSame($this->timestamp('2026-09-28'), (int)$updated['tx_redirectlifecycle_delete_after']);
        self::assertSame(0, (int)$updated['hitcount']);
    }

    public static function disabledHitCounting(): array
    {
        return ['record setting' => [1, true], 'global feature' => [0, false]];
    }

    public function testLongRemainingLifetimeDoesNotRecalculateDates(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = '200';
        $record = $this->createRedirect();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['cleanupGracePeriod'] = '365';
        self::assertSame(307, $this->request()->getStatusCode());
        $updated = BackendUtility::getRecord('sys_redirect', $record['uid']);
        self::assertSame($record['endtime'], $updated['endtime']);
        self::assertSame($record['tx_redirectlifecycle_delete_after'], $updated['tx_redirectlifecycle_delete_after']);
    }

    public function testRenewalDoesNotMoveDeletionEarlierWhenGraceWasReduced(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['cleanupGracePeriod'] = '365';
        $record = $this->createRedirect();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['cleanupGracePeriod'] = '0';
        self::assertSame(307, $this->request()->getStatusCode());
        $updated = BackendUtility::getRecord('sys_redirect', $record['uid']);
        self::assertSame($this->timestamp('2026-06-30'), (int)$updated['endtime']);
        self::assertSame($record['tx_redirectlifecycle_delete_after'], $updated['tx_redirectlifecycle_delete_after']);
    }

    #[DataProvider('expiryBoundary')]
    public function testCoreExpiryBoundary(string $hitTime, int $status, bool $renews): void
    {
        $record = $this->createRedirect();
        $this->setTime($hitTime);
        self::assertSame($status, $this->request()->getStatusCode());
        $updated = BackendUtility::getRecord('sys_redirect', $record['uid']);
        self::assertSame($renews ? $this->timestamp('2026-07-10') : (int)$record['endtime'], (int)$updated['endtime']);
        if ($renews) {
            self::assertSame($this->timestamp('2026-10-08'), (int)$updated['tx_redirectlifecycle_delete_after']);
            $this->setTime('2026-01-11 00:00:01 UTC');
            self::assertSame(307, $this->request()->getStatusCode());
        } else {
            self::assertSame($record['tx_redirectlifecycle_delete_after'], $updated['tx_redirectlifecycle_delete_after']);
        }
    }

    public static function expiryBoundary(): array
    {
        return [
            'final valid second' => ['2026-01-11 00:00:00 UTC', 307, true],
            'first expired second' => ['2026-01-11 00:00:01 UTC', 404, false],
        ];
    }

    #[DataProvider('excludedRedirects')]
    public function testIneligibleRedirectsKeepTheirDates(array $fields, string $ttl): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = $ttl;
        if (isset($fields['redirect_type']) && !isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
            self::markTestSkipped('Redirect types were introduced in TYPO3 14.');
        }
        $record = $this->createRedirect($fields);
        $this->request();
        $updated = BackendUtility::getRecord('sys_redirect', $record['uid']);
        self::assertSame($record['endtime'], $updated['endtime']);
        self::assertSame($record['tx_redirectlifecycle_delete_after'], $updated['tx_redirectlifecycle_delete_after']);
        self::assertSame($record['tx_redirectlifecycle_mode'], $updated['tx_redirectlifecycle_mode']);
    }

    public static function excludedRedirects(): array
    {
        return [
            'unlimited managed' => [[], '0'],
            'protected managed' => [['protected' => 1], '10'],
            'explicitly unmanaged' => [['tx_redirectlifecycle_mode' => 0], '10'],
            'fixed expiry' => [['endtime' => 1768089600], '10'],
            'manually disabled' => [['disabled' => 1], '10'],
            'not started' => [['starttime' => 1768089600], '10'],
            'QR code' => [['redirect_type' => 'qrcode'], '10'],
            'short URL' => [['redirect_type' => 'short_url'], '10'],
        ];
    }

    public function testOlderInFlightHitCannotShortenDatesWrittenByLaterHit(): void
    {
        $record = $this->createRedirect(['disable_hitcount' => 1]);
        $event = $this->hitEvent($record);
        $this->setTime('2026-01-03 00:00:00 UTC');
        self::assertSame(307, $this->request()->getStatusCode());
        $updated = BackendUtility::getRecord('sys_redirect', $record['uid']);
        self::assertSame($this->timestamp('2026-07-02'), (int)$updated['endtime']);
        self::assertSame($this->timestamp('2026-09-30'), (int)$updated['tx_redirectlifecycle_delete_after']);
        $this->setTime('2026-01-02 00:00:00 UTC');
        $this->get(EventDispatcherInterface::class)->dispatch($event);
        $after = BackendUtility::getRecord('sys_redirect', $record['uid']);
        self::assertSame($updated['endtime'], $after['endtime']);
        self::assertSame($updated['tx_redirectlifecycle_delete_after'], $after['tx_redirectlifecycle_delete_after']);
    }

    #[DataProvider('inFlightChanges')]
    public function testInFlightHitRechecksCurrentEligibility(array $fields, bool $delete): void
    {
        if (isset($fields['redirect_type']) && !isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
            self::markTestSkipped('Redirect types were introduced in TYPO3 14.');
        }
        $record = $this->createRedirect(['disable_hitcount' => 1]);
        $event = $this->hitEvent($record);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(
            $delete ? [] : ['sys_redirect' => [$record['uid'] => $fields]],
            $delete ? ['sys_redirect' => [$record['uid'] => ['delete' => 1]]] : [],
        );
        if ($delete) {
            $dataHandler->process_cmdmap();
        } else {
            $dataHandler->process_datamap();
        }
        self::assertSame([], $dataHandler->errorLog);
        $current = BackendUtility::getRecord('sys_redirect', $record['uid'], '*', '', false);
        $this->get(EventDispatcherInterface::class)->dispatch($event);
        $after = BackendUtility::getRecord('sys_redirect', $record['uid'], '*', '', false);
        self::assertSame($current['endtime'], $after['endtime']);
        self::assertSame($current['tx_redirectlifecycle_delete_after'], $after['tx_redirectlifecycle_delete_after']);
        self::assertSame($current['tx_redirectlifecycle_mode'], $after['tx_redirectlifecycle_mode']);
    }

    public static function inFlightChanges(): array
    {
        return [
            'protection' => [['protected' => 1], false],
            'manual disabling' => [['disabled' => 1], false],
            'ending management' => [['tx_redirectlifecycle_mode' => 0], false],
            'fixed expiry' => [['tx_redirectlifecycle_mode' => 2, 'endtime' => 1768089600], false],
            'soft deletion' => [[], true],
            'QR code type' => [['redirect_type' => 'qrcode'], false],
        ];
    }

    #[DataProvider('invalidMinimums')]
    public function testInvalidMinimumRemainingLifetimeIsRejected(mixed $value): void
    {
        $this->createRedirect();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['minimumRemainingLifetime'] = $value;
        $this->expectException(\InvalidArgumentException::class);
        $this->request();
    }

    public static function invalidMinimums(): array
    {
        return [[0], ['-1'], ['1.5'], [true], [null], [50000]];
    }

    public function testCurrentConfiguredRenewalLifetimeIsUsed(): void
    {
        $record = $this->createRedirect();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['minimumRemainingLifetime'] = '30';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['renewalLifetime'] = '60';
        self::assertSame(307, $this->request()->getStatusCode());
        $updated = BackendUtility::getRecord('sys_redirect', $record['uid']);
        self::assertSame($this->timestamp('2026-03-02'), (int)$updated['endtime']);
        self::assertSame($this->timestamp('2026-05-31'), (int)$updated['tx_redirectlifecycle_delete_after']);
    }

    public function testConfiguredMinimumControlsTheRenewalThreshold(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] += [
            'minimumRemainingLifetime' => '30', 'renewalLifetime' => '60',
        ];
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = '45';
        $record = $this->createRedirect(['disable_hitcount' => 1]);
        FailingCacheBackend::$attempts = [];
        foreach (['2026-01-01 UTC', '2026-01-16 UTC'] as $time) {
            $this->setTime($time);
            self::assertSame(307, $this->request()->getStatusCode());
            self::assertSame($record, BackendUtility::getRecord('sys_redirect', $record['uid']));
        }
        self::assertSame([], FailingCacheBackend::$attempts);
        $this->setTime('2026-01-16 00:00:01 UTC');
        self::assertSame(307, $this->request()->getStatusCode());
        $updated = BackendUtility::getRecord('sys_redirect', $record['uid']);
        self::assertSame($this->timestamp('2026-03-17') + 1, (int)$updated['endtime']);
        self::assertSame($this->timestamp('2026-06-15') + 1, (int)$updated['tx_redirectlifecycle_delete_after']);
        self::assertSame([0], FailingCacheBackend::$attempts);
    }

    public function testRenewalAndGraceUseFixedSecondsAcrossDaylightSavingChanges(): void
    {
        $this->setTime('2026-10-24 12:00:00 Europe/Vienna');
        $record = $this->createRedirect();
        self::assertSame(307, $this->request()->getStatusCode());
        $updated = BackendUtility::getRecord('sys_redirect', $record['uid']);
        self::assertSame((new \DateTimeImmutable('2027-04-22 10:00:00 UTC'))->getTimestamp(), (int)$updated['endtime']);
        self::assertSame((new \DateTimeImmutable('2027-07-21 10:00:00 UTC'))->getTimestamp(), (int)$updated['tx_redirectlifecycle_delete_after']);
    }

    public function testRepeatedHitsDoNotWriteCacheUntilBelowMinimumRemainingLifetime(): void
    {
        $record = $this->createRedirect(['disable_hitcount' => 1]);
        FailingCacheBackend::$attempts = [];
        self::assertSame(307, $this->request()->getStatusCode());
        self::assertSame([0], FailingCacheBackend::$attempts);
        $renewed = BackendUtility::getRecord('sys_redirect', $record['uid']);
        FailingCacheBackend::$attempts = [];
        foreach (['2026-01-01 00:00:01 UTC', '2026-03-31 23:59:59 UTC', '2026-04-01 00:00:00 UTC'] as $time) {
            $this->setTime($time);
            self::assertSame(307, $this->request()->getStatusCode());
            self::assertSame($renewed, BackendUtility::getRecord('sys_redirect', $record['uid']));
        }
        self::assertSame([], FailingCacheBackend::$attempts);
        $this->setTime('2026-04-01 00:00:01 UTC');
        self::assertSame(307, $this->request()->getStatusCode());
        self::assertSame([0], FailingCacheBackend::$attempts);
        $updated = BackendUtility::getRecord('sys_redirect', $record['uid']);
        self::assertSame($this->timestamp('2026-09-28') + 1, (int)$updated['endtime']);
        self::assertSame($this->timestamp('2026-12-27') + 1, (int)$updated['tx_redirectlifecycle_delete_after']);
    }

    #[DataProvider('invalidRenewalLifetimes')]
    public function testInvalidRenewalLifetimeIsRejectedWithoutChangingRecord(mixed $value): void
    {
        $record = $this->createRedirect();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['renewalLifetime'] = $value;
        FailingCacheBackend::$attempts = [];
        try {
            $this->request();
            self::fail('Invalid renewal lifetime must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('renewal', strtolower($exception->getMessage()));
        }
        self::assertSame($record, BackendUtility::getRecord('sys_redirect', $record['uid']));
        self::assertSame([], FailingCacheBackend::$attempts);
    }

    public static function invalidRenewalLifetimes(): array
    {
        return [[0], [89], [90], ['-1'], ['1.5'], [true], [null], [50000]];
    }

    private function hitEvent(array $record): RedirectWasHitEvent
    {
        return new RedirectWasHitEvent(
            new ServerRequest('https://example.test/old', 'GET'),
            (new HtmlResponse('', 307))->withHeader('Location', 'https://target.test/new'),
            $record,
            new Uri('https://target.test/new'),
        );
    }

    private function timestamp(string $date): int
    {
        return (new \DateTimeImmutable($date . ' 00:00:00 UTC'))->getTimestamp();
    }

    private function createRedirect(array $fields = []): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['sys_redirect' => ['NEWredirect' => array_replace([
            'pid' => 0, 'source_host' => 'example.test', 'source_path' => '/old',
            'target' => 'https://target.test/new', 'target_statuscode' => 307,
        ], $fields)]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        return BackendUtility::getRecord('sys_redirect', $dataHandler->substNEWwithIDs['NEWredirect']);
    }

    private function request(): ResponseInterface
    {
        return $this->get(RedirectHandler::class)->process(new ServerRequest('https://example.test/old', 'GET'), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new HtmlResponse('', 404);
            }
        });
    }
}
