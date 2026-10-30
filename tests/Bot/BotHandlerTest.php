<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Bot\Views;
use App\Entity\QuizRound;
use PHPUnit\Framework\TestCase;

final class BotHandlerTest extends TestCase
{
    // ---- the happy path -----------------------------------------------------------------------------------------

    public function testFullTourWithMixedAnswers(): void
    {
        $w = new BotWorld();

        $w->say('/start');
        $themeMenu = $w->gateway->last();
        $this->assertStringContainsString('Выберите тему', $themeMenu['text']);
        $this->assertSame(['th:' . $w->theme->getId()], $w->gateway->buttonData($themeMenu['id']));

        $w->tap('th:' . $w->theme->getId());
        // the theme menu turned into the mode menu (edited in place) with every playable mode plus "back"
        $this->assertStringContainsString('Выберите режим', $w->gateway->live[$themeMenu['id']]['text']);
        $this->assertCount(8, $w->gateway->buttonData($themeMenu['id']));
        $this->assertSame(QuizRound::STATE_CHOOSE_MODE, $w->round()->getState());

        $w->tap(sprintf('md:%d:title_by_year', $w->round()->getId()));
        $this->assertSame(QuizRound::STATE_AWAIT_OPTIONS, $w->round()->getState());
        $this->assertStringContainsString('Сколько вариантов ответа', $w->gateway->live[$themeMenu['id']]['text']);
        $this->assertSame([], $w->gateway->buttonData($themeMenu['id']), 'the mode buttons must be gone');

        $w->say('4');
        $this->assertSame(QuizRound::STATE_AWAIT_QUESTIONS, $w->round()->getState());
        $this->assertStringContainsString('Сколько вопросов в туре? Введите число от 1 до 14.', $w->gateway->last()['text']);
        $this->assertNull($w->round()->getStartedAt(), 'the clock must not run while the player types settings');

        $w->say('3');
        $round = $w->round();
        $this->assertSame(QuizRound::STATE_PLAYING, $round->getState());
        $this->assertNotNull($round->getStartedAt());
        $this->assertSame(1, $round->getCurrentNumber());
        $this->assertStringContainsString('Вопрос 1 из 3', $w->gateway->last()['text']);
        // 4 options + "Завершить"
        $this->assertCount(5, $w->gateway->buttonData($w->gateway->last()['id']));

        // 1st: right
        $w->answer(true);
        $this->assertStringContainsString('✅ Верно!', $w->gateway->live[$this->questionMessageId($w, 0)]['text']);
        $this->assertStringContainsString('Режиссёр: ', $w->gateway->live[$this->questionMessageId($w, 0)]['text'], 'facts about the film are shown with the answer');
        $this->assertSame([], $w->gateway->buttonData($this->questionMessageId($w, 0)), 'answered questions lose their buttons');

        // 2nd: wrong -> the right answer is shown right away
        $q = $w->currentQuestion();
        $rightText = $q['options'][$q['correct']];
        $secondId = $q['message_id'];
        $w->answer(false);
        $this->assertStringContainsString('❌ Неверно. Правильный ответ: ' . $rightText, $w->gateway->live[$secondId]['text']);

        // 3rd: right -> summary
        $w->answer(true);
        $summary = $w->gateway->last();
        $this->assertStringContainsString('Тур завершён!', $summary['text']);
        $this->assertStringContainsString('Верно: 2 из 3 (67%)', $summary['text']);
        $this->assertStringContainsString('Неверно: 1', $summary['text']);
        $this->assertStringContainsString('Время: ', $summary['text']);
        $this->assertSame(['Ещё раз', 'Новая игра'], $w->gateway->buttonTexts($summary['id']));

        $round = $w->round();
        $this->assertSame(QuizRound::STATE_FINISHED, $round->getState());
        $this->assertSame(QuizRound::FINISH_COMPLETED, $round->getFinishReason());
        $this->assertSame(2, $round->getCorrectCount());
        $this->assertSame(1, $round->getWrongCount());
        $this->assertCount(3, array_unique($round->getUsedItemIds()), 'no film is asked twice in a tour');
        $this->assertNull($round->getCurrentQuestion());
    }

    public function testEveryOptionListHasExactlyOneCorrectAnswer(): void
    {
        // end-to-end guard of the main rule, through the real bot code: the right option really is the one marked
        $w = new BotWorld();
        $w->startTour('title_by_actor', 5, 10);

        for ($i = 0; $i < 10; ++$i) {
            $q = $w->currentQuestion();
            $this->assertCount(5, $q['options']);
            $this->assertCount(5, array_unique($q['options']), 'options are distinct');
            preg_match('/снимался (.+)\?$/u', $q['text'], $m);
            $actor = $m[1];
            $withActor = array_values(array_filter(
                BotWorld::films(),
                static fn ($f): bool => in_array($actor, $f->attrs['actors'], true),
            ));
            $titlesWithActor = array_map(static fn ($f): string => $f->attrs['title'], $withActor);
            $matches = array_values(array_intersect($q['options'], $titlesWithActor));
            $this->assertCount(1, $matches, 'exactly one option stars ' . $actor);
            $this->assertSame($q['options'][$q['correct']], $matches[0]);
            $w->answer(true);
        }
        $this->assertStringContainsString('Верно: 10 из 10 (100%)', $w->gateway->last()['text']);
    }

    public function testTourCanUseEveryAvailableQuestion(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_poster', 4, 14);

        for ($i = 0; $i < 14; ++$i) {
            $w->answer(true);
        }

        $round = $w->round();
        $this->assertSame(QuizRound::FINISH_COMPLETED, $round->getFinishReason());
        $this->assertCount(14, array_unique($round->getUsedItemIds()));
        $this->assertStringContainsString('Верно: 14 из 14 (100%)', $w->gateway->last()['text']);
    }

    // ---- numbers typed by the player ----------------------------------------------------------------------------

    public function testBadNumbersAreRejectedAndStateIsKept(): void
    {
        $w = new BotWorld();
        $w->say('/start');
        $w->tap('th:' . $w->theme->getId());
        $w->tap(sprintf('md:%d:title_by_year', $w->round()->getId()));
        $maxOptions = $w->store->modeStats($w->theme)['title_by_year']->getMaxOptions();

        foreach (['abc', '1', '-3', '2.5', '4 5', (string) ($maxOptions + 1), '99999999'] as $bad) {
            $w->say($bad);
            $this->assertSame(Views::badOptions(2, $maxOptions), $w->gateway->last()['text'], 'input "' . $bad . '"');
            $this->assertSame(QuizRound::STATE_AWAIT_OPTIONS, $w->round()->getState());
            $this->assertNull($w->round()->getOptionsCount());
        }

        $w->say(' 3 '); // surrounding spaces are fine
        $this->assertSame(3, $w->round()->getOptionsCount());
        $this->assertSame(QuizRound::STATE_AWAIT_QUESTIONS, $w->round()->getState());

        foreach (['0', '15', 'many', '-1'] as $bad) {
            $w->say($bad);
            $this->assertSame(Views::badNumber(1, 14), $w->gateway->last()['text'], 'input "' . $bad . '"');
            $this->assertSame(QuizRound::STATE_AWAIT_QUESTIONS, $w->round()->getState());
        }

        $w->say('14');
        $this->assertSame(QuizRound::STATE_PLAYING, $w->round()->getState());
        $this->assertSame(14, $w->round()->getQuestionsTotal());
    }

    public function testTextDuringTourAndBeforeStart(): void
    {
        $w = new BotWorld();
        $w->say('привет');
        $this->assertStringContainsString('/start', $w->gateway->last()['text']);

        $w->startTour('title_by_year', 3, 2);
        $sent = count($w->gateway->sent);
        $w->say('42');
        $this->assertStringContainsString('Идёт тур', $w->gateway->last()['text']);
        $this->assertCount($sent + 1, $w->gateway->sent);
        $this->assertSame(0, $w->round()->getAnsweredCount());
    }

    // ---- stale, repeated and foreign taps -----------------------------------------------------------------------

    public function testSecondTapOnTheSameQuestionIsNotCounted(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_year', 3, 3);

        $round = $w->round();
        $q = $w->currentQuestion();
        $data = sprintf('an:%d:%d:%d', $round->getId(), 1, $q['correct']);
        $oldMessage = $q['message_id'];

        $w->tap($data, $oldMessage);
        $this->assertSame(1, $w->round()->getAnsweredCount());
        $sent = count($w->gateway->sent);

        $w->tap($data, $oldMessage); // a double tap / a redelivered update
        $this->assertSame(1, $w->round()->getAnsweredCount(), 'must not be counted twice');
        $this->assertSame(1, $w->round()->getCorrectCount());
        $this->assertSame('Этот вопрос уже закрыт.', $w->gateway->lastToast());
        $this->assertCount($sent, $w->gateway->sent, 'no extra question is sent');
        $this->assertSame(2, $w->round()->getCurrentNumber());
    }

    public function testTapAfterTheTourIsOver(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_year', 3, 1);
        $round = $w->round();
        $q = $w->currentQuestion();
        $data = sprintf('an:%d:1:%d', $round->getId(), $q['correct']);
        $oldMessage = $q['message_id'];
        $w->tap($data, $oldMessage);
        $this->assertSame(QuizRound::STATE_FINISHED, $w->round()->getState());

        $w->tap($data, $oldMessage);
        $this->assertSame('Тур уже завершён.', $w->gateway->lastToast());
        $this->assertSame(1, $w->round()->getAnsweredCount());
        $this->assertContains($oldMessage, $w->gateway->keyboardsRemoved, 'the stale message loses its buttons');
    }

    public function testAnotherPlayerCannotTouchMyTour(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_year', 3, 3);
        $round = $w->round();
        $q = $w->currentQuestion();

        $w->tap(sprintf('an:%d:1:%d', $round->getId(), $q['correct']), $q['message_id'], 2);

        $this->assertSame('Этот тур недоступен.', $w->gateway->lastToast());
        $this->assertSame(0, $w->round(BotWorld::USER)->getAnsweredCount());
    }

    public function testButtonOfAnUnknownRoundOrTheme(): void
    {
        $w = new BotWorld();
        $w->say('/start');
        $w->tap('th:9999');
        $this->assertSame('Эта тема сейчас недоступна.', $w->gateway->lastToast());

        $w->tap('an:777:1:0', $w->gateway->last()['id']);
        $this->assertSame('Этот тур недоступен.', $w->gateway->lastToast());

        $w->tap('garbage');
        $this->assertNull($w->gateway->lastToast());
    }

    public function testThemeButtonDuringATourIsRefused(): void
    {
        $w = new BotWorld();
        $w->say('/start');
        $oldMenu = $w->gateway->last()['id'];
        $w->tap('th:' . $w->theme->getId(), $oldMenu);
        $w->tap(sprintf('md:%d:title_by_year', $w->round()->getId()));
        $w->say('3');
        $w->say('2');
        $this->assertSame(QuizRound::STATE_PLAYING, $w->round()->getState());

        $w->tap('th:' . $w->theme->getId(), $oldMenu); // a leftover button from the old menu
        $this->assertSame('У вас идёт тур. Завершите его командой /stop.', $w->gateway->lastToast());
        $this->assertCount(1, $w->store->allRounds());
    }

    // ---- finishing early ----------------------------------------------------------------------------------------

    public function testFinishEarlyWithTheButton(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_year', 3, 3);
        $w->answer(true);
        $questionMessage = $w->currentQuestion()['message_id'];

        $w->tapLabel('Завершить');
        $confirm = $w->gateway->last();
        $this->assertSame('Завершить тур? Отвечено 1 из 3.', $confirm['text']);
        $this->assertSame(['Да, завершить', 'Продолжить'], $w->gateway->buttonTexts($confirm['id']));
        $this->assertSame(QuizRound::STATE_PLAYING, $w->round()->getState(), 'asking is not finishing');

        $w->tapLabel('Да, завершить');
        $summary = $w->gateway->live[$confirm['id']]['text']; // the confirmation turns into the summary
        $this->assertStringContainsString('Тур завершён досрочно', $summary);
        $this->assertStringContainsString('Отвечено: 1 из 3', $summary);
        $this->assertStringContainsString('Верно: 1 (100%)', $summary);
        $this->assertStringContainsString('Неверно: 0', $summary);
        $this->assertSame(['Ещё раз', 'Новая игра'], $w->gateway->buttonTexts($confirm['id']));
        $this->assertContains($questionMessage, $w->gateway->keyboardsRemoved);
        $this->assertSame(QuizRound::FINISH_EARLY, $w->round()->getFinishReason());
    }

    public function testFinishBeforeAnyAnswer(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_year', 3, 3);
        $w->say('/stop');
        $w->tapLabel('Да, завершить');

        $this->assertStringContainsString('Вы не ответили ни на один вопрос', $w->gateway->live[$this->lastConfirmId($w)]['text']);
    }

    public function testCancelFinishKeepsTheTourGoing(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_year', 3, 3);
        $w->say('/stop');
        $confirmId = $w->gateway->last()['id'];
        $this->assertStringContainsString('Завершить тур?', $w->gateway->last()['text']);

        $w->tapLabel('Продолжить');
        $this->assertStringContainsString('Продолжаем', $w->gateway->live[$confirmId]['text']);
        $this->assertSame([], $w->gateway->buttonData($confirmId));
        $this->assertSame(QuizRound::STATE_PLAYING, $w->round()->getState());

        $w->answer(true); // the question is still answerable
        $this->assertSame(1, $w->round()->getAnsweredCount());
    }

    public function testStaleConfirmationIsRefused(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_year', 3, 3);
        $w->say('/stop');
        $confirmId = $w->gateway->last()['id'];
        $confirmYes = $w->gateway->dataOfButton($confirmId, 'Да, завершить');

        $w->answer(true); // the player ignores the confirmation and answers; a new question is open
        $w->tap($confirmYes, $confirmId);

        $this->assertSame('Это подтверждение устарело.', $w->gateway->lastToast());
        $this->assertSame(QuizRound::STATE_PLAYING, $w->round()->getState());
    }

    public function testStopWithoutATourAndDuringSetup(): void
    {
        $w = new BotWorld();
        $w->say('/stop');
        $this->assertStringContainsString('нет активного тура', $w->gateway->last()['text']);

        $w->say('/start');
        $w->tap('th:' . $w->theme->getId());
        $w->say('/stop');
        $this->assertSame(QuizRound::FINISH_RESTART, $w->round()->getFinishReason());
        $this->assertStringContainsString('отменена', $w->gateway->last()['text']);
    }

    // ---- /start while a tour is running, restart, "again" --------------------------------------------------------

    public function testContinueShowsTheOpenQuestionAgain(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_year', 3, 3);
        $w->answer(true);
        $before = $w->currentQuestion();

        $w->say('/start');
        $this->assertStringContainsString('отвечено 1 из 3', $w->gateway->last()['text']);
        $this->assertSame(['Продолжить', 'Начать заново'], $w->gateway->buttonTexts($w->gateway->last()['id']));

        $w->tapLabel('Продолжить');
        $after = $w->currentQuestion();
        $this->assertNotSame($before['message_id'], $after['message_id'], 'the question is re-sent at the bottom of the chat');
        $this->assertSame($before['options'], $after['options']);
        $this->assertSame($before['correct'], $after['correct']);
        $this->assertContains($before['message_id'], $w->gateway->keyboardsRemoved, 'the old copy must stop accepting taps');
        $this->assertSame($after['message_id'], $w->gateway->last()['id']);
        $this->assertStringContainsString('Вопрос 2 из 3', $w->gateway->last()['text']);

        $w->answer(true);
        $this->assertSame(2, $w->round()->getAnsweredCount());
    }

    public function testRestartDropsTheTourAndShowsThemes(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_year', 3, 3);
        $w->say('/start');
        $promptId = $w->gateway->last()['id'];

        $w->tapLabel('Начать заново');
        $this->assertSame(QuizRound::FINISH_RESTART, $w->round()->getFinishReason());
        $this->assertStringContainsString('Выберите тему', $w->gateway->live[$promptId]['text']);

        $w->tap('th:' . $w->theme->getId()); // a new tour can start right away
        $this->assertCount(2, $w->store->allRounds());
        $this->assertSame(QuizRound::STATE_CHOOSE_MODE, $w->round()->getState());
    }

    public function testStartDuringSetupJustBeginsAgain(): void
    {
        $w = new BotWorld();
        $w->say('/start');
        $w->tap('th:' . $w->theme->getId());
        $first = $w->round();

        $w->say('/start');
        $this->assertSame(QuizRound::FINISH_RESTART, $first->getFinishReason());
        $this->assertStringContainsString('Выберите тему', $w->gateway->last()['text']);
    }

    public function testBackButtonReturnsToThemes(): void
    {
        $w = new BotWorld();
        $w->say('/start');
        $menu = $w->gateway->last()['id'];
        $w->tap('th:' . $w->theme->getId());
        $w->tap('bk');

        $this->assertStringContainsString('Выберите тему', $w->gateway->live[$menu]['text']);
        $this->assertSame(QuizRound::FINISH_RESTART, $w->round()->getFinishReason());
    }

    public function testAgainRepeatsTheSettings(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_year', 3, 2);
        $w->answer(true);
        $w->answer(false);
        $summaryId = $w->gateway->last()['id'];

        $w->tapLabel('Ещё раз');
        $this->assertContains($summaryId, $w->gateway->keyboardsRemoved, 'the button must not be pressable twice');

        $this->assertCount(2, $w->store->allRounds());
        $round = $w->round();
        $this->assertSame(QuizRound::STATE_PLAYING, $round->getState());
        $this->assertSame('title_by_year', $round->getModeSlug());
        $this->assertSame(3, $round->getOptionsCount());
        $this->assertSame(2, $round->getQuestionsTotal());
        $this->assertSame(0, $round->getAnsweredCount());
        $this->assertNotNull($round->getStartedAt());
        $this->assertStringContainsString('Вопрос 1 из 2', $w->gateway->last()['text']);

        // a second press of the old button while the new tour runs
        $w->tap('ag:' . $w->store->allRounds()[0]->getId(), $summaryId);
        $this->assertSame('У вас идёт тур. Завершите его командой /stop.', $w->gateway->lastToast());
        $this->assertCount(2, $w->store->allRounds());
    }

    public function testNewGameButtonShowsThemes(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_year', 3, 1);
        $w->answer(true);
        $w->tapLabel('Новая игра');

        $this->assertStringContainsString('Выберите тему', $w->gateway->last()['text']);
    }

    // ---- who may play, commands ---------------------------------------------------------------------------------

    public function testPrivateBotOnlyServesListedUsers(): void
    {
        $w = new BotWorld('5, 9');

        $w->say('/start', 7);
        $this->assertStringContainsString('приватный', $w->gateway->last()['text']);
        $this->assertSame([], $w->store->allRounds());

        $w->tap('th:1', $w->gateway->last()['id'], 7);
        $this->assertSame('Этот бот приватный.', $w->gateway->lastToast());

        $w->say('/start', 9);
        $this->assertStringContainsString('Выберите тему', $w->gateway->last()['text']);
    }

    public function testHelpAndUnknownCommand(): void
    {
        $w = new BotWorld();
        $w->say('/help');
        $this->assertStringContainsString('Как играть', $w->gateway->last()['text']);

        $w->say('/dance');
        $this->assertStringContainsString('Неизвестная команда', $w->gateway->last()['text']);
    }

    public function testThemeWithoutPlayableModesIsHidden(): void
    {
        // a theme whose every mode is unusable must not be offered
        $w = new BotWorld();
        foreach ($w->config->modes as $mode) {
            $w->store->addModeStat($w->theme, $mode->slug, 1, 0);
        }

        $w->say('/start');
        $this->assertStringContainsString('нет доступных тем', $w->gateway->last()['text']);
    }

    // ---- pictures ------------------------------------------------------------------------------------------------

    public function testPictureIsUploadedOnceAndThenSentByFileId(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_poster', 3, 14);

        $first = $w->gateway->last();
        $this->assertNotNull($first['photo']);
        $this->assertFalse($first['photo']->isFileId(), 'the first time the file is uploaded');
        $image = $w->currentQuestion()['image'];
        $this->assertSame('FID-' . basename($image), $w->store->cachedFileId($w->theme, $image, $this->sha1Of($image)));
        $this->assertSame('Вопрос 1 из 14' . "\n\n" . 'Что это за фильм?', explode("\n\n1.", $first['text'])[0] === $first['text'] ? $first['text'] : explode("\n\n", $first['text'], 3)[0] . "\n\n" . explode("\n\n", $first['text'], 3)[1]);

        $w->answer(true);
        $this->assertTrue($w->gateway->edits[array_key_last($w->gateway->edits)]['caption'], 'the result goes into the caption of a photo');
        for ($i = 1; $i < 14; ++$i) {
            $w->answer(true);
        }

        // second tour with the same settings: every picture is already known to Telegram
        $w->tapLabel('Ещё раз');
        $photos = array_values(array_filter($w->gateway->sent, static fn (array $m): bool => $m['photo'] !== null));
        $again = $photos[array_key_last($photos)];
        $this->assertTrue($again['photo']->isFileId());
        $this->assertSame('FID-' . basename($w->currentQuestion()['image']), $again['photo']->fileId);
    }

    public function testRejectedFileIdFallsBackToUpload(): void
    {
        $w = new BotWorld();
        $this->seedCache($w, 'STALE');
        $w->gateway->rejectFileIds = true;

        $w->startTour('title_by_poster', 3, 3);

        $photo = $w->gateway->last()['photo'];
        $this->assertNotNull($photo);
        $this->assertFalse($photo->isFileId(), 'uploaded again after Telegram refused the old id');
        $image = $w->currentQuestion()['image'];
        $this->assertSame('FID-' . basename($image), $w->store->cachedFileId($w->theme, $image, $this->sha1Of($image)), 'the cache holds the new id');
    }

    public function testChangedPictureIsNotServedFromTheCache(): void
    {
        $w = new BotWorld();
        for ($i = 1; $i <= 14; ++$i) {
            $path = sprintf('posters/%02d.png', $i);
            $w->store->rememberFileId($w->theme, $path, 'sha1-of-an-older-version', 'OLD');
        }

        $w->startTour('title_by_poster', 3, 3);

        $this->assertFalse($w->gateway->last()['photo']->isFileId(), 'the file differs from what was cached: upload it');
    }

    public function testPictureQuestionsInTheMiddleOfAModeWithPosterShown(): void
    {
        // year_by_poster: the question is about a title, the picture is only shown next to it
        $w = new BotWorld();
        $w->startTour('year_by_poster', 4, 3);

        $first = $w->gateway->last();
        $this->assertNotNull($first['photo']);
        $this->assertStringContainsString('В каком году вышел фильм', $first['text']);
        $this->assertCount(5, $w->gateway->buttonData($first['id']));
    }

    public function testTourEndsEarlyWhenAPictureIsMissing(): void
    {
        $records = BotWorld::films();
        $records[] = new \App\Quiz\ItemRecord(15, [
            'title' => 'Призрак', 'year' => 2001, 'director' => ['X'], 'actors' => ['Y'], 'genre' => ['драма'],
            'poster' => 'posters/this-file-is-gone.png',
        ]);
        $w = new BotWorld('', $records);
        $w->startTour('title_by_poster', 3, 15); // the import counted 15, one picture vanished afterwards

        for ($i = 0; $i < 14; ++$i) {
            $w->answer(true);
        }

        $round = $w->round();
        $this->assertSame(QuizRound::STATE_FINISHED, $round->getState());
        $this->assertSame(QuizRound::FINISH_EARLY, $round->getFinishReason());
        $this->assertStringContainsString('Отвечено: 14 из 15', $w->gateway->last()['text']);
        $this->assertNotContains(15, $round->getUsedItemIds());
    }

    // ---- helpers --------------------------------------------------------------------------------------------------

    /** Message id of the n-th question message sent in the chat (0-based), ignoring menus. */
    private function questionMessageId(BotWorld $w, int $n): int
    {
        $ids = [];
        foreach ($w->gateway->sent as $message) {
            if (str_starts_with($message['text'], 'Вопрос ')) {
                $ids[] = $message['id'];
            }
        }

        return $ids[$n];
    }

    // ---- typed answers, repetition, statistics --------------------------------------------------------------------

    public function testTypedAnswerIgnoresCaseAndSpaces(): void
    {
        $w = new BotWorld();
        $w->startTour('year_by_title', 0, 3);
        $q = $w->currentQuestion();
        $this->assertSame([], $q['options']);
        $this->assertStringContainsString('Напишите ответ', $w->gateway->live[$q['message_id']]['text']);
        $this->assertCount(1, $w->gateway->buttonData($q['message_id']), 'only the finish button');

        $w->say('  ' . $q['correct_text'] . ' ');
        $this->assertStringContainsString('✅ Верно!', $w->gateway->live[$q['message_id']]['text']);

        $q = $w->currentQuestion();
        $w->say('0000');
        $this->assertStringContainsString('❌ Неверно. Правильный ответ: ' . $q['correct_text'], $w->gateway->live[$q['message_id']]['text']);

        $this->assertSame(1, $w->round()->getCorrectCount());
        $this->assertSame(1, $w->round()->getWrongCount());
    }

    public function testTypedAnswerShowsFactsOfTheNamedItemAndTheRightAnswer(): void
    {
        $records = [
            new \App\Quiz\ItemRecord(1, ['title' => 'Челюсти', 'year' => 1975, 'director' => ['Стивен Спилберг'], 'actors' => ['Рой Шайдер'], 'genre' => ['ужасы'], 'poster' => 'posters/01.png']),
            new \App\Quiz\ItemRecord(2, ['title' => 'Сало', 'year' => 1975, 'director' => ['Пьер Паоло Пазолини'], 'actors' => ['Паоло Боначелли'], 'genre' => ['драма'], 'poster' => 'posters/02.png']),
            new \App\Quiz\ItemRecord(3, ['title' => 'Одиночка', 'year' => 2000, 'director' => ['Б'], 'actors' => ['Б'], 'genre' => ['драма'], 'poster' => 'posters/03.png']),
        ];
        for ($try = 0; $try < 8; $try++) {
            $w = new BotWorld('', $records);
            $w->startTour('title_by_year', 0, 1);
            $q = $w->currentQuestion();
            if (!in_array('Челюсти', $q['accepted'], true)) {
                continue; // the year 2000 was asked
            }
            $w->say('челюсти');
            $text = $w->gateway->live[$q['message_id']]['text'];
            $this->assertStringContainsString('Правильный ответ: Челюсти', $text);
            $this->assertStringContainsString('Стивен Спилберг', $text);
            $this->assertStringNotContainsString('Пазолини', $text);
        }
        $this->assertTrue(true);
    }

    public function testTypedTitleMatchesWithoutCapitalsAndSpaces(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_year', 0, 1);
        $q = $w->currentQuestion();
        $w->say(mb_strtoupper(str_replace(' ', '  ', $q['correct_text'])));
        $this->assertSame(1, $w->round()->getCorrectCount());
    }

    public function testWrongAnswersComeBackFirstInTheNextTour(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_year', 4, 3);
        $wrongKeys = [];
        for ($i = 0; $i < 3; $i++) {
            $q = $w->currentQuestion();
            $correct = $i === 0; // only the first one is right
            if (!$correct) {
                $wrongKeys[] = $q['item_key'];
            }
            $w->answer($correct);
        }
        $this->assertCount(2, $wrongKeys);

        $w->tapLabel('Ещё раз');
        $asked = [$w->currentQuestion()['item_key']];
        $w->answer(true);
        if ($w->round()->getState() === QuizRound::STATE_PLAYING) {
            $asked[] = $w->currentQuestion()['item_key'];
        }
        foreach ($asked as $key) {
            $this->assertContains($key, $wrongKeys, 'the two mistakes are repeated before anything else');
        }
    }

    public function testStatsCommand(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_year', 4, 2);
        $w->answer(true);
        $w->answer(false);
        $w->say('/stats');
        $text = $w->gateway->last()['text'];
        $this->assertStringContainsString('Ваш прогресс', $text);
        $this->assertStringContainsString('Фильм по году: выучено 0 из', $text);
        $this->assertStringContainsString('начато 2', $text);
        $this->assertStringContainsString('верных ответов 50%', $text);
    }

    public function testPictureFromAnswerInfoIsSentAfterTheAnswer(): void
    {
        $w = new BotWorld();
        $w->startTour('title_by_actor', 3, 2);
        $q = $w->currentQuestion();
        $this->assertCount(1, $q['answer_images']);
        $before = count($w->gateway->sent);

        $w->answer(false);
        $photos = array_values(array_filter(array_slice($w->gateway->sent, $before), static fn (array $m): bool => $m['photo'] !== null && $m['text'] === ''));
        $this->assertCount(1, $photos, 'one picture right after the answer');
        $this->assertStringContainsString('Год выхода: ', $w->gateway->live[$q['message_id']]['text']);
        $this->assertStringNotContainsString('Постер', $w->gateway->live[$q['message_id']]['text'], 'the picture is not a text fact');
        $this->assertSame(1, $w->round()->getWrongCount());
        $this->assertSame(2, $w->round()->getCurrentNumber());
    }

    // ---- "name N elements" ----------------------------------------------------------------------------------------

    public function testListQuestionShowsProgressAndWrongAnswers(): void
    {
        $w = new BotWorld();
        $w->startTour('list_titles_by_year', 2, 1);
        $q = $w->currentQuestion();
        $this->assertSame('list', $q['kind']);
        $this->assertSame(2, $q['need']);
        $this->assertStringContainsString('Названо 0 из 2', $w->gateway->live[$q['message_id']]['text']);

        $w->say('Совсем не фильм');
        $text = $w->gateway->live[$q['message_id']]['text'];
        $this->assertStringContainsString('❌ Совсем не фильм', $text);
        $this->assertSame(1, $w->round()->getWrongCount());

        $first = $q['accepted'][0];
        $w->say(mb_strtolower($first));
        $text = $w->gateway->live[$q['message_id']]['text'];
        $this->assertStringContainsString('Названо 1 из 2', $text);
        $this->assertStringContainsString('1. ' . $first, $text);

        $w->say($first);
        $this->assertStringContainsString('уже называли', $w->gateway->live[$q['message_id']]['text']);
        $this->assertSame(1, $w->round()->getCorrectCount(), 'a repeated element gives no point');
        $this->assertSame(1, $w->round()->getWrongCount(), 'and is not a mistake');
        $this->assertSame(QuizRound::STATE_PLAYING, $w->round()->getState());
    }

    public function testListQuestionIsSolvedWhenEnoughElementsAreNamed(): void
    {
        $w = new BotWorld();
        $w->startTour('list_titles_by_year', 2, 2);
        $q = $w->currentQuestion();
        $w->say($q['accepted'][0] . ";\n" . $q['accepted'][1]);

        $text = $w->gateway->live[$q['message_id']]['text'];
        $this->assertStringContainsString('Названо 2 из 2. Отлично!', $text);
        $this->assertSame(2, $w->round()->getCorrectCount());
        $this->assertSame(2, $w->round()->getCurrentNumber(), 'the next question is open');
        $this->assertSame(1, $w->round()->getAnsweredCount());
    }

    public function testEnoughButtonClosesTheQuestionAndShowsAtMostTenMissing(): void
    {
        $w = new BotWorld();
        $w->startTour('list_titles_by_year', 3, 1);
        $q = $w->currentQuestion();
        $messageId = $q['message_id'];
        $w->say($q['accepted'][0]);
        $w->tapLabel('Хватит, дальше');

        $text = $w->gateway->live[$messageId]['text'];
        $this->assertStringContainsString('❌ Названо 1 из 3.', $text);
        $this->assertStringContainsString('Ещё подходило: ', $text);
        $this->assertSame(QuizRound::STATE_FINISHED, $w->round()->getState());
        $this->assertStringContainsString('Вопросов пройдено: 1 из 1', $w->gateway->last()['text']);
        $this->assertSame(1, $w->round()->getAnsweredCount());

        $key = $q['item_key'];
        $progress = $w->store->progress($w->round()->getPlayer(), $w->theme, 'list_titles_by_year');
        $this->assertSame(0, $progress[$key]->box, 'a list question that was not finished is a mistake');
    }

    public function testListSizeMustBeAtLeastOne(): void
    {
        $w = new BotWorld();
        $w->say('/start');
        $w->tap('th:' . $w->theme->getId());
        $w->tap(sprintf('md:%d:%s', $w->round()->getId(), 'list_titles_by_year'));
        $w->say('0');
        $this->assertSame(QuizRound::STATE_AWAIT_OPTIONS, $w->round()->getState());
        $w->say('2');
        $this->assertSame(QuizRound::STATE_AWAIT_QUESTIONS, $w->round()->getState());
    }

    public function testListSplitsMessagesButKeepsTitlesWithCommas(): void
    {
        $records = [
            new \App\Quiz\ItemRecord(1, ['title' => 'Ирония судьбы, или С лёгким паром', 'year' => 1975, 'director' => ['Э. Рязанов'], 'actors' => ['А'], 'genre' => ['комедия'], 'poster' => 'posters/01.png']),
            new \App\Quiz\ItemRecord(2, ['title' => 'Другое кино', 'year' => 1975, 'director' => ['Э. Рязанов'], 'actors' => ['А'], 'genre' => ['комедия'], 'poster' => 'posters/01.png']),
            new \App\Quiz\ItemRecord(3, ['title' => 'Одиночка', 'year' => 2000, 'director' => ['Б'], 'actors' => ['Б'], 'genre' => ['драма'], 'poster' => 'posters/02.png']),
        ];
        $w = new BotWorld('', $records);
        $w->startTour('list_titles_by_year', 2, 1);
        $q = $w->currentQuestion();
        $w->say('ирония судьбы, или с легким паром');
        $this->assertStringContainsString('Названо 1 из 2', $w->gateway->live[$q['message_id']]['text']);
        $this->assertSame(0, $w->round()->getWrongCount());
    }

    private function lastConfirmId(BotWorld $w): int
    {
        $id = null;
        foreach ($w->gateway->sent as $message) {
            if (str_starts_with($message['text'], 'Завершить тур?')) {
                $id = $message['id'];
            }
        }

        return $id ?? throw new \LogicException('no confirmation was sent');
    }

    private function sha1Of(string $image): string
    {
        return (string) sha1_file(dirname(__DIR__) . '/fixtures/themes/films/images/' . $image);
    }

    private function seedCache(BotWorld $w, string $fileId): void
    {
        for ($i = 1; $i <= 14; ++$i) {
            $path = sprintf('posters/%02d.png', $i);
            $w->store->rememberFileId($w->theme, $path, $this->sha1Of($path), $fileId);
        }
    }
}
