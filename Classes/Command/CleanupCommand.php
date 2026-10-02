<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;

final class CleanupCommand extends Command
{
    private readonly LanguageService $languageService;

    public function __construct(
        private readonly Context $context,
        private readonly ConnectionPool $connectionPool,
        private readonly RedirectCacheService $redirectCacheService,
        LanguageServiceFactory $languageServiceFactory,
    ) {
        $this->languageService = isset($GLOBALS['BE_USER'])
            ? $languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'])
            : $languageServiceFactory->create('default');
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription($this->label('description'));
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, $this->label('dryRun'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $this->context->getAspect('date')->getDateTime()->getTimestamp();
        // ponytail: preview buffers candidates; paginate if large installations exceed CLI memory.
        $records = $this->candidates($now)->executeQuery()->fetchAllAssociative();
        $io = new SymfonyStyle($input, $output);
        $io->table(
            ['UID', $this->label('host'), $this->label('path'), $this->label('expiry'), $this->label('deletion')],
            array_map(static fn(array $row): array => [
                $row['uid'], $row['source_host'], $row['source_path'], $row['endtime'], $row['tx_redirectlifecycle_delete_after'],
            ], $records),
        );
        if ($input->getOption('dry-run')) {
            $io->success(sprintf($this->label('candidates'), count($records)));
            return Command::SUCCESS;
        }
        if ($records !== [] && empty($GLOBALS['BE_USER']->user['uid'])) {
            $GLOBALS['BE_USER'] = GeneralUtility::makeInstance(CommandLineUserAuthentication::class);
            $GLOBALS['BE_USER']->authenticate();
        }
        $GLOBALS['LANG'] ??= $this->languageService;
        $deleted = 0;
        foreach ($records as $candidate) {
            $uid = (int)$candidate['uid'];
            $record = $candidate;
            $connection = $this->connectionPool->getConnectionForTable('sys_redirect');
            $connection->beginTransaction();
            try {
                // A no-op write locks the row on every supported database, including SQLite.
                // Hold that lock from the fresh eligibility check through native DataHandler deletion.
                $connection->update('sys_redirect', ['uid' => $uid], ['uid' => $uid]);
                $query = $this->candidates($now);
                $record = $query->andWhere($query->expr()->eq('uid', $query->createNamedParameter($uid, Connection::PARAM_INT)))
                    ->executeQuery()->fetchAssociative();
                if (!$record) {
                    $connection->commit();
                    continue;
                }
                $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $dataHandler->start([], ['sys_redirect' => [$uid => ['delete' => 1]]]);
                $dataHandler->process_cmdmap();
                if ($dataHandler->errorLog !== []) {
                    throw new \RuntimeException(implode("\n", $dataHandler->errorLog), 1791014401);
                }
                $connection->commit();
            } catch (\Throwable $exception) {
                $connection->rollBack();
                $this->redirectCacheService->rebuildForHost($record['source_host'] ?? $candidate['source_host']);
                throw $exception;
            }
            // Native deletion rebuilds its cache inside the transaction; refresh again after commit.
            $this->redirectCacheService->rebuildForHost($record['source_host']);
            ++$deleted;
        }
        $io->success(sprintf($this->label('deleted'), $deleted));
        return Command::SUCCESS;
    }

    private function candidates(int $now): QueryBuilder
    {
        $query = $this->connectionPool->getQueryBuilderForTable('sys_redirect');
        // Expired records are the candidates, so native enable-field restrictions must be removed.
        $query->getRestrictions()->removeAll();
        $query->select('*')->from('sys_redirect')->orderBy('uid')->where(
            $query->expr()->eq('deleted', 0),
            $query->expr()->eq('disabled', 0),
            $query->expr()->eq('protected', 0),
            $query->expr()->eq('tx_redirectlifecycle_mode', 1),
            $query->expr()->gt('endtime', 0),
            $query->expr()->lt('endtime', $query->createNamedParameter($now, Connection::PARAM_INT)),
            $query->expr()->gte('tx_redirectlifecycle_delete_after', 'endtime'),
            $query->expr()->lte('tx_redirectlifecycle_delete_after', $query->createNamedParameter($now, Connection::PARAM_INT)),
        );
        if (isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
            $query->andWhere($query->expr()->eq('redirect_type', $query->createNamedParameter('default')));
        }
        return $query;
    }

    private function label(string $key): string
    {
        return $this->languageService->sL('LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:cleanup.' . $key);
    }
}
