<?php

declare(strict_types=1);

namespace App\Tests\Quiz;

use App\Quiz\ItemRecord;
use App\Quiz\ThemeConfig;
use App\Quiz\ThemeConfigLoader;

/** Small hand-made film theme with deliberate duplicates (same year, same title, shared actors). */
final class FilmFixtures
{
    /** @return array<string, mixed> */
    public static function configArray(): array
    {
        return [
            'slug' => 'films',
            'title' => 'Кино',
            'source' => 'films.xlsx',
            'image_dir' => 'images',
            'fields' => [
                'title' => ['type' => 'string', 'label' => 'Название'],
                'year' => ['type' => 'int', 'label' => 'Год'],
                'director' => ['type' => 'list', 'label' => 'Режиссёр'],
                'actors' => ['type' => 'list', 'label' => 'Актёры'],
                'genre' => ['type' => 'list', 'label' => 'Жанр'],
                'poster' => ['type' => 'image', 'label' => 'Постер'],
            ],
            'modes' => [
                ['slug' => 'title_by_year', 'title' => 'Фильм по году', 'by' => 'year', 'what' => 'title',
                    'template' => 'Какой из этих фильмов снят в {value}?'],
                ['slug' => 'year_by_title', 'title' => 'Год по фильму', 'by' => 'title', 'what' => 'year',
                    'template' => 'В каком году снят фильм «{value}»?'],
                ['slug' => 'year_near', 'title' => 'Год (близкие)', 'by' => 'title', 'what' => 'year',
                    'template' => 'Год фильма «{value}»?', 'distractors' => ['strategy' => 'near', 'spread' => 3]],
                ['slug' => 'title_by_actor', 'title' => 'Фильм по актёру', 'by' => 'actors', 'what' => 'title',
                    'template' => 'В каком из этих фильмов снимался {value}?'],
                ['slug' => 'title_same_genre', 'title' => 'Фильм по режиссёру (того же жанра)', 'by' => 'director', 'what' => 'title',
                    'template' => 'Какой фильм снял {value}?', 'distractors' => ['strategy' => 'same_field', 'field' => 'genre']],
                ['slug' => 'title_by_poster', 'title' => 'Фильм по постеру', 'by' => 'poster', 'what' => 'title',
                    'template' => 'Что это за фильм?'],
                ['slug' => 'year_with_poster', 'title' => 'Год по названию с постером', 'by' => 'title', 'what' => 'year', 'show' => 'poster',
                    'template' => 'В каком году вышел «{value}»?'],
            ],
        ];
    }

    public static function config(): ThemeConfig
    {
        return (new ThemeConfigLoader())->fromArray(self::configArray(), 'fixture');
    }

    /** @return list<ItemRecord> */
    public static function items(): array
    {
        $rows = [
            // title, year, directors, actors, genres, poster
            ['Довод', 2020, ['Кристофер Нолан'], ['Джон Дэвид Вашингтон', 'Роберт Паттинсон'], ['фантастика', 'боевик'], 'p/1.png'],
            ['Душа', 2020, ['Пит Доктер'], ['Джейми Фокс', 'Тина Фэй'], ['мультфильм', 'комедия'], 'p/2.png'],
            ['Земля кочевников', 2020, ['Хлоя Чжао'], ['Фрэнсис Макдорманд'], ['драма'], null],
            ['Начало', 2010, ['Кристофер Нолан'], ['Леонардо ДиКаприо', 'Эллен Пейдж'], ['фантастика', 'боевик'], 'p/4.png'],
            ['Интерстеллар', 2014, ['Кристофер Нолан'], ['Мэттью Макконахи', 'Энн Хэтэуэй'], ['фантастика', 'драма'], 'p/5.png'],
            ['Оппенгеймер', 2023, ['Кристофер Нолан'], ['Киллиан Мёрфи', 'Мэтт Дэймон'], ['драма'], 'p/6.png'],
            ['Паразиты', 2019, ['Пон Джун-хо'], ['Сон Кан-хо'], ['драма', 'триллер'], 'p/7.png'],
            ['Джокер', 2019, ['Тодд Филлипс'], ['Хоакин Феникс', 'Роберт Де Ниро'], ['драма', 'триллер'], 'p/8.png'],
            ['Однажды в Голливуде', 2019, ['Квентин Тарантино'], ['Леонардо ДиКаприо', 'Брэд Питт'], ['комедия', 'драма'], 'p/9.png'],
            ['Оно', 1990, ['Томми Ли Уоллес'], ['Тим Карри'], ['ужасы'], 'p/10.png'],
            ['Оно', 2017, ['Андрес Мускетти'], ['Билл Скарсгард'], ['ужасы'], 'p/11.png'],
            ['Побег из Шоушенка', 1994, ['Фрэнк Дарабонт'], ['Тим Роббинс', 'Морган Фриман'], ['драма'], 'p/12.png'],
            ['Форрест Гамп', 1994, ['Роберт Земекис'], ['Том Хэнкс', 'Робин Райт'], ['драма', 'комедия'], 'p/13.png'],
            ['Криминальное чтиво', 1994, ['Квентин Тарантино'], ['Джон Траволта', 'Ума Турман'], ['криминал', 'драма'], 'p/14.png'],
            ['Зелёная миля', 1999, ['Фрэнк Дарабонт'], ['Том Хэнкс'], ['драма', 'фэнтези'], 'p/15.png'],
            ['Матрица', 1999, ['Лана Вачовски', 'Лилли Вачовски'], ['Киану Ривз'], ['фантастика', 'боевик'], 'p/16.png'],
            ['Бойцовский клуб', 1999, ['Дэвид Финчер'], ['Брэд Питт', 'Эдвард Нортон'], ['драма', 'триллер'], 'p/17.png'],
            ['Семь', 1995, ['Дэвид Финчер'], ['Брэд Питт', 'Морган Фриман'], ['криминал', 'триллер'], 'p/18.png'],
            ['Гладиатор', 2000, ['Ридли Скотт'], ['Рассел Кроу', 'Хоакин Феникс'], ['драма', 'боевик'], 'p/19.png'],
            ['Чужой', 1979, ['Ридли Скотт'], ['Сигурни Уивер'], ['ужасы', 'фантастика'], 'p/20.png'],
            ['Марсианин', 2015, ['Ридли Скотт'], ['Мэтт Дэймон', 'Джессика Честейн'], ['фантастика', 'драма'], 'p/21.png'],
            ['Дюна', 2021, ['Дени Вильнёв'], ['Тимоти Шаламе', 'Оскар Айзек'], ['фантастика', 'приключения'], 'p/22.png'],
            ['Прибытие', 2016, ['Дени Вильнёв'], ['Эми Адамс', 'Джереми Реннер'], ['фантастика', 'драма'], 'p/23.png'],
            // incomplete rows: must never be a winner or an option source
            ['Без года', null, ['Неизвестный'], ['Некто'], ['драма'], 'p/24.png'],
            ['Без актёров', 2005, ['Неизвестный'], [], ['драма'], null],
        ];

        $items = [];
        foreach ($rows as $i => [$title, $year, $directors, $actors, $genres, $poster]) {
            $items[] = new ItemRecord($i + 1, [
                'title' => $title,
                'year' => $year,
                'director' => $directors,
                'actors' => $actors,
                'genre' => $genres,
                'poster' => $poster,
            ]);
        }

        return $items;
    }
}
