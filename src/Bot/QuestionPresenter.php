<?php

declare(strict_types=1);

namespace App\Bot;

use App\Entity\QuizRound;
use App\Service\ThemeCatalog;
use App\Telegram\Photo;
use App\Telegram\TelegramApiException;
use App\Telegram\TelegramGateway;
use Psr\Log\LoggerInterface;

/** Puts a question on the player's screen: a plain message, or a picture with the text as its caption. */
final class QuestionPresenter
{
    public function __construct(
        private readonly TelegramGateway $gateway,
        private readonly QuizStore $store,
        private readonly ThemeCatalog $catalog,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{text: string, options: list<string>, image: ?string} $question
     *
     * @throws TelegramApiException
     */
    public function present(QuizRound $round, array $question, string $chatId): PresentedQuestion
    {
        if (($question['kind'] ?? 'choice') === 'list') {
            $view = Views::listQuestion(
                (int) $round->getId(),
                $round->getCurrentNumber(),
                (int) $round->getQuestionsTotal(),
                (string) $question['text'],
                (int) $question['need'],
                array_map('strval', (array) ($question['named'] ?? [])),
            );
            $sent = $this->gateway->sendMessage($chatId, $view['text'], $view['keyboard']);

            return new PresentedQuestion($sent->messageId, $view['text'], false);
        }

        $image = $question['image'];
        $view = Views::question(
            (int) $round->getId(),
            $round->getCurrentNumber(),
            (int) $round->getQuestionsTotal(),
            $question['text'],
            $question['options'],
            $image !== null,
        );

        if ($image === null) {
            $sent = $this->gateway->sendMessage($chatId, $view['text'], $view['keyboard']);

            return new PresentedQuestion($sent->messageId, $view['text'], false);
        }

        $sent = $this->sendPicture($round, $chatId, $image, $view['text'], $view['keyboard']);

        return new PresentedQuestion($sent->messageId, $view['text'], true);
    }

    /**
     * Sends a picture of the theme (the file is uploaded once, later by file_id).
     *
     * @param list<list<array{text: string, data: string}>> $keyboard
     *
     * @throws TelegramApiException
     */
    public function sendPicture(QuizRound $round, string $chatId, string $image, string $caption = '', array $keyboard = []): \App\Telegram\SentMessage
    {
        $theme = $round->getTheme();
        $config = $this->catalog->get($theme->getSlug());
        if ($config === null) {
            throw new \RuntimeException(sprintf('Theme "%s" has no valid config', $theme->getSlug()));
        }
        $absolute = $this->catalog->imageAbsolutePath($config, $image);
        $sha1 = is_file($absolute) ? sha1_file($absolute) : false;
        if ($sha1 === false) {
            throw new \RuntimeException(sprintf('Picture not found: %s', $image));
        }

        $fileId = $this->store->cachedFileId($theme, $image, $sha1);
        $photo = $fileId !== null ? Photo::fromFileId($fileId) : Photo::fromPath($absolute);

        try {
            $sent = $this->gateway->sendPhoto($chatId, $photo, $caption, $keyboard);
        } catch (TelegramApiException $e) {
            // a stored file_id can become unusable (another bot token, Telegram cleanup): upload the file again
            if (!$photo->isFileId() || $e->errorCode !== 400) {
                throw $e;
            }
            $this->logger->warning('Stored file_id was rejected, uploading the picture again', ['image' => $image, 'error' => $e->getMessage()]);
            $this->store->forgetFileId($theme, $image);
            $photo = Photo::fromPath($absolute);
            $sent = $this->gateway->sendPhoto($chatId, $photo, $caption, $keyboard);
        }

        if (!$photo->isFileId() && $sent->fileId !== null) {
            $this->store->rememberFileId($theme, $image, $sha1, $sent->fileId);
        }

        return $sent;
    }
}
