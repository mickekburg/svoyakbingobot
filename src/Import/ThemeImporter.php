<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\QuizItem;
use App\Entity\QuizModeStat;
use App\Entity\QuizTheme;
use App\Quiz\ConfigException;
use App\Quiz\ItemRecord;
use App\Quiz\QuestionGenerator;
use App\Service\ThemeCatalog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * xlsx + JSON config -> database. All-or-nothing: if anything in the file is wrong, nothing is changed.
 * A successful import rebuilds the theme completely (items are identified by their Excel row number).
 */
final class ThemeImporter
{
    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
    private const BATCH = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThemeCatalog $catalog,
        private readonly ThemeTableParser $parser,
        private readonly XlsxReader $reader,
        private readonly QuestionGenerator $generator,
    ) {
    }

    public function import(string $slug): ImportReport
    {
        $report = new ImportReport($slug);

        try {
            $config = $this->catalog->configOrFail($slug);
        } catch (ConfigException $e) {
            $report->errors = $e->errors;

            return $report;
        }

        $xlsx = $this->catalog->sourcePath($config);
        if (!is_file($xlsx)) {
            $report->errors[] = sprintf('%s: file not found', $config->source);

            return $report;
        }

        try {
            $table = $this->reader->read($xlsx);
        } catch (\Throwable $e) {
            $report->errors[] = sprintf('%s: cannot read the spreadsheet (%s)', $config->source, $e->getMessage());

            return $report;
        }

        $parsed = $this->parser->parse(
            $config,
            $table['header'],
            $table['rows'],
            fn (string $path): ?string => $this->checkImage($this->catalog->imageAbsolutePath($config, $path), $path),
            $config->source,
        );
        $report->warnings = $parsed->warnings;
        if ($parsed->errors !== []) {
            $report->errors = $parsed->errors;

            return $report;
        }

        $records = [];
        foreach ($parsed->rows as $rowNum => $attrs) {
            $records[] = new ItemRecord($rowNum, $attrs);
        }
        $report->items = count($records);

        $stats = [];
        foreach ($config->modes as $mode) {
            $s = $this->generator->analyze($config, $mode, $records);
            $stats[$mode->slug] = $s;
            $usable = $s->isUsable($mode);
            $report->modes[] = [
                'slug' => $mode->slug,
                'title' => $mode->title,
                'maxOptions' => $s->maxOptions,
                'eligible' => $s->eligibleItems,
                'usable' => $usable,
            ];
            if (!$usable) {
                $report->warnings[] = sprintf(
                    'mode "%s" cannot be played: at most %d options possible (minimum is %d), %d eligible items — it will be hidden from players',
                    $mode->slug,
                    $s->maxOptions,
                    $mode->minOptions,
                    $s->eligibleItems,
                );
            }
        }

        $hash = sha1((string) file_get_contents($this->catalog->configPath($slug)) . '|' . sha1_file($xlsx));

        $this->em->wrapInTransaction(function () use ($config, $parsed, $stats, $hash): void {
            $theme = $this->em->getRepository(QuizTheme::class)->findOneBy(['slug' => $config->slug]) ?? new QuizTheme($config->slug, $config->title);
            $theme->setTitle($config->title);
            $theme->markImported($hash);
            $this->em->persist($theme);
            $this->em->flush();
            $themeId = (int) $theme->getId();

            $this->em->createQuery('DELETE FROM ' . QuizItem::class . ' i WHERE i.theme = :t')->setParameter('t', $theme)->execute();
            $this->em->createQuery('DELETE FROM ' . QuizModeStat::class . ' m WHERE m.theme = :t')->setParameter('t', $theme)->execute();

            $n = 0;
            foreach ($parsed->rows as $rowNum => $attrs) {
                $this->em->persist(new QuizItem($theme, $rowNum, $attrs));
                if (++$n % self::BATCH === 0) {
                    $this->em->flush();
                    $this->em->clear();
                    $theme = $this->em->getReference(QuizTheme::class, $themeId);
                }
            }
            foreach ($config->modes as $mode) {
                $s = $stats[$mode->slug];
                $this->em->persist(new QuizModeStat($theme, $mode->slug, $s->maxOptions, $s->eligibleItems));
            }
            $this->em->flush();
            $this->em->clear();
        });

        return $report;
    }

    /** @return string|null error text, null if the picture is fine for Telegram's sendPhoto */
    private function checkImage(string $absolute, string $relative): ?string
    {
        if (!is_file($absolute)) {
            return sprintf('image file not found: %s', $relative);
        }
        $size = filesize($absolute);
        if ($size === false || $size > self::MAX_IMAGE_BYTES) {
            return sprintf('image is larger than 10 MB (Telegram limit): %s', $relative);
        }
        $info = @getimagesize($absolute);
        if ($info === false) {
            return sprintf('not a readable image: %s', $relative);
        }
        [$w, $h] = $info;
        if ($w + $h > 10000) {
            return sprintf('image sides add up to more than 10000 px (Telegram limit): %s', $relative);
        }
        if ($w > 0 && $h > 0 && max($w / $h, $h / $w) > 20) {
            return sprintf('image aspect ratio exceeds 20:1 (Telegram limit): %s', $relative);
        }

        return null;
    }
}
