<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\ImportReport;
use App\Import\ThemeImporter;
use App\Service\ThemeCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:quiz:import', description: 'Import quiz themes from data/themes/<slug>/<slug>.json + xlsx into the database')]
final class ImportThemesCommand extends Command
{
    private const MAX_ERRORS_SHOWN = 50;

    public function __construct(private readonly ThemeImporter $importer, private readonly ThemeCatalog $catalog)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('theme', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Theme slug(s); all themes when omitted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $slugs = $input->getArgument('theme');
        if ($slugs === []) {
            $slugs = $this->catalog->slugs();
        }
        if ($slugs === []) {
            $io->warning('No themes found (expected data/themes/<slug>/<slug>.json).');

            return Command::SUCCESS;
        }

        $failed = false;
        foreach ($slugs as $slug) {
            $io->section($slug);
            $report = $this->importer->import($slug);
            $this->render($io, $report);
            $failed = $failed || !$report->ok();
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function render(SymfonyStyle $io, ImportReport $report): void
    {
        foreach ($report->warnings as $w) {
            $io->writeln('<comment>warning:</comment> ' . $w);
        }

        if (!$report->ok()) {
            $shown = array_slice($report->errors, 0, self::MAX_ERRORS_SHOWN);
            foreach ($shown as $e) {
                $io->writeln('<error> error </error> ' . $e);
            }
            if (count($report->errors) > count($shown)) {
                $io->writeln(sprintf('... and %d more error(s)', count($report->errors) - count($shown)));
            }
            $io->error(sprintf('Theme "%s" was NOT imported (the database is unchanged).', $report->theme));

            return;
        }

        $io->success(sprintf('Imported %d item(s).', $report->items));
        $io->table(
            ['mode', 'title', 'eligible items', 'max options', 'playable'],
            array_map(
                static fn (array $m): array => [$m['slug'], $m['title'], $m['eligible'], $m['maxOptions'], $m['usable'] ? 'yes' : 'NO'],
                $report->modes,
            ),
        );
    }
}
