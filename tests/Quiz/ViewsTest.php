<?php

declare(strict_types=1);

namespace App\Tests\Quiz;

use App\Bot\Views;
use PHPUnit\Framework\TestCase;

final class ViewsTest extends TestCase
{
    public function testShortOptionsAreButtonLabels(): void
    {
        $v = Views::question(12, 3, 10, 'Какой из этих фильмов снят в 2020 году?', ['Довод', 'Душа', 'Дюна'], false);

        $this->assertSame("Вопрос 3 из 10\n\nКакой из этих фильмов снят в 2020 году?", $v['text']);
        $this->assertCount(4, $v['keyboard'], 'three options + the finish row');
        $this->assertSame('Довод', $v['keyboard'][0][0]['text']);
        $this->assertSame('an:12:3:0', $v['keyboard'][0][0]['data']);
        $this->assertSame('an:12:3:2', $v['keyboard'][2][0]['data']);
        $this->assertSame('Завершить', $v['keyboard'][3][0]['text']);
        $this->assertSame('fin:12:3', $v['keyboard'][3][0]['data']);
    }

    public function testLongOptionsAreNumberedInTheText(): void
    {
        $options = ['Очень длинное название фильма номер один', 'Короткое', 'Ещё одно очень длинное название фильма', 'Four', 'Five', 'Six'];
        $v = Views::question(5, 1, 3, 'Вопрос?', $options, false);

        $this->assertStringContainsString("1. Очень длинное название фильма номер один\n2. Короткое", $v['text']);
        $this->assertSame('1', $v['keyboard'][0][0]['text']);
        $this->assertCount(5, $v['keyboard'][0], 'numbers are packed 5 per row');
        $this->assertCount(1, $v['keyboard'][1]);
        $this->assertSame('an:5:1:5', $v['keyboard'][1][0]['data']);
        $this->assertSame('Завершить', $v['keyboard'][2][0]['text']);
    }

    public function testCallbackDataFitsTelegramLimit(): void
    {
        $longest = [
            Views::cbAnswer(2147483647, 999, 9),
            Views::cbMode(2147483647, str_repeat('m', 32)),
            Views::cbFinishYes(2147483647, 999),
        ];
        foreach ($longest as $data) {
            $this->assertGreaterThan(strlen($data) - 1, 64, $data);
        }
    }

    public function testCaptionsAreKeptUnderTelegramLimit(): void
    {
        $options = array_fill(0, 10, str_repeat('Очень длинный вариант ', 8));
        $v = Views::question(1, 1, 5, str_repeat('Длинный вопрос. ', 100), $options, true);
        $this->assertGreaterThan(mb_strlen($v['text']), Views::CAPTION_LIMIT);

        $withFeedback = Views::answered($v['text'], false, str_repeat('Ответ ', 50), true);
        $this->assertGreaterThan(mb_strlen($withFeedback) - 1, Views::CAPTION_LIMIT);
    }

    public function testFeedback(): void
    {
        $this->assertStringContainsString('✅ Верно!', Views::answered('Вопрос', true, '2020', false));
        $wrong = Views::answered('Вопрос', false, '2020', false);
        $this->assertStringContainsString('❌ Неверно. Правильный ответ: 2020', $wrong);
        $this->assertStringContainsString('Вопрос', $wrong);
    }

    public function testSummaryForACompletedRound(): void
    {
        $text = Views::summary('completed', 7, 3, 10, 125);
        $this->assertStringContainsString('Верно: 7 из 10 (70%)', $text);
        $this->assertStringContainsString('Неверно: 3', $text);
        $this->assertStringContainsString('Время: 02:05', $text);
    }

    public function testSummaryForAnEarlyFinish(): void
    {
        $text = Views::summary('early', 2, 1, 10, 61);
        $this->assertStringContainsString('досрочно', $text);
        $this->assertStringContainsString('Отвечено: 3 из 10', $text);
        $this->assertStringContainsString('Верно: 2 (67%)', $text);
        $this->assertStringContainsString('Время: 01:01', $text);

        $none = Views::summary('early', 0, 0, 10, 4);
        $this->assertStringContainsString('не ответили ни на один вопрос', $none);
    }

    public function testDurationAndPercent(): void
    {
        $this->assertSame('00:00', Views::duration(0));
        $this->assertSame('00:00', Views::duration(-5));
        $this->assertSame('59:59', Views::duration(3599));
        $this->assertSame('1:00:00', Views::duration(3600));
        $this->assertSame('2:03:04', Views::duration(7384));
        $this->assertSame(0, Views::percent(0, 0));
        $this->assertSame(33, Views::percent(1, 3));
        $this->assertSame(100, Views::percent(5, 5));
    }
}
