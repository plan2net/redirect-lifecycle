<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Controller;

use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RenewController
{
    public function __construct(
        private readonly RedirectLifecycle $lifecycle,
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly FormProtectionFactory $formProtectionFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!in_array($request->getMethod(), ['GET', 'POST'], true)) {
            return new HtmlResponse('', 405);
        }
        $input = $request->getMethod() === 'POST' ? ($request->getParsedBody() ?? []) : $request->getQueryParams();
        if (!is_array($input)) {
            return new HtmlResponse('', 400);
        }
        $uid = filter_var($input['uid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($uid === false) {
            return new HtmlResponse('', 400);
        }
        $record = BackendUtility::getRecord('sys_redirect', $uid);
        if (!$record || !$this->canEdit($record)) {
            return new HtmlResponse(htmlspecialchars($this->label('denied')), 403);
        }
        $reason = $this->lifecycle->actionReason($record, false);
        if ($reason !== 'eligible') {
            return new HtmlResponse(htmlspecialchars($this->label('ineligible')), 409);
        }
        return $request->getMethod() === 'POST'
            ? $this->confirm($request, $record, $input)
            : $this->preview($request, $record, $input);
    }

    private function confirm(ServerRequestInterface $request, array $record, array $input): ResponseInterface
    {
        $uid = (int)$record['uid'];
        if (!is_string($input['state'] ?? null) || !is_string($input['formToken'] ?? null)
            || !$this->formProtectionFactory->createForType('backend')->validateToken($input['formToken'], 'redirectLifecycle', 'renew', $uid . ':' . $input['state'])
        ) {
            return new HtmlResponse(htmlspecialchars($this->label('invalidToken')), 403);
        }
        try {
            $reason = $this->lifecycle->renew($uid, $input['state']);
            $changed = $reason === 'changed';
            $feedback = [
                'message' => $this->label($reason === null ? 'applied' : ($changed ? 'changed' : 'ineligible')),
                'severity' => $reason === null ? 'success' : 'warning',
                'showConfirmation' => $changed,
                'status' => $changed ? 409 : 200,
            ];
            if ($changed) {
                $record = BackendUtility::getRecord('sys_redirect', $uid);
            }
        } catch (\RuntimeException $exception) {
            $feedback = ['message' => $exception->getMessage(), 'severity' => 'warning', 'showConfirmation' => false];
        }
        return $this->preview($request, $record, $input, $feedback);
    }

    private function preview(ServerRequestInterface $request, array $record, array $input, array $feedback = []): ResponseInterface
    {
        $uid = (int)$record['uid'];
        $preview = $this->lifecycle->previewRenewal($record);
        $returnUrl = is_string($input['returnUrl'] ?? null) ? $input['returnUrl'] : '';
        // Absolute local URLs avoid TYPO3 14's deprecated relative-path canonicalization.
        if ($returnUrl !== '' && !parse_url($returnUrl, PHP_URL_SCHEME)) {
            $params = $request->getAttribute('normalizedParams');
            $returnUrl = str_starts_with($returnUrl, '//') ? ''
                : (str_starts_with($returnUrl, '/') ? $params->getRequestHost() : $params->getSiteUrl()) . $returnUrl;
        }
        $returnUrl = GeneralUtility::sanitizeLocalUrl($returnUrl, $request);
        $module = $this->moduleTemplateFactory->create($request);
        $module->setTitle($this->label('renew'));
        $module->assignMultiple($feedback + [
            'record' => $record,
            'currentExpiry' => $this->formatExpiry((int)$record['endtime']),
            'newExpiry' => $this->formatExpiry((int)$preview['endtime']),
            'shortens' => $preview['shortens'],
            'formToken' => $this->formProtectionFactory->createForType('backend')->generateToken('redirectLifecycle', 'renew', $uid . ':' . $preview['state']),
            'state' => $preview['state'], 'returnUrl' => $returnUrl,
            'actionUrl' => (string)$this->uriBuilder->buildUriFromRoute('redirect_lifecycle_renew'),
            'backUrl' => $returnUrl ?: (string)$this->uriBuilder->buildUriFromRoute('record_edit', ['edit' => ['sys_redirect' => [$uid => 'edit']]]),
            'showConfirmation' => true,
            'message' => '', 'severity' => 'success',
        ]);
        return $module->renderResponse('Renew')->withStatus($feedback['status'] ?? 200);
    }

    private function canEdit(array $record): bool
    {
        $user = $GLOBALS['BE_USER'];
        if (empty($user->user['uid'])) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        if (!$user->check('tables_select', 'sys_redirect') || !$user->check('tables_modify', 'sys_redirect')
            || !$user->check('non_exclude_fields', 'sys_redirect:tx_redirectlifecycle_mode')
        ) {
            return false;
        }
        if ((int)$record['pid'] > 0 && !BackendUtility::getRecord('pages', (int)$record['pid'], 'uid', ' AND ' . $user->getPagePermsClause(Permission::CONTENT_EDIT))) {
            return false;
        }
        return method_exists($user, 'checkRecordEditAccess')
            ? $user->checkRecordEditAccess('sys_redirect', $record)->isAllowed
            : $user->recordEditAccessInternals('sys_redirect', $record);
    }

    private function formatExpiry(int $timestamp): string
    {
        return $timestamp === 0 ? $this->label('unlimited') : BackendUtility::datetime($timestamp);
    }

    private function label(string $key): string
    {
        return $GLOBALS['LANG']->sL('LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:backend.' . $key);
    }
}
