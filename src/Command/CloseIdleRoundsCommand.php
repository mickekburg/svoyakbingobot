<?php

declare(strict_types=1);

namespace App\Command;

use App\Bot\RoundJanitor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:quiz:close-idle', description: 'Close tours without activity (run from cron, e.g. every 10 minutes)')]
final class CloseIdleRoundsCommand extends Command
{
    public function __construct(private readonly RoundJanitor $janitor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('minutes', null, InputOption::VALUE_REQUIRED, 'Idle time after which a tour is closed', '30')
            ->addOption('notify', null, InputOption::VALUE_NONE, 'Send the player the summary of a closed tour');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $minutes = (int) $input->getOption('minutes');
        if ($minutes < 1) {
            $io->error('--minutes must be a positive number.');

            return Command::INVALID;
        }

        $closed = $this->janitor->closeIdle(new \DateTimeImmutable(), $minutes, (bool) $input->getOption('notify'));
        $io->writeln(sprintf('Closed %d idle tour(s).', $closed));

        return Command::SUCCESS;
    }
}
