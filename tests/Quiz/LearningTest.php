<?php

declare(strict_types=1);

namespace App\Tests\Quiz;

use App\Quiz\AnswerMatcher;
use App\Quiz\ItemKey;
use App\Quiz\ItemProgress;
use App\Quiz\ItemRecord;
use App\Quiz\Leitner;
use App\Quiz\PhpRandom;
use App\Quiz\QuestionGenerator;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;

final class LearningTest extends TestCase
{
    public function testMatcherIgnoresCaseAndSpaces(): void
    {
        $this->assertTrue(AnswerMatcher::matches('  начало ', ['Начало']));
        $this->assertTrue(AnswerMatcher::matches('ПОБЕГ ИЗ шоушенка', ['Побег из Шоушенка']));
        $this->assertTrue(AnswerMatcher::matches("Побег\u{00A0}из\tШоушенка", ['Побег из Шоушенка']));
        $this->assertTrue(AnswerMatcher::matches('елки', ['Ёлки']));
        $this->assertTrue(AnswerMatcher::matches('зеленая миля', ['Зелёная миля']));
        $this->assertTrue(AnswerMatcher::matches('Начала', ['Начало']), 'one typo');
        $this->assertFalse(AnswerMatcher::matches('Конец', ['Начало']));
        $this->assertFalse(AnswerMatcher::matches('   ', ['Начало']));
        $this->assertTrue(AnswerMatcher::matches('1999', ['1999']));
    }

    public function testTyposAreForgivenExceptInNumbers(): void
    {
        $this->assertTrue(AnswerMatcher::matches('Гладиатр', ['Гладиатор']), 'a missing letter');
        $this->assertTrue(AnswerMatcher::matches('Гладиотор', ['Гладиатор']), 'a wrong letter');
        $this->assertTrue(AnswerMatcher::matches('Гладиаттор', ['Гладиатор']), 'an extra letter');
        $this->assertTrue(AnswerMatcher::matches('Гладиаотр', ['Гладиатор']), 'swapped letters');
        $this->assertFalse(AnswerMatcher::matches('Гладитр', ['Гладиатор']), 'two typos in a 8-letter answer');
        $this->assertTrue(AnswerMatcher::matches('Побег из Шошенка', ['Побег из Шоушенка']));
        $this->assertTrue(AnswerMatcher::matches('Криминальне чтво', ['Криминальное чтиво']), 'two typos in a long answer');
        $this->assertFalse(AnswerMatcher::matches('Оно', ['Она']), 'short answers are exact');
        $this->assertFalse(AnswerMatcher::matches('1998', ['1999']), 'years are exact');
        $this->assertFalse(AnswerMatcher::matches('Брат 3', ['Брат 2']), 'answers with digits are exact');
        $this->assertFalse(AnswerMatcher::matches('Матрица', ['Матрицы 2']));

        $m = AnswerMatcher::match('Гладиатр', ['Гладиатор', 'Гладиатор 2']);
        $this->assertSame(['value' => 'Гладиатор', 'exact' => false], $m);
        $this->assertTrue(AnswerMatcher::match('гладиатор', ['Гладиатор'])['exact']);
        $this->assertSame(1, AnswerMatcher::distance('аб', 'ба'));
    }

    public function testLeitnerBoxes(): void
    {
        $t0 = new \DateTimeImmutable('2026-01-01 10:00:00');

        $p = Leitner::advance(null, 'k', true, $t0);
        $this->assertSame(1, $p->box);
        $this->assertFalse($p->isDue($t0));
        $this->assertTrue($p->isDue($t0->modify('+1 day')));

        // right before it is due: counted, but the box stays (cramming is not learning)
        $same = Leitner::advance($p, 'k', true, $t0->modify('+2 hours'));
        $this->assertSame(1, $same->box);
        $this->assertSame(2, $same->correct);
        $this->assertSame($p->dueAt->format('c'), $same->dueAt->format('c'));

        // right when due: one box up
        $up = Leitner::advance($p, 'k', true, $t0->modify('+1 day'));
        $this->assertSame(2, $up->box);

        // a mistake: back to box 0 and due at once
        $down = Leitner::advance($up, 'k', false, $t0->modify('+2 days'));
        $this->assertSame(0, $down->box);
        $this->assertSame(1, $down->wrong);
        $this->assertTrue($down->isDue($t0->modify('+2 days')));

        // first answer wrong: box 0
        $this->assertSame(0, Leitner::advance(null, 'k', false, $t0)->box);

        // the top box is not exceeded and counts as learned
        $now = $t0;
        $q = null;
        for ($i = 0; $i < 10; $i++) {
            $now = $now->modify('+100 days');
            $q = Leitner::advance($q, 'k', true, $now);
        }
        $this->assertSame(Leitner::MAX_BOX, $q->box);
        $this->assertTrue($q->isLearned());
    }

    public function testPriorityOrder(): void
    {
        $now = new \DateTimeImmutable('2026-01-01');
        $wrong = new ItemProgress('a', 0, 0, 1, $now);
        $dueHigh = new ItemProgress('b', 3, 3, 0, $now->modify('-1 day'));
        $future = new ItemProgress('c', 2, 2, 0, $now->modify('+3 days'));

        $this->assertTrue(Leitner::priority($wrong, $now) < Leitner::priority($dueHigh, $now));
        $this->assertTrue(Leitner::priority($dueHigh, $now) < Leitner::priority(null, $now));
        $this->assertTrue(Leitner::priority(null, $now) < Leitner::priority($future, $now));
    }

    public function testGeneratorAsksTheBestPriorityGroupFirst(): void
    {
        $config = FilmFixtures::config();
        $items = FilmFixtures::items();
        $mode = $config->mode('title_by_year');
        $gen = new QuestionGenerator(new PhpRandom(new Xoshiro256StarStar(5)));

        $priorities = [];
        foreach ($items as $item) {
            $priorities[$item->id] = Leitner::PRIORITY_NOT_DUE;
        }
        $priorities[$items[3]->id] = 0;
        $priorities[$items[4]->id] = 0;

        for ($i = 0; $i < 30; $i++) {
            $q = $gen->generate($config, $mode, $items, 3, [], $priorities);
            $this->assertNotNull($q);
            $this->assertContains($q->itemId, [$items[3]->id, $items[4]->id]);
        }
    }

    public function testFreeTextQuestion(): void
    {
        $config = FilmFixtures::config();
        $items = FilmFixtures::items();
        $gen = new QuestionGenerator(new PhpRandom(new Xoshiro256StarStar(9)));

        $q = $gen->generate($config, $config->mode('title_by_year'), $items, 0);
        $this->assertNotNull($q);
        $this->assertTrue($q->isFreeText());
        $this->assertSame([], $q->options);
        $this->assertNotSame('', $q->correctAnswer());
        $this->assertContains($q->correctAnswer(), $q->accepted);
        // every film of that year is accepted
        $year = (int) $q->byValue;
        foreach ($items as $item) {
            if ($item->attrs['year'] === $year) {
                $this->assertContains($item->attrs['title'], $q->accepted);
            }
        }
        $this->assertSame(ItemKey::of($items[array_search($q->itemId, array_map(static fn (ItemRecord $i): int => $i->id, $items), true)]), $q->itemKey);
    }

    public function testItemKeyIsStableAndContentBased(): void
    {
        $a = new ItemRecord(1, ['title' => 'Начало', 'year' => 2010]);
        $b = new ItemRecord(99, ['year' => 2010, 'title' => 'Начало']);
        $c = new ItemRecord(1, ['title' => 'Начало', 'year' => 2011]);
        $this->assertSame(ItemKey::of($a), ItemKey::of($b));
        $this->assertNotSame(ItemKey::of($a), ItemKey::of($c));
        $this->assertSame(40, strlen(ItemKey::of($a)));
    }
}
