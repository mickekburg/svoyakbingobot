<?php

declare(strict_types=1);

namespace App\Tests\Quiz;

use App\Bot\Views;
use App\Quiz\ConfigException;
use App\Quiz\ItemRecord;
use App\Quiz\PhpRandom;
use App\Quiz\QuestionGenerator;
use App\Quiz\ThemeConfig;
use App\Quiz\ThemeConfigLoader;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;

/** answer_info (extra facts shown after the answer) and the two "cut (...)" flags. */
final class AnswerInfoTest extends TestCase
{
    /** @return array<string, mixed> */
    private function personConfig(): array
    {
        return [
            'slug' => 'people', 'title' => 'Люди', 'source' => 'people.xlsx',
            'answer_info' => ['name', 'city', 'country', 'years', 'books'],
            'fields' => [
                'name' => ['type' => 'string', 'label' => 'Имя'],
                'city' => ['type' => 'string', 'label' => 'Город'],
                'country' => ['type' => 'string', 'label' => 'Страна'],
                'years' => ['type' => 'string', 'label' => 'Годы жизни'],
                'books' => ['type' => 'list', 'label' => 'Книги', 'strip_brackets_in_question' => true, 'strip_brackets_in_answer' => true],
            ],
            'modes' => [
                ['slug' => 'name_by_book', 'title' => 'Автор по книге', 'by' => 'books', 'what' => 'name', 'template' => 'Кто написал «{value}»?'],
                ['slug' => 'book_by_name', 'title' => 'Книга по автору', 'by' => 'name', 'what' => 'books', 'template' => 'Какую книгу написал {value}?'],
                ['slug' => 'city_by_name', 'title' => 'Город', 'by' => 'name', 'what' => 'city', 'template' => 'Где родился {value}?', 'answer_info' => ['years']],
                ['slug' => 'years_by_name', 'title' => 'Годы', 'by' => 'name', 'what' => 'years', 'template' => 'Годы жизни: {value}?', 'answer_info' => []],
            ],
        ];
    }

    private function config(): ThemeConfig
    {
        return (new ThemeConfigLoader())->fromArray($this->personConfig(), 'people.json');
    }

    /** @return list<ItemRecord> */
    private function items(): array
    {
        $rows = [
            ['Александр Пушкин', 'Москва', 'Российская империя', '1799–1837', ['Евгений Онегин (1825)', 'Борис Годунов (1831)', 'Капитанская дочка (1836)']],
            ['Михаил Лермонтов', 'Москва', 'Российская империя', '1814–1841', ['Герой нашего времени (1840)', 'Мцыри (1839)']],
            ['Лев Толстой', 'Ясная Поляна', 'Российская империя', '1828–1910', ['Война и мир (1869)', 'Анна Каренина (1877)']],
            ['Фёдор Достоевский', 'Москва', 'Российская империя', '1821–1881', ['Идиот (1869)', 'Бесы (1872)']],
            ['Николай Гоголь', 'Сорочинцы', 'Российская империя', '1809–1852', ['Мёртвые души (1842)', 'Ревизор (1836)']],
            ['Иван Тургенев', 'Орёл', 'Российская империя', '1818–1883', ['Отцы и дети (1862)', 'Рудин (1856)']],
        ];
        $out = [];
        foreach ($rows as $i => [$name, $city, $country, $years, $books]) {
            $out[] = new ItemRecord($i + 1, ['name' => $name, 'city' => $city, 'country' => $country, 'years' => $years, 'books' => $books]);
        }

        return $out;
    }

    private function generator(int $seed = 1): QuestionGenerator
    {
        return new QuestionGenerator(new PhpRandom(new Xoshiro256StarStar($seed)));
    }

    public function testStripBrackets(): void
    {
        $this->assertSame('Евгений Онегин', QuestionGenerator::stripBrackets('Евгений Онегин (1825)'));
        $this->assertSame('Война и мир', QuestionGenerator::stripBrackets('Война и мир (роман) (1869)'));
        $this->assertSame('Книга', QuestionGenerator::stripBrackets('Книга (а (б) в)'));
        $this->assertSame('(1825)', QuestionGenerator::stripBrackets('(1825)'), 'nothing else left: the text is kept');
    }

    public function testQuestionTextAndOptionsHaveNoBracketsButInfoKeepsThem(): void
    {
        $config = $this->config();
        $items = $this->items();

        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $seed) {
            $q = $this->generator($seed)->generate($config, $config->mode('name_by_book'), $items, 4);
            $this->assertNotNull($q);
            $this->assertStringNotContainsString('(', $q->text, 'question');
            $this->assertStringNotContainsString('1', $q->text, 'no year hint in the question');

            $q = $this->generator($seed)->generate($config, $config->mode('book_by_name'), $items, 4);
            $this->assertNotNull($q);
            foreach ($q->options as $option) {
                $this->assertStringNotContainsString('(', $option, 'option');
            }
            // by = name, what = books: both are skipped in the info, the rest is shown in full
            $this->assertStringContainsString('Город: ', $q->info);
            $this->assertStringContainsString('Годы жизни: ', $q->info);
            $this->assertStringNotContainsString('Имя: ', $q->info);
            $this->assertStringNotContainsString('Книги: ', $q->info);
        }
    }

    public function testBooksInfoIsShownWithYearsWhenBooksIsNotTheQuestion(): void
    {
        $config = $this->config();
        $mode = $config->mode('name_by_book');
        $q = $this->generator(3)->generate($config, $mode, $this->items(), 3);
        $this->assertNotNull($q);
        // by = books, what = name -> the info lists the other fields but not these two
        $this->assertSame(['city', 'country', 'years'], $config->answerInfoFor($mode));
        $this->assertStringNotContainsString('Книги', $q->info);
        $this->assertStringContainsString('Страна: Российская империя', $q->info);
    }

    public function testQuestionAndAnswerFieldsAreNotRepeatedInTheInfo(): void
    {
        $config = $this->config();
        $this->assertSame(['city', 'country', 'years'], $config->answerInfoFor($config->mode('name_by_book')));
        $this->assertSame(['city', 'country', 'years'], $config->answerInfoFor($config->mode('book_by_name')));
        $this->assertSame(['years'], $config->answerInfoFor($config->mode('city_by_name')));
    }

    public function testModeOverrideAndEmptyList(): void
    {
        $config = $this->config();
        $items = $this->items();

        $q = $this->generator(2)->generate($config, $config->mode('city_by_name'), $items, 3);
        $this->assertNotNull($q);
        $this->assertSame(1, preg_match('/^Годы жизни: \d{4}–\d{4}$/u', $q->info));

        $q = $this->generator(2)->generate($config, $config->mode('years_by_name'), $items, 2);
        $this->assertNotNull($q);
        $this->assertSame('', $q->info);
    }

    public function testStrippedOptionsStayUnique(): void
    {
        // two authors with a book of the same title but different years: it is one option, and never a wrong one
        $config = $this->config();
        $items = $this->items();
        $items[] = new ItemRecord(7, ['name' => 'Другой автор', 'city' => 'Киев', 'country' => 'X', 'years' => '1900–1950', 'books' => ['Идиот (1951)']]);

        $mode = $config->mode('name_by_book');
        for ($seed = 1; $seed <= 60; $seed++) {
            $q = $this->generator($seed)->generate($config, $mode, $items, 5);
            $this->assertNotNull($q);
            $this->assertSame(count($q->options), count(array_unique($q->options)));
            if ($q->text === 'Кто написал «Идиот»?') {
                $this->assertNotContains('Фёдор Достоевский', array_diff($q->options, [$q->correctAnswer()]));
                $this->assertNotContains('Другой автор', array_diff($q->options, [$q->correctAnswer()]));
            }
        }
    }

    public function testFlagsOnAModeAreEnough(): void
    {
        $data = $this->personConfig();
        unset($data['fields']['books']['strip_brackets_in_question'], $data['fields']['books']['strip_brackets_in_answer']);
        foreach ([0, 1] as $i) {
            $data['modes'][$i]['strip_brackets_in_question'] = true;
            $data['modes'][$i]['strip_brackets_in_answer'] = true;
        }
        $config = (new ThemeConfigLoader())->fromArray($data, 'people.json');

        for ($seed = 1; $seed <= 10; $seed++) {
            $q = $this->generator($seed)->generate($config, $config->mode('name_by_book'), $this->items(), 4);
            $this->assertNotNull($q);
            $this->assertStringNotContainsString('(', $q->text);
            $q = $this->generator($seed)->generate($config, $config->mode('book_by_name'), $this->items(), 4);
            $this->assertNotNull($q);
            foreach ($q->options as $option) {
                $this->assertStringNotContainsString('(', $option);
            }
        }
    }

    public function testUnknownKeysAreReported(): void
    {
        $data = $this->personConfig();
        $data['modes'][0]['strip_bracket_in_question'] = true; // typo
        $data['fields']['name']['lable'] = 'x';
        try {
            (new ThemeConfigLoader())->fromArray($data, 'people.json');
            $this->fail('expected ConfigException');
        } catch (ConfigException $e) {
            $text = implode("\n", $e->errors);
            $this->assertStringContainsString('unknown key "strip_bracket_in_question"', $text);
            $this->assertStringContainsString('unknown key "lable"', $text);
        }
    }

    public function testConfigValidation(): void
    {
        $bad = $this->personConfig();
        $bad['answer_info'] = ['name', 'nope'];
        $bad['fields']['years']['strip_brackets_in_answer'] = true;
        $bad['fields']['name']['strip_brackets_in_question'] = 'yes';
        $bad['modes'][0]['answer_info'] = 'city';
        try {
            (new ThemeConfigLoader())->fromArray($bad, 'people.json');
            $this->fail('expected ConfigException');
        } catch (ConfigException $e) {
            $text = implode("\n", $e->errors);
            $this->assertStringContainsString('"nope" is not a declared field', $text);
            $this->assertStringContainsString('"strip_brackets_in_question" must be true or false', $text);
            $this->assertStringContainsString('must be a list of field keys', $text);
        }

        $ok = $this->personConfig();
        $ok['fields']['years']['strip_brackets_in_answer'] = true; // string field: allowed
        (new ThemeConfigLoader())->fromArray($ok, 'people.json');
        $ok['fields']['y'] = ['type' => 'int'];
        $ok['fields']['y']['strip_brackets_in_answer'] = true;
        $this->expectException(ConfigException::class);
        (new ThemeConfigLoader())->fromArray($ok, 'people.json');
    }

    public function testViewsAppendTheInfoAndKeepItInsideTheLimit(): void
    {
        $text = Views::answered('Вопрос', true, 'Ответ', false, "Город: Москва\nГоды жизни: 1799–1837");
        $this->assertStringContainsString("✅ Верно!\n\nГород: Москва\nГоды жизни: 1799–1837", $text);

        $wrong = Views::answered('Вопрос', false, 'Ответ', false, 'Город: Москва');
        $this->assertStringContainsString("❌ Неверно. Правильный ответ: Ответ\n\nГород: Москва", $wrong);

        // a long caption: the facts survive, the question is what gets shortened
        $long = Views::answered(str_repeat('Длинный вопрос. ', 70), false, str_repeat('О', 200), true, str_repeat('Факт. ', 40));
        $this->assertGreaterThan(mb_strlen($long) - 1, Views::CAPTION_LIMIT);
        $this->assertStringContainsString('Факт.', $long);

        $this->assertSame("Вопрос\n\n✅ Верно!", Views::answered('Вопрос', true, 'x', false));
    }
}
