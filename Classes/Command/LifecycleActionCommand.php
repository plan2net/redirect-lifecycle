<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Command;

use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class LifecycleActionCommand extends Command
{
    private const INVALID_REDIRECT_UID = 1791014403;

    private readonly LanguageService $languageService;

    public function __construct(
        private readonly bool $adopt,
        private readonly ConnectionPool $connectionPool,
        private readonly RedirectLifecycle $lifecycle,
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
                throw new \InvalidArgumentException($this->label('invalidUid'), self::INVALID_REDIRECT_UID);
            }
            $uids[] = (int)$uid;
        }
        $uids = array_values(array_unique($uids));
        $query = $this->connectionPool->getQueryBuilderForTable('sys_redirect');
        $query->getRestrictions()->removeAll();
        $query->select(
            'uid', 'source_host', 'source_path', 'tx_redirectlifecycle_mode',
            'deleted', 'protected', 'disabled', 'starttime', 'endtime',
        )->from('sys_redirect')->orderBy('uid');
        if (isset($GLOBALS['TCA']['sys_redirect']['columns']['redirect_type'])) {
            $query->addSelect('redirect_type');
        }
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
            fn(array $record): array => [$record['uid'], $record['source_host'], $record['source_path'], $this->label($this->lifecycle->actionReason($record, $this->adopt))],
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
            $reason = $this->adopt ? $this->lifecycle->adopt($uid) : $this->lifecycle->renew($uid);
            if ($reason !== null) {
                $io->note(sprintf($this->label('skipped'), $uid, $this->label($reason)));
                continue;
            }
            ++$applied;
        }
        $io->success(sprintf($this->label('applied'), $applied));
        return Command::SUCCESS;
    }

    private function label(string $key): string
    {
        return $this->languageService->sL('LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:action.' . $key);
    }
}
