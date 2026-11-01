<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Bot\BotHandler;
use App\Bot\QuestionFactory;
use App\Bot\QuestionPresenter;
use App\Entity\QuizRound;
use App\Entity\QuizTheme;
use App\Quiz\ItemRecord;
use App\Quiz\PhpRandom;
use App\Quiz\QuestionGenerator;
use App\Quiz\ThemeConfig;
use App\Quiz\ThemeConfigLoader;
use App\Service\ThemeCatalog;
use App\Telegram\Update;
use Psr\Log\NullLogger;

/**
 * A complete little game: the demo "films" theme config and pictures from tests/fixtures/themes, 14 hand-made films,
 * the real bot code, a fake chat. Tests "type" messages and "tap" buttons and read what came back.
 */
final class BotWorld
{
    public const USER = 1;

    public InMemoryQuizStore $store;
    public FakeGateway $gateway;
    public QuizTheme $theme;
    public ThemeConfig $config;
    public QuestionFactory $factory;
    public ThemeCatalog $catalog;
    private int $updateId = 1;

    /** @param list<ItemRecord>|null $records replace the default films */
    public function __construct(string $allowedUserIds = '', ?array $records = null)
    {
        $logger = new NullLogger();
        $this->catalog = new ThemeCatalog(dirname(__DIR__) . '/fixtures/themes', new ThemeConfigLoader(), $logger);
        $this->config = $this->catalog->configOrFail('films');

        $records ??= self::films();
        $this->store = new InMemoryQuizStore();
        $this->gateway = new FakeGateway();
        $this->theme = $this->store->addTheme('films', $this->config->title, $records);

        // the same numbers the importer would store
        $generator = new QuestionGenerator(new PhpRandom());
        foreach ($this->config->modes as $mode) {
            $stats = $generator->analyze($this->config, $mode, $records);
            $this->store->addModeStat($this->theme, $mode->slug, $stats->maxOptions, $stats->eligibleItems);
        }

        $this->factory = new QuestionFactory($this->store, $this->catalog, $generator, $logger);
        $presenter = new QuestionPresenter($this->gateway, $this->store, $this->catalog, $logger);
        $this->handler = new BotHandler($this->store, $this->gateway, $this->catalog, $this->factory, $presenter, $logger, $allowedUserIds);
    }

    private BotHandler $handler;

    /** @return list<ItemRecord> fourteen films; posters 01..14 exist in tests/fixtures/themes/films/images/posters */
    public static function films(): array
    {
        $rows = [
            ['Довод', 2020, ['Кристофер Нолан'], ['Джон Дэвид Вашингтон', 'Роберт Паттинсон'], ['фантастика']],
            ['Душа', 2020, ['Пит Доктер'], ['Джейми Фокс', 'Тина Фэй'], ['мультфильм']],
            ['Начало', 2010, ['Кристофер Нолан'], ['Леонардо ДиКаприо', 'Эллен Пейдж'], ['фантастика']],
            ['Интерстеллар', 2014, ['Кристофер Нолан'], ['Мэттью Макконахи', 'Энн Хэтэуэй'], ['фантастика']],
            ['Паразиты', 2019, ['Пон Джун-хо'], ['Сон Кан-хо'], ['драма']],
            ['Джокер', 2019, ['Тодд Филлипс'], ['Хоакин Феникс', 'Роберт Де Ниро'], ['драма']],
            ['Однажды в Голливуде', 2019, ['Квентин Тарантино'], ['Леонардо ДиКаприо', 'Брэд Питт'], ['комедия']],
            ['Побег из Шоушенка', 1994, ['Фрэнк Дарабонт'], ['Тим Роббинс', 'Морган Фриман'], ['драма']],
            ['Форрест Гамп', 1994, ['Роберт Земекис'], ['Том Хэнкс', 'Робин Райт'], ['драма']],
            ['Криминальное чтиво', 1994, ['Квентин Тарантино'], ['Джон Траволта', 'Ума Турман'], ['криминал']],
            ['Зелёная миля', 1999, ['Фрэнк Дарабонт'], ['Том Хэнкс'], ['драма']],
            ['Матрица', 1999, ['Лана Вачовски'], ['Киану Ривз'], ['фантастика']],
            ['Бойцовский клуб', 1999, ['Дэвид Финчер'], ['Брэд Питт', 'Эдвард Нортон'], ['драма']],
            ['Гладиатор', 2000, ['Ридли Скотт'], ['Рассел Кроу', 'Хоакин Феникс'], ['драма']],
        ];

        $records = [];
        foreach ($rows as $i => [$title, $year, $directors, $actors, $genres]) {
            $records[] = new ItemRecord($i + 1, [
                'title' => $title,
                'year' => $year,
                'director' => $directors,
                'actors' => $actors,
                'genre' => $genres,
                'poster' => sprintf('posters/%02d.png', $i + 1),
            ]);
        }

        return $records;
    }

    // ---- the player's side --------------------------------------------------------------------------------------

    public function say(string $text, int $user = self::USER): void
    {
        $this->handler->handle(new Update(++$this->updateId, $user, $user, 'u' . $user, 'User' . $user, $text, null, null, null, false));
    }

    /** Taps a button. By default the message is the newest one that carries this very button. */
    public function tap(string $data, ?int $messageId = null, int $user = self::USER): void
    {
        $messageId ??= $this->gateway->messageWithButton($data) ?? $this->gateway->last()['id'];
        $hasPhoto = $this->gateway->live[$messageId]['photo'] ?? false;
        $this->handler->handle(new Update(++$this->updateId, $user, $user, 'u' . $user, 'User' . $user, null, $data, 'cb' . $this->updateId, $messageId, $hasPhoto));
    }

    /** Taps the button with this label on the newest message that has one. */
    public function tapLabel(string $label, int $user = self::USER): void
    {
        foreach (array_reverse(array_keys($this->gateway->live)) as $id) {
            $data = $this->gateway->dataOfButton($id, $label);
            if ($data !== null) {
                $this->tap($data, $id, $user);

                return;
            }
        }
        throw new \LogicException(sprintf('No button "%s" on screen', $label));
    }

    /** /start -> theme -> mode -> number of options -> number of questions. Leaves the first question on screen. */
    public function startTour(string $modeSlug, int $options, int $questions, bool $smart = true, bool $bingoOnly = true): void
    {
        $this->say('/start');
        $this->tap('th:' . $this->theme->getId());
        $this->tap(sprintf('md:%d:%s', $this->round()->getId(), $modeSlug));
        $this->say((string) $options);
        $this->say((string) $questions);
        $this->tap(sprintf('sel:%d:%d', $this->round()->getId(), $smart ? 1 : 0));
        $this->tap(sprintf('bg:%d:%d', $this->round()->getId(), $bingoOnly ? 1 : 0));
    }

    /** Answers the question on screen: the right option, or a wrong one. */
    public function answer(bool $correct): void
    {
        $round = $this->round();
        $q = $round->getCurrentQuestion() ?? throw new \LogicException('no question is open');
        $index = $correct ? $q['correct'] : ($q['correct'] + 1) % count($q['options']);
        $this->tap(sprintf('an:%d:%d:%d', $round->getId(), $round->getCurrentNumber(), $index));
    }

    public function round(int $user = self::USER): QuizRound
    {
        $latest = null;
        foreach ($this->store->allRounds() as $round) {
            if ($round->getPlayer()->getTelegramUserId() === (string) $user) {
                $latest = $round;
            }
        }

        return $latest ?? throw new \LogicException('the player has no round yet');
    }

    /** @return array<string, mixed> */
    public function currentQuestion(): array
    {
        return $this->round()->getCurrentQuestion() ?? throw new \LogicException('no question is open');
    }
}
