<?php

declare(strict_types=1);

namespace App\Bot;

use App\Entity\QuizRound;
use App\Telegram\TelegramApiException;
use App\Telegram\TelegramGateway;
use Psr\Log\LoggerInterface;

/** Closes rounds nobody touched for a while, so they do not hang around "unfinished" forever. */
final class RoundJanitor
{
    public function __construct(
        private readonly QuizStore $store,
        private readonly TelegramGateway $gateway,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A started round ends at its last activity, so the training time does not include the hours the player
     * spent away. With $notify the player gets the usual summary for tours in which at least one answer was given.
     *
     * @return int number of rounds closed
     */
    public function closeIdle(\DateTimeImmutable $now, int $idleMinutes, bool $notify = false): int
    {
        $before = $now->modify(sprintf('-%d minutes', max(1, $idleMinutes)));
        $closed = 0;

        foreach ($this->store->idleRounds($before) as $round) {
            $wasPlaying = $round->getState() === QuizRound::STATE_PLAYING;
            $round->finish(QuizRound::FINISH_TIMEOUT, $wasPlaying ? $round->getLastActivityAt() : $now);
            $this->store->save($round);
            ++$closed;

            if ($notify && $wasPlaying && $round->getAnsweredCount() > 0) {
                $this->notify($round);
            }
        }

        return $closed;
    }

    private function notify(QuizRound $round): void
    {
        $text = "Тур закрыт из-за долгого бездействия.\n\n" . Views::summary(
            QuizRound::FINISH_TIMEOUT,
            $round->getCorrectCount(),
            $round->getWrongCount(),
            (int) $round->getQuestionsTotal(),
            $round->getDurationSeconds(),
            $round->isListMode(),
            $round->getAnsweredCount(),
        );

        try {
            $this->gateway->sendMessage($round->getPlayer()->getChatId(), $text, Views::summaryKeyboard((int) $round->getId()));
        } catch (TelegramApiException $e) {
            // most likely the player blocked the bot; the round is closed either way
            $this->logger->info('Could not send the timeout summary', ['round' => $round->getId(), 'error' => $e->getMessage()]);
        }
    }
}
