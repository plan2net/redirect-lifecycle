<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Plan2net\RedirectLifecycle\RedirectLifecycle;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;

final class LifecycleActionCommand extends Command
{
    private readonly LanguageService $languageService;

    public function __construct(
        private readonly bool $adopt,
        private readonly Context $context,
        private readonly ConnectionPool $connectionPool,
        private readonly RedirectCacheService $redirectCacheService,
        LanguageServiceFactory $languageServiceFactory,
    ) {
        $this->languageService = isset($GLOBALS['BE_USER'])
            ? $languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'])
            : $languageServiceFactory->create('default');
        parent::__construct('redirect-lifecycle:' . ($adopt ? 'adopt' : 'renew'));
    }

    protected function configure(): void
    {
        $this->setDescription($this->label($this->adopt ? 'adoptDescription' : 'renewDescription'));
        $this->addArgument('uids', InputArgument::IS_ARRAY | ($this->adopt ? InputArgument::OPTIONAL : InputArgument::REQUIRED), $this->label('uids'));
        $this->addOption('execute', null, InputOption::VALUE_NONE, $this->label('execute'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uids = [];
        foreach ($input->getArgument('uids') as $uid) {
            if (filter_var($uid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new \InvalidArgumentException($this->label('invalidUid'), 1791014403);
            }
            $uids[] = (int)$uid;
        }
        $uids = array_values(array_unique($uids));
        $query = $this->connectionPool->getQueryBuilderForTable('sys_redirect');
        $query->getRestrictions()->removeAll();
        $query->select('*')->from('sys_redirect')->orderBy('uid');
        if ($uids !== []) {
            $query->where($query->expr()->in('uid', $query->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)));
        }
        // ponytail: preview buffers records; paginate if large installations exceed CLI memory.
        $records = array_column($query->executeQuery()->fetchAllAssociative(), null, 'uid');
        foreach (array_diff($uids, array_keys($records)) as $uid) {
            $records[$uid] = ['uid' => $uid, 'source_host' => '', 'source_path' => ''];
        }
        $io = new SymfonyStyle($input, $output);
        $io->table(['UID', $this->label('host'), $this->label('path'), $this->label('status')], array_map(
            fn(array $record): array => [$record['uid'], $record['source_host'], $record['source_path'], $this->label($this->reason($record))],
            array_values($records),
        ));
        if (!$input->getOption('execute')) {
            $io->note($this->label('preview'));
            return Command::SUCCESS;
        }
        if ($records !== [] && empty($GLOBALS['BE_USER']->user['uid'])) {
            $GLOBALS['BE_USER'] = GeneralUtility::makeInstance(CommandLineUserAuthentication::class);
            $GLOBALS['BE_USER']->authenticate();
        }
        $GLOBALS['LANG'] ??= $this->languageService;
        $applied = 0;
        foreach ($records as $candidate) {
            $uid = (int)$candidate['uid'];
            $connection = $this->connectionPool->getConnectionForTable('sys_redirect');
            $record = $candidate;
            $connection->beginTransaction();
            try {
                $connection->update('sys_redirect', ['uid' => $uid], ['uid' => $uid]);
                $record = BackendUtility::getRecord('sys_redirect', $uid, '*', '', false) ?? ['uid' => $uid];
                $reason = $this->reason($record);
                if ($reason !== 'eligible') {
                    $connection->commit();
                    $io->note(sprintf($this->label('skipped'), $uid, $this->label($reason)));
                    continue;
                }
                if (!$GLOBALS['BE_USER']->isAdmin() && !$GLOBALS['BE_USER']->check('non_exclude_fields', 'sys_redirect:tx_redirectlifecycle_mode')) {
                    throw new \RuntimeException($this->label('permission'), 1791014404);
                }
                $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $dataHandler->start(['sys_redirect' => [$uid => [
                    'tx_redirectlifecycle_mode' => 1, 'source_host' => $record['source_host'], 'target' => $record['target'],
                ]]], []);
                if (!$this->adopt && $dataHandler->getCorrelationId() !== null) {
                    $dataHandler->setCorrelationId($dataHandler->getCorrelationId()->withAspects(RedirectLifecycle::RENEWAL_ASPECT));
                }
                $dataHandler->process_datamap();
                if ($dataHandler->errorLog !== []) {
                    throw new \RuntimeException(implode("\n", $dataHandler->errorLog), 1791014405);
                }
                $connection->commit();
            } catch (\Throwable $exception) {
                $connection->rollBack();
                $this->redirectCacheService->rebuildForHost($record['source_host'] ?? $candidate['source_host']);
                throw $exception;
            }
            $this->redirectCacheService->rebuildForHost($record['source_host']);
            ++$applied;
        }
        $io->success(sprintf($this->label('applied'), $applied));
        return Command::SUCCESS;
    }

    private function reason(array $record): string
    {
        if (!isset($record['tx_redirectlifecycle_mode'])) {
            return 'missing';
        }
        if ($record['deleted']) {
            return 'deleted';
        }
        if (($record['redirect_type'] ?? 'default') !== 'default') {
            return 'excludedType';
        }
        if ($this->adopt) {
            if ((int)$record['tx_redirectlifecycle_mode'] === 1) {
                return 'alreadyManaged';
            }
            if ((int)$record['tx_redirectlifecycle_mode'] !== 0 || (int)$record['endtime'] !== 0) {
                return 'fixed';
            }
            if ($record['protected']) {
                return 'protected';
            }
            if ($record['disabled']) {
                return 'disabled';
            }
            if ((int)$record['starttime'] > $this->context->getAspect('date')->getDateTime()->getTimestamp()) {
                return 'notStarted';
            }
        } elseif ((int)$record['tx_redirectlifecycle_mode'] !== 1) {
            return (int)$record['tx_redirectlifecycle_mode'] === 2 || (int)$record['endtime'] > 0 ? 'fixed' : 'unmanaged';
        }
        return 'eligible';
    }

    private function label(string $key): string
    {
        return $this->languageService->sL('LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:action.' . $key);
    }
}
