<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Plan2net\RedirectLifecycle\Controller\RenewController;
use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use Plan2net\RedirectLifecycle\Tests\Functional\Fixtures\FailingCacheBackend;
use Plan2net\RedirectLifecycle\Tests\Functional\Support\TestClock;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class BackendRenewalTest extends FunctionalTestCase
{
    use TestClock;

    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['plan2net/redirect-lifecycle'];
    protected array $configurationToUseInTestInstance = [
        'SYS' => ['caching' => ['cacheConfigurations' => ['pages' => ['backend' => FailingCacheBackend::class]]]],
    ];

    protected function setUp(): void
    {
        FailingCacheBackend::$transactionFailure = FailingCacheBackend::$outsideFailure = null;
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Creation.csv');
        $user = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
        $this->setTime('2026-01-01 UTC');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle'] = ['redirectTTL' => '200', 'cleanupGracePeriod' => '7'];
        self::assertNull($this->get(RedirectLifecycle::class)->adopt(100));
        $this->setTime('2026-01-10 UTC');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = '5';
    }

    protected function tearDown(): void
    {
        FailingCacheBackend::$transactionFailure = FailingCacheBackend::$outsideFailure = null;
        parent::tearDown();
    }

    public function testPreviewShowsShorteningWithoutMutatingRecord(): void
    {
        $before = $this->record();
        $response = $this->get(RenewController::class)->handle($this->request());
        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();
        self::assertStringContainsString('This action shortens', $html);
        self::assertStringContainsString(BackendUtility::datetime($this->timestamp('2026-01-15')), $html);
        self::assertStringContainsString('method="post"', $html);
        self::assertSame($before, $this->record());
    }

    public function testConfirmationUsesNativeRenewalAndRefreshesDates(): void
    {
        $response = $this->get(RenewController::class)->handle($this->request('POST', $this->confirmation()));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('The lifetime was reset.', (string)$response->getBody());
        self::assertSame($this->timestamp('2026-01-15'), (int)$this->record()['endtime']);
        self::assertSame($this->timestamp('2026-01-22'), (int)$this->record()['tx_redirectlifecycle_delete_after']);
        self::assertSame(1, (int)$this->record()['tx_redirectlifecycle_mode']);
    }

    public function testLifecycleRejectsAnOutdatedPreviewWithoutControllerValidation(): void
    {
        $lifecycle = $this->get(RedirectLifecycle::class);
        $preview = $lifecycle->previewRenewal($this->record());
        $this->update(['protected' => 1]);
        $before = $this->record();
        self::assertSame('changed', $lifecycle->renew(100, $preview['state']));
        self::assertSame($before, $this->record());
    }

    public function testCancelReturnsToTheOriginalEditUrlWithoutNestingIt(): void
    {
        $returnUrl = '/typo3/record/edit?edit%5Bsys_redirect%5D%5B100%5D=edit&returnUrl=/typo3/module/site/redirects';
        $html = (string)$this->get(RenewController::class)->handle($this->request('GET', ['uid' => 100, 'returnUrl' => $returnUrl]))->getBody();
        self::assertStringContainsString('href="https://example.test' . htmlspecialchars($returnUrl, ENT_QUOTES) . '"', $html);
    }

    public function testExternalReturnUrlCannotLeaveTheBackend(): void
    {
        $html = (string)$this->get(RenewController::class)->handle($this->request('GET', ['uid' => 100, 'returnUrl' => 'https://outside.example/']))->getBody();
        self::assertStringNotContainsString('outside.example', $html);
        self::assertStringContainsString('typo3/record/edit', $html);
    }

    public function testZeroTtlPreviewAndConfirmationProduceUnlimitedLifetime(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = '0';
        $preview = (string)$this->get(RenewController::class)->handle($this->request())->getBody();
        self::assertStringContainsString('Unlimited', $preview);
        self::assertStringNotContainsString('This action shortens', $preview);
        self::assertSame(200, $this->get(RenewController::class)->handle($this->request('POST', $this->confirmation()))->getStatusCode());
        self::assertSame(0, (int)$this->record()['endtime']);
        self::assertSame(0, (int)$this->record()['tx_redirectlifecycle_delete_after']);
    }

    public function testUnlimitedToFinitePreviewWarnsAboutShortening(): void
    {
        $this->update(['endtime' => 0, 'tx_redirectlifecycle_delete_after' => 0]);
        self::assertStringContainsString('This action shortens', (string)$this->get(RenewController::class)->handle($this->request())->getBody());
    }

    #[DataProvider('invalidConfirmations')]
    public function testInvalidConfirmationCannotMutateRecord(array $changes): void
    {
        $before = $this->record();
        $response = $this->get(RenewController::class)->handle($this->request('POST', array_replace($this->confirmation(), $changes)));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame($before, $this->record());
    }

    public static function invalidConfirmations(): array
    {
        return [[['formToken' => 'invalid']], [['formToken' => '']], [['state' => 'tampered']]];
    }

    #[DataProvider('changedPreviews')]
    public function testChangedPreviewRequiresAnotherConfirmation(bool $configuration): void
    {
        $input = $this->confirmation();
        if ($configuration) {
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['redirect_lifecycle']['redirectTTL'] = '10';
        } else {
            $this->update(['protected' => 1]);
        }
        $before = $this->record();
        $response = $this->get(RenewController::class)->handle($this->request('POST', $input));
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('Review the updated preview', (string)$response->getBody());
        self::assertSame($before, $this->record());
    }

    public static function changedPreviews(): array
    {
        return [[true], [false]];
    }

    public function testReviewTimeDoesNotInvalidateConfirmation(): void
    {
        $input = $this->confirmation();
        $this->setTime('2026-01-10 00:01:00 UTC');
        self::assertSame(200, $this->get(RenewController::class)->handle($this->request('POST', $input))->getStatusCode());
        self::assertSame($this->timestamp('2026-01-15') + 60, (int)$this->record()['endtime']);
    }

    public function testCacheFailureAfterCommitReportsSavedChangeWithoutRepeatingRenewal(): void
    {
        $input = $this->confirmation();
        FailingCacheBackend::$outsideFailure = new \RuntimeException('Cache unavailable.');
        FailingCacheBackend::$attempts = [];
        $response = $this->get(RenewController::class)->handle($this->request('POST', $input));
        self::assertStringContainsString('was saved, but cache rebuilding failed', (string)$response->getBody());
        self::assertSame($this->timestamp('2026-01-15'), (int)$this->record()['endtime']);
        self::assertCount(1, array_filter(FailingCacheBackend::$attempts, static fn(int $level): bool => $level === 0));
    }

    public function testMissingFieldPermissionHidesButtonAndDeniesDirectRequests(): void
    {
        $input = $this->confirmation();
        $before = $this->record();
        $GLOBALS['EXEC_TIME'] = time();
        $this->setUpBackendUser(2);
        $this->setTime('2026-01-10 UTC');
        self::assertSame([], $this->button());
        foreach (['GET', 'POST'] as $method) {
            self::assertSame(403, $this->get(RenewController::class)->handle($this->request($method, $input))->getStatusCode());
        }
        self::assertSame($before, $this->record());
    }

    public function testEditorWithFieldPermissionCanConfirmRenewal(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups', [
            'non_exclude_fields' => 'sys_redirect:tx_redirectlifecycle_mode',
        ], ['uid' => 1]);
        $GLOBALS['EXEC_TIME'] = time();
        $this->setUpBackendUser(2);
        $this->setTime('2026-01-10 UTC');
        self::assertNotSame([], $this->button());
        $response = $this->get(RenewController::class)->handle($this->request('POST', $this->confirmation()));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('The lifetime was reset.', (string)$response->getBody());
        self::assertSame($this->timestamp('2026-01-15'), (int)$this->record()['endtime']);
    }

    public function testMissingTablePermissionDeniesRenewalEvenWithFieldPermission(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups', [
            'tables_modify' => 'pages', 'non_exclude_fields' => 'sys_redirect:tx_redirectlifecycle_mode',
        ], ['uid' => 1]);
        $GLOBALS['EXEC_TIME'] = time();
        $this->setUpBackendUser(2);
        $this->setTime('2026-01-10 UTC');
        $before = $this->record();
        self::assertSame([], $this->button());
        self::assertSame(403, $this->get(RenewController::class)->handle($this->request())->getStatusCode());
        self::assertSame($before, $this->record());
    }

    #[DataProvider('pagePermissions')]
    public function testPagePermissionsAgreeForButtonPreviewAndConfirmation(bool $allowed): void
    {
        $this->update(['pid' => 4]);
        $input = $this->confirmation();
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups', [
            'non_exclude_fields' => 'sys_redirect:tx_redirectlifecycle_mode',
        ], ['uid' => 1]);
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', [
            'perms_user' => $allowed ? 31 : 1,
        ], ['uid' => 4]);
        $GLOBALS['EXEC_TIME'] = time();
        $this->setUpBackendUser(2);
        $this->setTime('2026-01-10 UTC');
        $before = $this->record();
        self::assertSame($allowed, $this->button() !== []);
        self::assertSame($allowed ? 200 : 403, $this->get(RenewController::class)->handle($this->request())->getStatusCode());
        if ($allowed) {
            $input = $this->confirmation();
        }
        self::assertSame($allowed ? 200 : 403, $this->get(RenewController::class)->handle($this->request('POST', $input))->getStatusCode());
        if ($allowed) {
            self::assertSame($this->timestamp('2026-01-15'), (int)$this->record()['endtime']);
        } else {
            self::assertSame($before, $this->record());
        }
    }

    public static function pagePermissions(): array
    {
        return ['content edit allowed' => [true], 'read only page' => [false]];
    }

    public function testConfirmationCannotBeReusedForAnotherRedirect(): void
    {
        $input = $this->confirmation();
        $other = $this->record();
        $other['uid'] = 101;
        $other['source_path'] = '/other';
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->insert('sys_redirect', $other);
        $input['uid'] = 101;
        self::assertSame(403, $this->get(RenewController::class)->handle($this->request('POST', $input))->getStatusCode());
        self::assertSame((int)$other['endtime'], (int)BackendUtility::getRecord('sys_redirect', 101)['endtime']);
    }

    #[DataProvider('ineligibleRecords')]
    public function testIneligibleRecordsHideButtonAndDenyRequests(array $fields): void
    {
        if (isset($fields['redirect_type']) && !isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
            self::markTestSkipped('Redirect types were introduced in TYPO3 14.');
        }
        $this->update($fields);
        $before = $this->record();
        self::assertSame([], $this->button());
        self::assertNotSame(200, $this->get(RenewController::class)->handle($this->request())->getStatusCode());
        self::assertSame($before, $this->record());
    }

    public static function ineligibleRecords(): array
    {
        return [[['tx_redirectlifecycle_mode' => 0]], [['tx_redirectlifecycle_mode' => 2]], [['deleted' => 1]],
            [['redirect_type' => 'qrcode']], [['redirect_type' => 'short_url']],
        ];
    }

    public function testNativeFormControlLinksOnlySavedManagedRecordsToPreview(): void
    {
        $button = $this->button();
        self::assertSame('actions-refresh', $button['iconIdentifier']);
        self::assertStringContainsString('redirect-lifecycle/renew', $button['linkAttributes']['href']);
        self::assertStringContainsString('uid=100', $button['linkAttributes']['href']);
        self::assertSame('Reset lifetime', $button['linkAttributes']['aria-label']);
        self::assertSame([], $this->button('new'));
    }

    private function button(string $command = 'edit'): array
    {
        return GeneralUtility::makeInstance(NodeFactory::class)->create([
            'renderType' => 'redirectLifecycleRenew', 'tableName' => 'sys_redirect',
            'command' => $command, 'databaseRow' => $this->record(), 'returnUrl' => '',
        ])->render();
    }

    private function confirmation(): array
    {
        $html = (string)$this->get(RenewController::class)->handle($this->request())->getBody();
        preg_match_all('/name="([^"]+)" value="([^"]*)"/', $html, $matches);
        return array_combine($matches[1], array_map(static fn(string $value): string => html_entity_decode($value, ENT_QUOTES), $matches[2]));
    }

    private function request(string $method = 'GET', array $input = ['uid' => 100]): ServerRequest
    {
        $serverParams = ['HTTP_HOST' => 'example.test', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/index.php'];
        $request = (new ServerRequest('https://example.test/typo3/redirect-lifecycle/renew', $method, 'php://input', [], $serverParams))
            ->withAttribute('route', $this->get(Router::class)->getRoute('redirect_lifecycle_renew'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('backend.user', $GLOBALS['BE_USER']);
        $params = new NormalizedParams($serverParams, $GLOBALS['TYPO3_CONF_VARS']['SYS'], '/var/www/html/public/index.php', '/var/www/html/public');
        $request = $request->withAttribute('normalizedParams', $params);
        if ((new Typo3Version())->getMajorVersion() < 14) {
            GeneralUtility::setIndpEnv('TYPO3_REQUEST_HOST', $params->getRequestHost());
            GeneralUtility::setIndpEnv('TYPO3_SITE_URL', $params->getSiteUrl());
            GeneralUtility::setIndpEnv('TYPO3_SITE_PATH', $params->getSitePath());
        }
        $request = $method === 'POST' ? $request->withParsedBody($input) : $request->withQueryParams($input);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $request;
    }

    private function record(): array
    {
        return BackendUtility::getRecord('sys_redirect', 100, '*', '', false);
    }

    private function update(array $fields): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->update('sys_redirect', $fields, ['uid' => 100]);
    }

    private function timestamp(string $date): int
    {
        return (new \DateTimeImmutable($date . ' UTC'))->getTimestamp();
    }
}
