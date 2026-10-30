<?php

declare(strict_types=1);

namespace App\Tests\Quiz;

use App\Quiz\ConfigException;
use App\Quiz\FieldType;
use App\Quiz\ModeDef;
use App\Quiz\ThemeConfigLoader;
use PHPUnit\Framework\TestCase;

final class ThemeConfigLoaderTest extends TestCase
{
    public function testValidConfigIsParsed(): void
    {
        $config = FilmFixtures::config();

        $this->assertSame('films', $config->slug);
        $this->assertCount(7, $config->modes);
        $this->assertSame(FieldType::List, $config->field('actors')->type);
        $this->assertSame(';', $config->field('actors')->separator);

        $near = $config->mode('year_near');
        $this->assertNotNull($near);
        $this->assertSame(ModeDef::STRATEGY_NEAR, $near->strategy);
        $this->assertSame(3, $near->spread);

        $same = $config->mode('title_same_genre');
        $this->assertNotNull($same);
        $this->assertSame('genre', $same->strategyField);
        $this->assertNull($config->mode('nope'));
    }

    /** @param callable(array): array $mutate */
    private function errorsFor(callable $mutate): array
    {
        $data = $mutate(FilmFixtures::configArray());
        try {
            (new ThemeConfigLoader())->fromArray($data, 'films.json');
        } catch (ConfigException $e) {
            return $e->errors;
        }

        return [];
    }

    private function assertHasError(array $errors, string $needle): void
    {
        foreach ($errors as $e) {
            if (str_contains($e, $needle)) {
                $this->assertTrue(true);

                return;
            }
        }
        $this->fail(sprintf('No error containing "%s" in: %s', $needle, implode(' | ', $errors)));
    }

    public function testAllProblemsAreReportedAtOnce(): void
    {
        $errors = $this->errorsFor(function (array $d): array {
            $d['slug'] = 'Bad Slug!';
            unset($d['title']);
            $d['fields']['extra'] = ['type' => 'date'];
            $d['modes'][0]['template'] = 'Без подстановки';
            $d['modes'][1]['by'] = 'unknown';
            return $d;
        });

        $this->assertGreaterThanOrEqual(5, count($errors));
        $this->assertHasError($errors, '"slug"');
        $this->assertHasError($errors, '"title" is required');
        $this->assertHasError($errors, 'unknown type "date"');
        $this->assertHasError($errors, 'must contain {value}');
        $this->assertHasError($errors, '"by" must be one of the declared fields');
    }

    public function testModeRules(): void
    {
        $this->assertHasError($this->errorsFor(function (array $d): array {
            $d['modes'][0]['what'] = 'year';
            $d['modes'][0]['by'] = 'year';
            return $d;
        }), '"by" and "what" must differ');

        $this->assertHasError($this->errorsFor(function (array $d): array {
            $d['modes'][0]['what'] = 'poster';
            return $d;
        }), 'cannot be an image field');

        $this->assertHasError($this->errorsFor(function (array $d): array {
            $d['modes'][0]['show'] = 'title';
            return $d;
        }), '"show" must point to an image field');

        $this->assertHasError($this->errorsFor(function (array $d): array {
            $d['modes'][1]['slug'] = 'title_by_year';
            return $d;
        }), 'duplicate slug');

        $this->assertHasError($this->errorsFor(function (array $d): array {
            $d['modes'][0]['distractors'] = ['strategy' => 'near'];
            return $d;
        }), 'needs an int "what" field');

        $this->assertHasError($this->errorsFor(function (array $d): array {
            $d['modes'][1]['distractors'] = ['strategy' => 'same_field', 'field' => 'missing'];
            return $d;
        }), 'distractors.field must be a declared field');

        $this->assertHasError($this->errorsFor(function (array $d): array {
            $d['modes'][0]['min_options'] = 8;
            $d['modes'][0]['max_options'] = 4;
            return $d;
        }), 'greater than "max_options"');

        $this->assertHasError($this->errorsFor(function (array $d): array {
            $d['modes'][0]['max_options'] = 99;
            return $d;
        }), '"max_options" must be an integer');
    }

    public function testImageByModeNeedsNoPlaceholder(): void
    {
        $config = FilmFixtures::config();
        $this->assertNotNull($config->mode('title_by_poster'));
    }

    public function testMissingFileAndBrokenJson(): void
    {
        $loader = new ThemeConfigLoader();
        try {
            $loader->loadFile('/definitely/not/here.json');
            $this->fail('expected exception');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('file not found', $e->getMessage());
        }

        $tmp = tempnam(sys_get_temp_dir(), 'cfg');
        file_put_contents($tmp, '{ not json');
        try {
            $loader->loadFile($tmp);
            $this->fail('expected exception');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('invalid JSON', $e->getMessage());
        } finally {
            unlink($tmp);
        }
    }
}
