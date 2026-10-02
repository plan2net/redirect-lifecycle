<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Command;

use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class CleanupCommand extends Command
{
    private readonly LanguageService $languageService;

    public function __construct(
        private readonly Context $context,
        private readonly RedirectLifecycle $lifecycle,
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
        $records = $this->lifecycle->cleanupCandidates($now);
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
            if ($this->lifecycle->cleanup((int)$candidate['uid'], $now) === null) {
                ++$deleted;
            }
        }
        $io->success(sprintf($this->label('deleted'), $deleted));
        return Command::SUCCESS;
    }

    private function label(string $key): string
    {
        return $this->languageService->sL('LLL:EXT:redirect_lifecycle/Resources/Private/Language/locallang.xlf:cleanup.' . $key);
    }
}
