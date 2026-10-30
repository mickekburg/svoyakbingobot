<?php

declare(strict_types=1);

namespace App\Bot;

/**
 * Everything the player reads or taps: message texts (Russian) and inline keyboards.
 * Pure functions, no I/O — covered by unit tests.
 *
 * A keyboard is a list of rows; a button is ['text' => ..., 'data' => callback_data (<= 64 bytes)].
 */
final class Views
{
    public const CAPTION_LIMIT = 1024;
    public const TEXT_LIMIT = 4096;

    /** Options up to this length are shown right on the buttons; longer ones are numbered in the message text. */
    private const LABEL_MAX = 36;
    private const NUMBER_BUTTONS_PER_ROW = 5;

    // ---- callback data ------------------------------------------------------------------------------------------

    public static function cbTheme(int $themeId): string
    {
        return 'th:' . $themeId;
    }

    public static function cbMode(int $roundId, string $modeSlug): string
    {
        return sprintf('md:%d:%s', $roundId, $modeSlug);
    }

    public static function cbAnswer(int $roundId, int $number, int $index): string
    {
        return sprintf('an:%d:%d:%d', $roundId, $number, $index);
    }

    public static function cbListEnough(int $roundId, int $number): string
    {
        return sprintf('lk:%d:%d', $roundId, $number);
    }

    public static function cbFinish(int $roundId, int $number): string
    {
        return sprintf('fin:%d:%d', $roundId, $number);
    }

    public static function cbFinishYes(int $roundId, int $number): string
    {
        return sprintf('fy:%d:%d', $roundId, $number);
    }

    public static function cbFinishCancel(int $roundId, int $number): string
    {
        return sprintf('fc:%d:%d', $roundId, $number);
    }

    // ---- menus --------------------------------------------------------------------------------------------------

    public static function welcome(): string
    {
        return "Привет! Это тренировочная викторина.\nВыберите тему:";
    }

    public static function help(): string
    {
        return "Как играть:\n"
            . "1. /start — выберите тему и режим.\n"
            . "2. Введите, сколько вариантов ответа показывать и сколько вопросов в туре.\n"
            . "3. Отвечайте кнопками. Правильный ответ показывается сразу.\n"
            . "   Если ввести 0 вариантов, ответ нужно написать самому.\n"
            . "4. «Завершить» или /stop — закончить тур досрочно. В конце — итог: верные и неверные ответы и время.";
    }

    /**
     * @param list<array{title: string, modes: list<array{title: string, total: int, seen: int, learned: int, due: int, correct: int, wrong: int}>}> $themes
     */
    public static function stats(array $themes): string
    {
        if ($themes === []) {
            return 'Пока нечего показывать. Начните игру: /start';
        }
        $out = ["📊 Ваш прогресс\n«Выучено» — ответы верны несколько раз подряд с растущими паузами; «пора повторить» — то, что бот покажет в первую очередь."];
        foreach ($themes as $theme) {
            $out[] = '';
            $out[] = '— ' . $theme['title'];
            foreach ($theme['modes'] as $m) {
                $answers = $m['correct'] + $m['wrong'];
                $out[] = sprintf(
                    '%s: выучено %d из %d, начато %d, пора повторить %d%s',
                    $m['title'],
                    $m['learned'],
                    $m['total'],
                    $m['seen'],
                    $m['due'],
                    $answers > 0 ? sprintf(', верных ответов %d%%', self::percent($m['correct'], $answers)) : '',
                );
            }
        }

        return self::cut(implode("\n", $out), self::TEXT_LIMIT);
    }

    public static function noThemes(): string
    {
        return 'Пока нет доступных тем. Загляните позже.';
    }

    /** @param list<array{id: int, title: string}> $themes */
    public static function themeKeyboard(array $themes): array
    {
        return array_map(static fn (array $t): array => [['text' => $t['title'], 'data' => self::cbTheme($t['id'])]], $themes);
    }

    /** @param list<array{slug: string, title: string}> $modes */
    public static function modeMenu(string $themeTitle, int $roundId, array $modes): array
    {
        $rows = array_map(static fn (array $m): array => [['text' => $m['title'], 'data' => self::cbMode($roundId, $m['slug'])]], $modes);
        $rows[] = [['text' => '« Назад', 'data' => 'bk']];

        return ['text' => sprintf("Тема: %s\nВыберите режим:", $themeTitle), 'keyboard' => $rows];
    }

    public static function askOptions(string $modeTitle, int $min, int $max): string
    {
        return sprintf(
            "Режим: %s\nСколько вариантов ответа показывать в каждом вопросе? Введите число от %d до %d.\nИли 0 — без вариантов: ответ нужно будет вписать самому (регистр и пробелы не важны).",
            $modeTitle,
            $min,
            $max,
        );
    }

    public static function askListSize(string $modeTitle, int $max): string
    {
        return sprintf("Режим: %s\nСколько элементов нужно назвать в каждом вопросе? Введите число от 1 до %d.\nЗа каждый верный элемент — один балл. Ответы пишите сообщениями (регистр, пробелы и мелкие опечатки не страшны).", $modeTitle, $max);
    }

    public static function badOptions(int $min, int $max): string
    {
        return sprintf('Нужно целое число от %d до %d или 0 (без вариантов). Попробуйте ещё раз.', $min, $max);
    }

    public static function askQuestions(int $max): string
    {
        return sprintf('Сколько вопросов в туре? Введите число от 1 до %d.', $max);
    }

    public static function badNumber(int $min, int $max): string
    {
        return sprintf('Нужно целое число от %d до %d. Попробуйте ещё раз.', $min, $max);
    }

    public static function notEnoughData(): string
    {
        return 'Для этого режима не хватает данных. Выберите другой режим: /start';
    }

    // ---- question -----------------------------------------------------------------------------------------------

    /**
     * @param list<string> $options
     *
     * @return array{text: string, keyboard: list<list<array{text: string, data: string}>>}
     */
    public static function question(int $roundId, int $number, int $total, string $text, array $options, bool $asCaption): array
    {
        $maxLen = 0;
        foreach ($options as $o) {
            $maxLen = max($maxLen, mb_strlen($o));
        }

        $message = sprintf("Вопрос %d из %d\n\n%s", $number, $total, $text);
        if ($options === []) {
            $message .= "\n\n✍️ Напишите ответ сообщением.";
        }
        $rows = [];

        if ($maxLen <= self::LABEL_MAX) {
            foreach ($options as $i => $o) {
                $rows[] = [['text' => $o, 'data' => self::cbAnswer($roundId, $number, $i)]];
            }
        } else {
            $lines = [];
            $buttons = [];
            foreach ($options as $i => $o) {
                $lines[] = sprintf('%d. %s', $i + 1, self::cut($o, 90));
                $buttons[] = ['text' => (string) ($i + 1), 'data' => self::cbAnswer($roundId, $number, $i)];
            }
            $message .= "\n\n" . implode("\n", $lines);
            $rows = array_chunk($buttons, self::NUMBER_BUTTONS_PER_ROW);
        }
        $rows[] = [['text' => 'Завершить', 'data' => self::cbFinish($roundId, $number)]];

        return ['text' => self::cut($message, $asCaption ? self::CAPTION_LIMIT - 120 : self::TEXT_LIMIT - 200), 'keyboard' => $rows];
    }

    /**
     * A "name N elements" question with what has been named so far.
     *
     * @param list<string>                         $named   accepted elements, in the order they were named
     * @param list<array{kind: string, text: string}> $results what the last message gave: kind is ok | wrong | again
     *
     * @return array{text: string, keyboard: list<list<array{text: string, data: string}>>}
     */
    public static function listQuestion(int $roundId, int $number, int $total, string $text, int $need, array $named, array $results = []): array
    {
        $lines = [sprintf("Вопрос %d из %d\n\n%s", $number, $total, $text), ''];
        $lines[] = sprintf('Названо %d из %d%s', count($named), $need, $named === [] ? '.' : ':');
        foreach ($named as $i => $value) {
            $lines[] = sprintf('%d. %s', $i + 1, self::cut($value, 100));
        }
        if ($results !== []) {
            $lines[] = '';
            foreach ($results as $r) {
                $lines[] = match ($r['kind']) {
                    'ok' => '✅ ' . self::cut($r['text'], 100),
                    'again' => '↩️ ' . self::cut($r['text'], 100) . ' — уже называли',
                    default => '❌ ' . self::cut($r['text'], 100) . ' — нет в списке',
                };
            }
        }
        $lines[] = '';
        $lines[] = '✍️ Пишите ответы сообщениями: по одному, с новой строки или через «;».';

        return [
            'text' => self::cut(implode("\n", $lines), self::TEXT_LIMIT - 100),
            'keyboard' => [
                [['text' => 'Хватит, дальше', 'data' => self::cbListEnough($roundId, $number)]],
                [['text' => 'Завершить', 'data' => self::cbFinish($roundId, $number)]],
            ],
        ];
    }

    /**
     * The result of a closed "name N elements" question.
     *
     * @param list<string> $named
     * @param list<string> $rest  correct elements that were not named (at most 10 are shown)
     */
    public static function listFinished(string $text, int $need, array $named, bool $success, array $rest): string
    {
        $lines = [$text, ''];
        $lines[] = $success ? sprintf('✅ Названо %d из %d. Отлично!', count($named), $need) : sprintf('❌ Названо %d из %d.', count($named), $need);
        foreach ($named as $i => $value) {
            $lines[] = sprintf('%d. %s', $i + 1, self::cut($value, 100));
        }
        if ($rest !== []) {
            $lines[] = '';
            $lines[] = 'Ещё подходило: ' . implode('; ', array_map(static fn (string $v): string => self::cut($v, 60), array_slice($rest, 0, 10)));
        }

        return self::cut(implode("\n", $lines), self::TEXT_LIMIT);
    }

    public static function answered(string $messageText, bool $correct, string $correctAnswer, bool $asCaption, string $info = '', ?string $typoOf = null): string
    {
        $limit = $asCaption ? self::CAPTION_LIMIT : self::TEXT_LIMIT;
        $verdict = $correct ? "\n\n✅ Верно!" . ($typoOf !== null ? ' Засчитано, но с опечаткой: правильно «' . self::cut($typoOf, 120) . '».' : ' Правильный ответ: ' . self::cut($correctAnswer, 120)) : "\n\n❌ Неверно. Правильный ответ: " . self::cut($correctAnswer, 120);
        $text = self::cut($messageText, $limit - mb_strlen($verdict));
        $text .= $verdict;

        if ($info !== '') {
            // the facts are the point of the answer: they take the room, the question text is what gets cut
            $room = $limit - mb_strlen($text) - 2;
            if ($room < 40) {
                $text = self::cut($messageText, max(0, $limit - mb_strlen($verdict) - min(mb_strlen($info), 400) - 2)) . $verdict;
                $room = $limit - mb_strlen($text) - 2;
            }
            $text .= "\n\n" . self::cut($info, max(0, $room));
        }

        return self::cut($text, $limit);
    }

    // ---- finishing ----------------------------------------------------------------------------------------------

    public static function confirmFinish(int $answered, int $total): string
    {
        return sprintf('Завершить тур? Отвечено %d из %d.', $answered, $total);
    }

    public static function confirmKeyboard(int $roundId, int $number): array
    {
        return [[
            ['text' => 'Да, завершить', 'data' => self::cbFinishYes($roundId, $number)],
            ['text' => 'Продолжить', 'data' => self::cbFinishCancel($roundId, $number)],
        ]];
    }

    public static function unfinishedPrompt(int $answered, int $total): string
    {
        return sprintf('У вас есть незавершённый тур (отвечено %d из %d). Продолжить?', $answered, $total);
    }

    public static function unfinishedKeyboard(int $roundId): array
    {
        return [[
            ['text' => 'Продолжить', 'data' => 'go:' . $roundId],
            ['text' => 'Начать заново', 'data' => 'rs:' . $roundId],
        ]];
    }

    public static function summary(string $reason, int $correct, int $wrong, int $total, int $seconds, bool $listMode = false, int $questionsDone = 0): string
    {
        $time = self::duration($seconds);
        if ($listMode) {
            return sprintf(
                "🏁 Тур завершён%s!\nВопросов пройдено: %d из %d\nНазвано верно: %d\nНеверных ответов: %d\nВремя: %s",
                $reason === 'completed' ? '' : ' досрочно',
                $questionsDone,
                $total,
                $correct,
                $wrong,
                $time,
            );
        }
        $answered = $correct + $wrong;

        if ($reason === 'completed') {
            return sprintf(
                "🏁 Тур завершён!\nВерно: %d из %d (%d%%)\nНеверно: %d\nВремя: %s",
                $correct,
                $total,
                self::percent($correct, $total),
                $wrong,
                $time,
            );
        }

        if ($answered === 0) {
            return sprintf("🏁 Тур завершён досрочно.\nВы не ответили ни на один вопрос.\nВремя: %s", $time);
        }

        return sprintf(
            "🏁 Тур завершён досрочно.\nОтвечено: %d из %d\nВерно: %d (%d%%)\nНеверно: %d\nВремя: %s",
            $answered,
            $total,
            $correct,
            self::percent($correct, $answered),
            $wrong,
            $time,
        );
    }

    public static function summaryKeyboard(int $roundId): array
    {
        return [[
            ['text' => 'Ещё раз', 'data' => 'ag:' . $roundId],
            ['text' => 'Новая игра', 'data' => 'nw'],
        ]];
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds >= 3600) {
            return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public static function percent(int $part, int $whole): int
    {
        return $whole <= 0 ? 0 : (int) round($part * 100 / $whole);
    }

    public static function cut(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
}
