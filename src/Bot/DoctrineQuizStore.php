<?php

declare(strict_types=1);

namespace App\Bot;

use App\Entity\QuizItem;
use App\Entity\QuizModeStat;
use App\Entity\QuizPlayer;
use App\Entity\QuizRound;
use App\Entity\QuizTelegramFile;
use App\Entity\QuizTheme;
use App\Entity\QuizItemProgress;
use App\Quiz\ItemProgress;
use App\Quiz\ItemRecord;
use App\Quiz\Leitner;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Takes the entity manager from the registry on every call instead of holding one: when a transaction fails
 * Doctrine closes the manager, and a long-running polling worker would otherwise be stuck with a dead one.
 */
final class DoctrineQuizStore implements QuizStore
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    public function findOrCreatePlayer(int $userId, int $chatId, ?string $username, ?string $firstName): QuizPlayer
    {
        $em = $this->em();
        $player = $em->getRepository(QuizPlayer::class)->findOneBy(['telegramUserId' => (string) $userId]);
        if ($player === null) {
            $player = new QuizPlayer($userId, $chatId);
        }
        $player->touch($chatId, $username, $firstName);
        $em->persist($player);
        $em->flush();

        return $player;
    }

    public function findUnfinishedRound(QuizPlayer $player): ?QuizRound
    {
        $round = $this->em()
            ->createQuery('SELECT r FROM ' . QuizRound::class . ' r WHERE r.player = :player AND r.state <> :done ORDER BY r.id DESC')
            ->setParameter('player', $player)
            ->setParameter('done', QuizRound::STATE_FINISHED)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return $round instanceof QuizRound ? $round : null;
    }

    public function findRound(int $roundId): ?QuizRound
    {
        return $this->em()->find(QuizRound::class, $roundId);
    }

    public function createRound(QuizPlayer $player, QuizTheme $theme): QuizRound
    {
        $em = $this->em();
        $round = new QuizRound($player, $theme);
        $em->persist($round);
        $em->flush();

        return $round;
    }

    public function save(QuizRound $round): void
    {
        $em = $this->em();
        $em->persist($round);
        $em->flush();
    }

    public function withLockedRound(int $roundId, callable $fn): mixed
    {
        // wrapInTransaction() flushes before it commits; on an exception it rolls back and closes the manager
        return $this->em()->wrapInTransaction(static function (EntityManagerInterface $em) use ($roundId, $fn): mixed {
            $round = $em->find(QuizRound::class, $roundId);
            if ($round !== null) {
                // The row lock is taken by this re-read; refreshing also drops any stale copy that
                // was loaded earlier in the same request.
                $em->refresh($round, LockMode::PESSIMISTIC_WRITE);
            }

            return $fn($round);
        });
    }

    public function themes(): array
    {
        /** @var list<QuizTheme> $themes */
        $themes = $this->em()->createQuery('SELECT t FROM ' . QuizTheme::class . ' t ORDER BY t.title ASC')->getResult();

        return $themes;
    }

    public function theme(int $themeId): ?QuizTheme
    {
        return $this->em()->find(QuizTheme::class, $themeId);
    }

    public function modeStats(QuizTheme $theme): array
    {
        $stats = [];
        foreach ($this->em()->getRepository(QuizModeStat::class)->findBy(['theme' => $theme]) as $stat) {
            $stats[$stat->getModeSlug()] = $stat;
        }

        return $stats;
    }

    public function itemRecords(QuizTheme $theme): array
    {
        $rows = $this->em()
            ->createQuery('SELECT i.id AS id, i.attrs AS attrs FROM ' . QuizItem::class . ' i WHERE i.theme = :theme ORDER BY i.id ASC')
            ->setParameter('theme', $theme)
            ->getArrayResult();

        $records = [];
        foreach ($rows as $row) {
            $attrs = $row['attrs'];
            if (is_string($attrs)) { // some drivers hand the raw JSON over
                $attrs = json_decode($attrs, true, 512, JSON_THROW_ON_ERROR);
            }
            $records[] = new ItemRecord((int) $row['id'], is_array($attrs) ? $attrs : []);
        }

        return $records;
    }

    public function progress(QuizPlayer $player, QuizTheme $theme, string $modeSlug): array
    {
        $result = [];
        foreach ($this->em()->getRepository(QuizItemProgress::class)->findBy(['player' => $player, 'theme' => $theme, 'modeSlug' => $modeSlug]) as $row) {
            $result[$row->getItemKey()] = $row->toProgress();
        }

        return $result;
    }

    public function themeProgress(QuizPlayer $player, QuizTheme $theme): array
    {
        $result = [];
        foreach ($this->em()->getRepository(QuizItemProgress::class)->findBy(['player' => $player, 'theme' => $theme]) as $row) {
            $result[$row->getModeSlug()][$row->getItemKey()] = $row->toProgress();
        }

        return $result;
    }

    public function recordAnswer(QuizPlayer $player, QuizTheme $theme, string $modeSlug, string $itemKey, bool $correct): void
    {
        $em = $this->em();
        $row = $em->getRepository(QuizItemProgress::class)->findOneBy(['player' => $player, 'theme' => $theme, 'modeSlug' => $modeSlug, 'itemKey' => $itemKey]);
        $new = Leitner::advance($row?->toProgress(), $itemKey, $correct, new \DateTimeImmutable());
        if ($row === null) {
            $em->persist(new QuizItemProgress($player, $theme, $modeSlug, $new));
        } else {
            $row->apply($new);
        }
        $em->flush();
    }

    public function cachedFileId(QuizTheme $theme, string $imagePath, string $sha1): ?string
    {
        $file = $this->em()->getRepository(QuizTelegramFile::class)->findOneBy(['theme' => $theme, 'imagePath' => $imagePath]);

        return $file !== null && $file->getSha1() === $sha1 ? $file->getFileId() : null;
    }

    public function rememberFileId(QuizTheme $theme, string $imagePath, string $sha1, string $fileId): void
    {
        $em = $this->em();
        $file = $em->getRepository(QuizTelegramFile::class)->findOneBy(['theme' => $theme, 'imagePath' => $imagePath]);
        if ($file === null) {
            $file = new QuizTelegramFile($theme, $imagePath, $sha1, $fileId);
        } else {
            $file->update($sha1, $fileId);
        }
        $em->persist($file);
        $em->flush();
    }

    public function forgetFileId(QuizTheme $theme, string $imagePath): void
    {
        $em = $this->em();
        $file = $em->getRepository(QuizTelegramFile::class)->findOneBy(['theme' => $theme, 'imagePath' => $imagePath]);
        if ($file !== null) {
            $em->remove($file);
            $em->flush();
        }
    }

    public function idleRounds(\DateTimeImmutable $before): array
    {
        /** @var list<QuizRound> $rounds */
        $rounds = $this->em()
            ->createQuery('SELECT r FROM ' . QuizRound::class . ' r WHERE r.state <> :done AND r.lastActivityAt < :before ORDER BY r.id ASC')
            ->setParameter('done', QuizRound::STATE_FINISHED)
            ->setParameter('before', $before)
            ->getResult();

        return $rounds;
    }

    public function reset(): void
    {
        $em = $this->em();
        $em->clear();
        $connection = $em->getConnection();
        if (!$connection->isTransactionActive()) {
            // reconnects lazily on the next query: a worker that idled for hours never meets "MySQL server has gone away"
            $connection->close();
        }
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->registry->getManager();
        if ($em instanceof EntityManagerInterface && !$em->isOpen()) {
            $em = $this->registry->resetManager();
        }
        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('The default Doctrine manager is not an ORM entity manager.');
        }

        return $em;
    }
}
