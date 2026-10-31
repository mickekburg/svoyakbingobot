<?php

declare(strict_types=1);

namespace App\Bot;

use App\Entity\QuizModeStat;
use App\Entity\QuizPlayer;
use App\Entity\QuizRound;
use App\Entity\QuizTheme;
use App\Quiz\AnswerMatcher;
use App\Quiz\ItemKey;
use App\Quiz\Leitner;
use App\Quiz\ModeDef;
use App\Quiz\ThemeConfig;
use App\Service\ThemeCatalog;
use App\Telegram\TelegramApiException;
use App\Telegram\TelegramGateway;
use App\Telegram\Update;
use Psr\Log\LoggerInterface;

/**
 * The game as a state machine over QuizRound.state:
 *
 *   choose_mode -> await_options -> await_questions -> await_selection -> playing -> finished
 *
 * Every state change that a repeated or late tap could break runs under a row lock on the round
 * (QuizStore::withLockedRound) and re-checks the state first, so the second of two quick taps is
 * rejected instead of being counted twice.
 */
final class BotHandler
{
    private const TOAST_GONE = 'Этот тур недоступен.';
    private const TOAST_ENDED = 'Тур уже завершён.';
    private const TOAST_STALE = 'Этот вопрос уже закрыт.';
    private const TOAST_BUSY = 'У вас идёт тур. Завершите его командой /stop.';

    /** @var list<int> */
    private array $allowedUserIds;

    /** @param string $allowedUserIds comma/space separated Telegram user ids; empty = everyone may play */
    public function __construct(
        private readonly QuizStore $store,
        private readonly TelegramGateway $gateway,
        private readonly ThemeCatalog $catalog,
        private readonly QuestionFactory $questions,
        private readonly QuestionPresenter $presenter,
        private readonly LoggerInterface $logger,
        string $allowedUserIds = '',
    ) {
        $ids = [];
        foreach (preg_split('/[\s,]+/', $allowedUserIds, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (ctype_digit($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }
        $this->allowedUserIds = $ids;
    }

    public function handle(Update $update): void
    {
        if ($this->allowedUserIds !== [] && !in_array($update->userId, $this->allowedUserIds, true)) {
            $this->deny($update);

            return;
        }

        $player = $this->store->findOrCreatePlayer($update->userId, $update->chatId, $update->username, $update->firstName);

        if ($update->callbackData !== null) {
            $this->handleCallback($update, $player);
        } elseif ($update->text !== null) {
            $this->handleText($update, $player);
        }
    }

    // ==== text ===================================================================================================

    private function handleText(Update $u, QuizPlayer $player): void
    {
        $chat = (string) $u->chatId;

        switch ($u->command()) {
            case null:
                $this->plainText($u, $player);

                return;
            case 'start':
                $this->start($u, $player);

                return;
            case 'help':
                $this->gateway->sendMessage($chat, Views::help());

                return;
            case 'stop':
                $this->stopCommand($u, $player);

                return;
            case 'stats':
                $this->statsCommand($u, $player);

                return;
            default:
                $this->gateway->sendMessage($chat, 'Неизвестная команда. Список возможностей: /help');
        }
    }

    private function plainText(Update $u, QuizPlayer $player): void
    {
        $chat = (string) $u->chatId;
        $round = $this->store->findUnfinishedRound($player);
        if ($round === null) {
            $this->gateway->sendMessage($chat, 'Нажмите /start, чтобы начать игру.');

            return;
        }

        $roundId = (int) $round->getId();
        $text = (string) $u->text;

        switch ($round->getState()) {
            case QuizRound::STATE_CHOOSE_MODE:
                $this->showModes($u->chatId, null, $round);

                return;
            case QuizRound::STATE_AWAIT_OPTIONS:
                $this->onOptionsInput($u, $roundId, $text);

                return;
            case QuizRound::STATE_AWAIT_QUESTIONS:
                $this->onQuestionsInput($u, $roundId, $text);

                return;
            case QuizRound::STATE_AWAIT_SELECTION:
                $this->gateway->sendMessage($chat, 'Выберите способ подбора кнопкой выше или нажмите /stop.');

                return;
            case QuizRound::STATE_PLAYING:
                $question = $round->getCurrentQuestion();
                if ($question !== null && ($question['kind'] ?? 'choice') === 'list') {
                    $this->onListInput($u, $player, $roundId, $text);

                    return;
                }
                if ($question !== null && ($question['options'] ?? []) === []) {
                    $this->onTypedAnswer($u, $player, $roundId, $text);

                    return;
                }
                $this->gateway->sendMessage($chat, 'Идёт тур: отвечайте кнопками или нажмите /stop.');

                return;
            default:
                $this->gateway->sendMessage($chat, 'Идёт тур: отвечайте кнопками или нажмите /stop.');
        }
    }

    private function start(Update $u, QuizPlayer $player): void
    {
        $round = $this->store->findUnfinishedRound($player);

        if ($round !== null && $round->getState() === QuizRound::STATE_PLAYING) {
            $this->gateway->sendMessage(
                (string) $u->chatId,
                Views::unfinishedPrompt($round->getAnsweredCount(), (int) $round->getQuestionsTotal()),
                Views::unfinishedKeyboard((int) $round->getId()),
            );

            return;
        }

        if ($round !== null) { // a tour that was only being set up
            $round->finish(QuizRound::FINISH_RESTART);
            $this->store->save($round);
        }

        $this->showThemes($u->chatId, null);
    }

    private function stopCommand(Update $u, QuizPlayer $player): void
    {
        $chat = (string) $u->chatId;
        $round = $this->store->findUnfinishedRound($player);

        if ($round === null) {
            $this->gateway->sendMessage($chat, 'Сейчас нет активного тура. Начать: /start');

            return;
        }
        if ($round->getState() !== QuizRound::STATE_PLAYING) {
            $round->finish(QuizRound::FINISH_RESTART);
            $this->store->save($round);
            $this->gateway->sendMessage($chat, 'Настройка тура отменена. Начать заново: /start');

            return;
        }

        $this->sendFinishConfirm($u->chatId, $round);
    }

    private function onOptionsInput(Update $u, int $roundId, string $text): void
    {
        $this->store->withLockedRound($roundId, function (?QuizRound $round) use ($u, $text): void {
            if ($round === null || $round->getState() !== QuizRound::STATE_AWAIT_OPTIONS) {
                return; // the state moved on while this message was waiting
            }

            $range = $this->optionsRange($round);
            if ($range === null) {
                $this->abortNoData($round, $u->chatId);

                return;
            }
            [$min, $max] = $range;

            $number = self::parseNumber($text);
            $isList = $this->questions->context($round)['mode']->isList() ?? false;
            if ($isList) {
                if ($number === null || $number < $min || $number > $max) {
                    $this->gateway->sendMessage((string) $u->chatId, Views::badNumber($min, $max));

                    return;
                }
            } elseif ($number === null || ($number !== 0 && ($number < $min || $number > $max))) { // 0 = no options, the player types the answer
                $this->gateway->sendMessage((string) $u->chatId, Views::badOptions($min, $max));

                return;
            }

            $round->setOptionsCount($number);
            $round->touch();

            $maxQuestions = $this->questions->maxQuestions($round);
            if ($maxQuestions < 1) {
                $this->abortNoData($round, $u->chatId);

                return;
            }

            $round->setState(QuizRound::STATE_AWAIT_QUESTIONS);
            $this->gateway->sendMessage((string) $u->chatId, Views::askQuestions($maxQuestions));
        });
    }

    private function onQuestionsInput(Update $u, int $roundId, string $text): void
    {
        $this->store->withLockedRound($roundId, function (?QuizRound $round) use ($u, $text): void {
            if ($round === null || $round->getState() !== QuizRound::STATE_AWAIT_QUESTIONS) {
                return;
            }

            $maxQuestions = $this->questions->maxQuestions($round);
            if ($maxQuestions < 1) {
                $this->abortNoData($round, $u->chatId);

                return;
            }

            $number = self::parseNumber($text);
            if ($number === null || $number < 1 || $number > $maxQuestions) {
                $this->gateway->sendMessage((string) $u->chatId, Views::badNumber(1, $maxQuestions));

                return;
            }

            $round->setQuestionsTotal($number);
            $round->setState(QuizRound::STATE_AWAIT_SELECTION);
            $round->touch();
            $view = Views::askSelection((int) $round->getId());
            $this->gateway->sendMessage((string) $u->chatId, $view['text'], $view['keyboard']);
        });
    }

    /** The player chose smart or random selection: the tour starts. */
    private function onSelection(Update $u, QuizPlayer $player, int $roundId, bool $smart): ?string
    {
        return $this->store->withLockedRound($roundId, function (?QuizRound $round) use ($u, $player, $smart): ?string {
            if (!$this->owns($round, $player)) {
                return self::TOAST_GONE;
            }
            if ($round->getState() !== QuizRound::STATE_AWAIT_SELECTION) {
                $this->dropKeyboardOf($u); // a second tap must not start anything again

                return self::TOAST_STALE;
            }

            $this->dropKeyboardOf($u);
            $round->setSmartSelection($smart);
            $round->setState(QuizRound::STATE_PLAYING);
            $round->markStarted(); // the clock starts when the first question is shown, not while settings are typed
            $round->touch();
            $this->startNextQuestion($round, $u->chatId);

            return null;
        });
    }

    // ==== callbacks ==============================================================================================

    private function handleCallback(Update $u, QuizPlayer $player): void
    {
        $toast = null;
        try {
            $toast = $this->dispatchCallback($u, $player, (string) $u->callbackData);
        } finally {
            // always stop the button's spinner, also when handling failed
            $this->gateway->answerCallback((string) $u->callbackId, $toast);
        }
    }

    private function dispatchCallback(Update $u, QuizPlayer $player, string $data): ?string
    {
        if ($data === 'bk') {
            return $this->onBack($u, $player);
        }
        if ($data === 'nw') {
            $this->start($u, $player);

            return null;
        }
        if (preg_match('/^th:(\d+)$/', $data, $m) === 1) {
            return $this->onTheme($u, $player, (int) $m[1]);
        }
        if (preg_match('/^md:(\d+):([A-Za-z0-9_-]+)$/', $data, $m) === 1) {
            return $this->onMode($u, $player, (int) $m[1], $m[2]);
        }
        if (preg_match('/^an:(\d+):(\d+):(\d+)$/', $data, $m) === 1) {
            return $this->onAnswer($u, $player, (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^sel:(\d+):([01])$/', $data, $m) === 1) {
            return $this->onSelection($u, $player, (int) $m[1], $m[2] === '1');
        }
        if (preg_match('/^lk:(\d+):(\d+)$/', $data, $m) === 1) {
            return $this->onListEnough($u, $player, (int) $m[1], (int) $m[2]);
        }
        if (preg_match('/^fin:(\d+):(\d+)$/', $data, $m) === 1) {
            return $this->onFinishAsk($u, $player, (int) $m[1], (int) $m[2]);
        }
        if (preg_match('/^fy:(\d+):(\d+)$/', $data, $m) === 1) {
            return $this->onFinishYes($u, $player, (int) $m[1], (int) $m[2]);
        }
        if (preg_match('/^fc:(\d+):(\d+)$/', $data, $m) === 1) {
            return $this->onFinishCancel($u, $player, (int) $m[1]);
        }
        if (preg_match('/^go:(\d+)$/', $data, $m) === 1) {
            return $this->onContinue($u, $player, (int) $m[1]);
        }
        if (preg_match('/^rs:(\d+)$/', $data, $m) === 1) {
            return $this->onRestart($u, $player, (int) $m[1]);
        }
        if (preg_match('/^ag:(\d+)$/', $data, $m) === 1) {
            return $this->onAgain($u, $player, (int) $m[1]);
        }

        return null; // unknown button: ignore
    }

    private function onTheme(Update $u, QuizPlayer $player, int $themeId): ?string
    {
        $existing = $this->store->findUnfinishedRound($player);
        if ($existing !== null && $existing->getState() === QuizRound::STATE_PLAYING) {
            return self::TOAST_BUSY;
        }

        $theme = $this->store->theme($themeId);
        $config = $theme === null ? null : $this->catalog->get($theme->getSlug());
        if ($theme === null || $config === null || $this->playableModes($theme, $config) === []) {
            return 'Эта тема сейчас недоступна.';
        }

        if ($existing !== null) {
            $existing->finish(QuizRound::FINISH_RESTART);
            $this->store->save($existing);
        }

        $round = $this->store->createRound($player, $theme);
        $this->showModes($u->chatId, $u->messageId, $round);

        return null;
    }

    private function onBack(Update $u, QuizPlayer $player): ?string
    {
        $round = $this->store->findUnfinishedRound($player);
        if ($round !== null && $round->getState() === QuizRound::STATE_PLAYING) {
            return self::TOAST_BUSY;
        }
        if ($round !== null) {
            $round->finish(QuizRound::FINISH_RESTART);
            $this->store->save($round);
        }
        $this->showThemes($u->chatId, $u->messageId);

        return null;
    }

    private function onMode(Update $u, QuizPlayer $player, int $roundId, string $modeSlug): ?string
    {
        return $this->store->withLockedRound($roundId, function (?QuizRound $round) use ($u, $player, $modeSlug): ?string {
            if (!$this->owns($round, $player)) {
                return self::TOAST_GONE;
            }
            if ($round->getState() !== QuizRound::STATE_CHOOSE_MODE) {
                return 'Режим уже выбран.';
            }

            $theme = $round->getTheme();
            $config = $this->catalog->get($theme->getSlug());
            $mode = $config?->mode($modeSlug);
            $stat = $this->store->modeStats($theme)[$modeSlug] ?? null;
            if ($mode === null || $stat === null || !self::isPlayable($mode, $stat)) {
                return 'Этот режим недоступен.';
            }

            $round->setModeSlug($modeSlug);
            $round->setListMode($mode->isList());
            $round->setState(QuizRound::STATE_AWAIT_OPTIONS);
            $round->touch();
            $this->show(
                $u->chatId,
                $u->messageId,
                $mode->isList() ? Views::askListSize($mode->title, $stat->getMaxOptions()) : Views::askOptions($mode->title, $mode->minOptions, $stat->getMaxOptions()),
            );

            return null;
        });
    }

    private function onAnswer(Update $u, QuizPlayer $player, int $roundId, int $number, int $index): ?string
    {
        return $this->store->withLockedRound($roundId, function (?QuizRound $round) use ($u, $player, $number, $index): ?string {
            if (!$this->owns($round, $player)) {
                return self::TOAST_GONE;
            }
            if ($round->getState() !== QuizRound::STATE_PLAYING) {
                $this->dropKeyboardOf($u);

                return self::TOAST_ENDED;
            }

            $question = $round->getCurrentQuestion();
            if ($question === null || $round->getCurrentNumber() !== $number) {
                $this->dropKeyboardOf($u); // a button of an already answered question

                return self::TOAST_STALE;
            }
            if ($index < 0 || $index >= count($question['options'])) {
                return 'Такого варианта нет.';
            }

            $this->resolveQuestion($u->chatId, $player, $round, $question, $index === $question['correct']);

            return null;
        });
    }

    /** The player typed an answer to a question without options. */
    private function onTypedAnswer(Update $u, QuizPlayer $player, int $roundId, string $text): void
    {
        $this->store->withLockedRound($roundId, function (?QuizRound $round) use ($u, $player, $text): void {
            if (!$this->owns($round, $player) || $round->getState() !== QuizRound::STATE_PLAYING) {
                return;
            }
            $question = $round->getCurrentQuestion();
            if ($question === null || ($question['options'] ?? []) !== [] || ($question['kind'] ?? 'choice') === 'list') {
                return;
            }

            $accepted = array_map('strval', (array) ($question['accepted'] ?? []));
            $match = AnswerMatcher::match($text, $accepted);
            $typo = $match !== null && !$match['exact'] ? $match['value'] : null;
            $this->resolveQuestion($u->chatId, $player, $round, $question, $match !== null, $typo, $match['value'] ?? null);
        });
    }

    /**
     * Counts the answer, remembers it for the repetition schedule, shows the result and goes on.
     *
     * @param array<string, mixed> $question
     */
    private function resolveQuestion(int $chatId, QuizPlayer $player, QuizRound $round, array $question, bool $correct, ?string $typoOf = null, ?string $named = null): void
    {
        $round->registerAnswer($correct);
        $round->completeQuestion();
        $round->touch();

        $modeSlug = $round->getModeSlug();
        if ($modeSlug !== null && is_string($question['item_key'] ?? null)) {
            $this->store->recordAnswer($player, $round->getTheme(), $modeSlug, $question['item_key'], $correct);
        }

        $options = (array) ($question['options'] ?? []);
        $info = (string) ($question['info'] ?? '');
        $images = (array) ($question['answer_images'] ?? []);
        // a typed answer: the facts about the item that was written, not about the one the question was built on
        if ($named !== null && isset($question['answer_facts'][$named])) {
            $info = (string) $question['answer_facts'][$named]['info'];
            $images = (array) $question['answer_facts'][$named]['images'];
        }
        $this->editQuestionMessage($chatId, $question, Views::answered(
            (string) $question['message_text'],
            $correct,
            (string) ($correct && $named !== null ? $named : ($options === [] ? ($question['correct_text'] ?? '') : $options[$question['correct']])),
            (bool) $question['photo'],
            $info,
            $typoOf,
        ));

        foreach ($images as $image) {
            try {
                $this->presenter->sendPicture($round, (string) $chatId, (string) $image);
            } catch (\Throwable $e) {
                // the answer is already counted: a missing or rejected picture must not stop the tour
                $this->logger->warning('Could not send the picture of the answer', ['image' => $image, 'error' => $e->getMessage()]);
            }
        }

        $this->goOn($round, $chatId);
    }

    private function goOn(QuizRound $round, int $chatId): void
    {
        if ($round->getAnsweredCount() >= (int) $round->getQuestionsTotal()) {
            $this->finishWithSummary($round, QuizRound::FINISH_COMPLETED, $chatId);
        } else {
            $this->startNextQuestion($round, $chatId);
        }
    }

    /** The player wrote something to a "name N elements" question: one or several elements. */
    private function onListInput(Update $u, QuizPlayer $player, int $roundId, string $text): void
    {
        $this->store->withLockedRound($roundId, function (?QuizRound $round) use ($u, $player, $text): void {
            if (!$this->owns($round, $player) || $round->getState() !== QuizRound::STATE_PLAYING) {
                return;
            }
            $question = $round->getCurrentQuestion();
            if ($question === null || ($question['kind'] ?? 'choice') !== 'list') {
                return;
            }

            $accepted = array_map('strval', (array) ($question['accepted'] ?? []));
            $named = array_map('strval', (array) ($question['named'] ?? []));
            $need = (int) $question['need'];
            $results = [];

            foreach ($this->splitAnswers($text, $accepted) as $part) {
                $match = AnswerMatcher::match($part, $accepted);
                if ($match === null) {
                    $round->registerAnswer(false);
                    $question['wrong_tries'] = (int) ($question['wrong_tries'] ?? 0) + 1;
                    $results[] = ['kind' => 'wrong', 'text' => $part];
                } elseif (in_array($match['value'], $named, true)) {
                    $results[] = ['kind' => 'again', 'text' => $match['value']];
                } else {
                    $named[] = $match['value'];
                    $round->registerAnswer(true);
                    $results[] = ['kind' => 'ok', 'text' => $match['value']];
                }
                if (count($named) >= $need) {
                    break; // the question is solved; the rest of the message is not needed
                }
            }
            $question['named'] = $named;
            $round->touch();

            if (count($named) >= $need) {
                $this->closeListQuestion($u->chatId, $player, $round, $question, true);

                return;
            }

            $round->setCurrentQuestion($question);
            $view = Views::listQuestion((int) $round->getId(), $round->getCurrentNumber(), (int) $round->getQuestionsTotal(), (string) $question['text'], $need, $named, $results);
            try {
                if (is_int($question['message_id'] ?? null)) {
                    $this->gateway->editText((string) $u->chatId, $question['message_id'], $view['text'], $view['keyboard']);
                }
            } catch (TelegramApiException $e) {
                $this->logger->warning('Could not update the list question', ['error' => $e->getMessage()]);
            }
        });
    }

    /** "Enough, next": the question is closed with what has been named. */
    private function onListEnough(Update $u, QuizPlayer $player, int $roundId, int $number): ?string
    {
        return $this->store->withLockedRound($roundId, function (?QuizRound $round) use ($u, $player, $number): ?string {
            if (!$this->owns($round, $player)) {
                return self::TOAST_GONE;
            }
            if ($round->getState() !== QuizRound::STATE_PLAYING) {
                $this->dropKeyboardOf($u);

                return self::TOAST_ENDED;
            }
            $question = $round->getCurrentQuestion();
            if ($question === null || ($question['kind'] ?? 'choice') !== 'list' || $round->getCurrentNumber() !== $number) {
                $this->dropKeyboardOf($u);

                return self::TOAST_STALE;
            }

            $round->touch();
            $this->closeListQuestion($u->chatId, $player, $round, $question, false);

            return null;
        });
    }

    /**
     * @param array<string, mixed> $question
     */
    private function closeListQuestion(int $chatId, QuizPlayer $player, QuizRound $round, array $question, bool $success): void
    {
        $round->completeQuestion();
        $modeSlug = $round->getModeSlug();
        if ($modeSlug !== null && is_string($question['item_key'] ?? null)) {
            $this->store->recordAnswer($player, $round->getTheme(), $modeSlug, $question['item_key'], $success);
        }

        $named = array_map('strval', (array) ($question['named'] ?? []));
        $taken = array_flip(array_map([AnswerMatcher::class, 'normalize'], $named));
        $rest = array_values(array_filter(
            array_map('strval', (array) ($question['accepted'] ?? [])),
            static fn (string $v): bool => !isset($taken[AnswerMatcher::normalize($v)]),
        ));
        shuffle($rest);

        $text = Views::listFinished((string) $question['text'], (int) $question['need'], $named, $success, array_slice($rest, 0, 10));
        try {
            if (is_int($question['message_id'] ?? null)) {
                $this->gateway->editText((string) $chatId, $question['message_id'], $text, []);
            }
        } catch (TelegramApiException $e) {
            $this->logger->warning('Could not show the result of the list question', ['error' => $e->getMessage()]);
        }

        $this->goOn($round, $chatId);
    }

    /**
     * One message may hold several answers. A title can contain a comma, so the whole text is tried first;
     * only when it matches nothing it is cut at new lines, semicolons and commas.
     *
     * @param list<string> $accepted
     *
     * @return list<string>
     */
    private function splitAnswers(string $text, array $accepted): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (AnswerMatcher::match($text, $accepted) !== null) {
            return [$text];
        }
        $parts = preg_split('/[\r\n;]+/u', $text) ?: [$text];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (AnswerMatcher::match($part, $accepted) !== null || !str_contains($part, ',')) {
                $out[] = $part;

                continue;
            }
            foreach (explode(',', $part) as $piece) {
                if (trim($piece) !== '') {
                    $out[] = trim($piece);
                }
            }
        }

        return $out;
    }

    private function statsCommand(Update $u, QuizPlayer $player): void
    {
        $themes = [];
        $now = new \DateTimeImmutable();
        foreach ($this->store->themes() as $theme) {
            $config = $this->catalog->get($theme->getSlug());
            if ($config === null) {
                continue;
            }
            $progress = $this->store->themeProgress($player, $theme);
            $modes = [];
            foreach ($this->playableModes($theme, $config) as $mode) {
                $stat = $this->store->modeStats($theme)[$mode->slug];
                $seen = $learned = $due = $correct = $wrong = 0;
                $keys = $this->questions->validKeys($theme, $config, $mode);
                foreach ($progress[$mode->slug] ?? [] as $key => $p) {
                    if (!isset($keys[$key])) {
                        continue; // the row was edited or removed since
                    }
                    ++$seen;
                    $learned += $p->isLearned() ? 1 : 0;
                    $due += $p->isDue($now) ? 1 : 0;
                    $correct += $p->correct;
                    $wrong += $p->wrong;
                }
                $modes[] = ['title' => $mode->title, 'total' => $stat->getEligibleItems(), 'seen' => $seen, 'learned' => $learned, 'due' => $due, 'correct' => $correct, 'wrong' => $wrong];
            }
            if ($modes !== []) {
                $themes[] = ['title' => $config->title, 'modes' => $modes];
            }
        }

        $this->gateway->sendMessage((string) $u->chatId, Views::stats($themes));
    }

    private function onFinishAsk(Update $u, QuizPlayer $player, int $roundId, int $number): ?string
    {
        $round = $this->store->findRound($roundId);
        if (!$this->owns($round, $player)) {
            return self::TOAST_GONE;
        }
        if ($round->getState() !== QuizRound::STATE_PLAYING) {
            $this->dropKeyboardOf($u);

            return self::TOAST_ENDED;
        }
        if ($round->getCurrentNumber() !== $number) {
            $this->dropKeyboardOf($u);

            return self::TOAST_STALE;
        }

        $this->sendFinishConfirm($u->chatId, $round);

        return null;
    }

    private function onFinishYes(Update $u, QuizPlayer $player, int $roundId, int $number): ?string
    {
        return $this->store->withLockedRound($roundId, function (?QuizRound $round) use ($u, $player, $number): ?string {
            if (!$this->owns($round, $player)) {
                return self::TOAST_GONE;
            }
            if ($round->getState() !== QuizRound::STATE_PLAYING) {
                $this->dropKeyboardOf($u);

                return self::TOAST_ENDED;
            }
            if ($round->getCurrentNumber() !== $number) {
                $this->dropKeyboardOf($u);

                return 'Это подтверждение устарело.';
            }

            $question = $round->getCurrentQuestion();
            if ($question !== null && is_int($question['message_id'] ?? null)) {
                $this->gateway->removeKeyboard((string) $u->chatId, $question['message_id']);
            }
            // the confirmation message itself turns into the summary
            $this->finishWithSummary($round, QuizRound::FINISH_EARLY, $u->chatId, $u->messageId);

            return null;
        });
    }

    private function onFinishCancel(Update $u, QuizPlayer $player, int $roundId): ?string
    {
        $round = $this->store->findRound($roundId);
        if (!$this->owns($round, $player)) {
            return self::TOAST_GONE;
        }

        $this->show($u->chatId, $u->messageId, 'Продолжаем! Выберите ответ в вопросе выше.');

        return null;
    }

    private function onContinue(Update $u, QuizPlayer $player, int $roundId): ?string
    {
        return $this->store->withLockedRound($roundId, function (?QuizRound $round) use ($u, $player): ?string {
            if (!$this->owns($round, $player)) {
                return self::TOAST_GONE;
            }
            $question = $round->getCurrentQuestion();
            if ($round->getState() !== QuizRound::STATE_PLAYING || $question === null) {
                $this->dropKeyboardOf($u);

                return self::TOAST_ENDED;
            }

            // the old copy of the question must stop accepting taps, then it is shown again at the bottom of the chat
            if (is_int($question['message_id'] ?? null)) {
                $this->gateway->removeKeyboard((string) $u->chatId, $question['message_id']);
            }
            $this->dropKeyboardOf($u);
            $round->touch();
            $this->present($round, $question, $u->chatId);

            return null;
        });
    }

    private function onRestart(Update $u, QuizPlayer $player, int $roundId): ?string
    {
        return $this->store->withLockedRound($roundId, function (?QuizRound $round) use ($u, $player): ?string {
            if (!$this->owns($round, $player)) {
                return self::TOAST_GONE;
            }
            if ($round->isFinished()) {
                $this->dropKeyboardOf($u);

                return self::TOAST_ENDED;
            }

            $question = $round->getCurrentQuestion();
            if ($round->getState() === QuizRound::STATE_PLAYING && $question !== null && is_int($question['message_id'] ?? null)) {
                $this->gateway->removeKeyboard((string) $u->chatId, $question['message_id']);
            }
            $round->finish(QuizRound::FINISH_RESTART);
            $this->showThemes($u->chatId, $u->messageId);

            return null;
        });
    }

    /** "Ещё раз": the same theme, mode, option count and question count. */
    private function onAgain(Update $u, QuizPlayer $player, int $roundId): ?string
    {
        $old = $this->store->findRound($roundId);
        if (!$this->owns($old, $player)) {
            return self::TOAST_GONE;
        }
        if (!$old->isFinished()) {
            return self::TOAST_BUSY;
        }

        $modeSlug = $old->getModeSlug();
        $options = $old->getOptionsCount();
        $total = $old->getQuestionsTotal();
        if ($modeSlug === null || $options === null || $total === null) {
            return 'Для этого тура нет сохранённых настроек. Нажмите /start.';
        }

        $existing = $this->store->findUnfinishedRound($player);
        if ($existing !== null && $existing->getState() === QuizRound::STATE_PLAYING) {
            return self::TOAST_BUSY;
        }
        if ($existing !== null) {
            $existing->finish(QuizRound::FINISH_RESTART);
            $this->store->save($existing);
        }

        $this->dropKeyboardOf($u); // the button must not start a third tour on a double tap

        $fresh = $this->store->createRound($player, $old->getTheme());
        $this->store->withLockedRound((int) $fresh->getId(), function (?QuizRound $round) use ($u, $modeSlug, $options, $total, $old): void {
            if ($round === null) {
                return;
            }
            $round->setModeSlug($modeSlug);
            $round->setListMode($old->isListMode());
            $round->setSmartSelection($old->isSmartSelection());
            $round->setOptionsCount($options);

            $maxQuestions = $this->questions->maxQuestions($round);
            if ($maxQuestions < 1) {
                $this->abortNoData($round, $u->chatId);

                return;
            }

            $round->setQuestionsTotal(min($total, $maxQuestions));
            $round->setState(QuizRound::STATE_PLAYING);
            $round->markStarted();
            $round->touch();
            $this->startNextQuestion($round, $u->chatId);
        });

        return null;
    }

    // ==== building blocks ========================================================================================

    private function showThemes(int $chatId, ?int $messageId): void
    {
        $themes = [];
        foreach ($this->store->themes() as $theme) {
            $config = $this->catalog->get($theme->getSlug());
            if ($config !== null && $this->playableModes($theme, $config) !== []) {
                $themes[] = ['id' => (int) $theme->getId(), 'title' => $config->title];
            }
        }

        if ($themes === []) {
            $this->show($chatId, $messageId, Views::noThemes());

            return;
        }
        $this->show($chatId, $messageId, Views::welcome(), Views::themeKeyboard($themes));
    }

    private function showModes(int $chatId, ?int $messageId, QuizRound $round): void
    {
        $theme = $round->getTheme();
        $config = $this->catalog->get($theme->getSlug());
        $modes = $config === null ? [] : $this->playableModes($theme, $config);
        if ($config === null || $modes === []) {
            $this->abortNoData($round, $chatId);

            return;
        }

        $menu = Views::modeMenu(
            $config->title,
            (int) $round->getId(),
            array_map(static fn (ModeDef $m): array => ['slug' => $m->slug, 'title' => $m->title], $modes),
        );
        $this->show($chatId, $messageId, $menu['text'], $menu['keyboard']);
    }

    private function sendFinishConfirm(int $chatId, QuizRound $round): void
    {
        $this->gateway->sendMessage(
            (string) $chatId,
            Views::confirmFinish($round->getAnsweredCount(), (int) $round->getQuestionsTotal()),
            Views::confirmKeyboard((int) $round->getId(), $round->getCurrentNumber()),
        );
    }

    private function startNextQuestion(QuizRound $round, int $chatId): void
    {
        $generated = $this->questions->next($round);

        if ($generated === null) {
            if ($round->getAnsweredCount() === 0) {
                $this->abortNoData($round, $chatId);
            } else {
                $this->logger->warning('Ran out of questions mid-tour, finishing it early', ['round' => $round->getId()]);
                $this->finishWithSummary($round, QuizRound::FINISH_EARLY, $chatId);
            }

            return;
        }

        $round->setCurrentNumber($round->getAnsweredCount() + 1);
        $round->addUsedItemId($generated->itemId);
        $this->present($round, [
            'item' => $generated->itemId,
            'text' => $generated->text,
            'options' => $generated->options,
            'correct' => $generated->correctIndex,
            'correct_text' => $generated->correctAnswer(),
            'accepted' => $generated->accepted,
            'item_key' => $generated->itemKey,
            'answer_images' => $generated->answerImages,
            'answer_facts' => $generated->answerFacts,
            'kind' => $generated->isList() ? 'list' : 'choice',
            'need' => $generated->listNeed,
            'named' => [],
            'wrong_tries' => 0,
            'image' => $generated->image,
            'info' => $generated->info,
            'photo' => false,
            'message_id' => null,
            'message_text' => '',
        ], $chatId);
    }

    /**
     * Sends the question and stores it, with the id and text of the message, as the round's current question.
     *
     * @param array<string, mixed> $question
     */
    private function present(QuizRound $round, array $question, int $chatId): void
    {
        $presented = $this->presenter->present($round, $question, (string) $chatId);
        $question['message_id'] = $presented->messageId;
        $question['message_text'] = $presented->text;
        $question['photo'] = $presented->photo;
        $round->setCurrentQuestion($question);
    }

    private function finishWithSummary(QuizRound $round, string $reason, int $chatId, ?int $replaceMessageId = null): void
    {
        $round->finish($reason);
        $this->show(
            $chatId,
            $replaceMessageId,
            Views::summary($reason, $round->getCorrectCount(), $round->getWrongCount(), (int) $round->getQuestionsTotal(), $round->getDurationSeconds(), $round->isListMode(), $round->getAnsweredCount()),
            Views::summaryKeyboard((int) $round->getId()),
        );
    }

    private function abortNoData(QuizRound $round, int $chatId): void
    {
        $round->finish(QuizRound::FINISH_NO_DATA);
        $this->store->save($round); // may be called outside a locked section, where nothing else would flush it
        $this->gateway->sendMessage((string) $chatId, Views::notEnoughData());
    }

    /** Edits a message in place; if Telegram refuses (too old, deleted) sends a new one instead. */
    private function show(int $chatId, ?int $messageId, string $text, array $keyboard = []): void
    {
        if ($messageId !== null) {
            try {
                $this->gateway->editText((string) $chatId, $messageId, $text, $keyboard);

                return;
            } catch (TelegramApiException $e) {
                $this->logger->info('Could not edit a message, sending a new one', ['error' => $e->getMessage()]);
            }
        }
        $this->gateway->sendMessage((string) $chatId, $text, $keyboard);
    }

    /** @param array<string, mixed> $question */
    private function editQuestionMessage(int $chatId, array $question, string $text): void
    {
        if (!is_int($question['message_id'] ?? null)) {
            return;
        }
        try {
            $this->gateway->editText((string) $chatId, $question['message_id'], $text, [], (bool) ($question['photo'] ?? false));
        } catch (TelegramApiException $e) {
            // the answer is already counted; failing to decorate the old message must not undo it
            $this->logger->warning('Could not show the result on the question message', ['error' => $e->getMessage()]);
        }
    }

    private function dropKeyboardOf(Update $u): void
    {
        if ($u->messageId !== null) {
            $this->gateway->removeKeyboard((string) $u->chatId, $u->messageId);
        }
    }

    private function deny(Update $u): void
    {
        if ($u->callbackId !== null) {
            $this->gateway->answerCallback($u->callbackId, 'Этот бот приватный.');

            return;
        }
        $this->gateway->sendMessage((string) $u->chatId, 'Этот бот приватный.');
    }

    private function owns(?QuizRound $round, QuizPlayer $player): bool
    {
        return $round !== null && $round->getPlayer()->getId() === $player->getId();
    }

    /** @return array{0: int, 1: int}|null allowed number of options, or null if the mode cannot be played any more */
    private function optionsRange(QuizRound $round): ?array
    {
        $context = $this->questions->context($round);
        if ($context === null) {
            return null;
        }
        $stat = $this->store->modeStats($round->getTheme())[$context['mode']->slug] ?? null;
        if ($stat === null || !self::isPlayable($context['mode'], $stat)) {
            return null;
        }

        return [$context['mode']->minOptions, $stat->getMaxOptions()];
    }

    /** @return list<ModeDef> modes whose import statistics say that a question can be built */
    private function playableModes(QuizTheme $theme, ThemeConfig $config): array
    {
        $stats = $this->store->modeStats($theme);
        $modes = [];
        foreach ($config->modes as $mode) {
            if (isset($stats[$mode->slug]) && self::isPlayable($mode, $stats[$mode->slug])) {
                $modes[] = $mode;
            }
        }

        return $modes;
    }

    private static function isPlayable(ModeDef $mode, QuizModeStat $stat): bool
    {
        return $stat->getEligibleItems() > 0 && $stat->getMaxOptions() >= $mode->minOptions;
    }

    private static function parseNumber(string $text): ?int
    {
        $text = trim($text);

        return preg_match('/^\d{1,6}$/', $text) === 1 ? (int) $text : null;
    }
}
