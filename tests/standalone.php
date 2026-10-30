<?php

declare(strict_types=1);

/**
 * Runs the framework-free tests (tests/Quiz) without PHPUnit and without vendor/.
 * It provides a tiny stand-in for PHPUnit's TestCase, so the very same test files run under both.
 *
 *   php tests/standalone.php
 *
 * With PHPUnit installed (composer install) use `vendor/bin/phpunit` instead.
 */

namespace PHPUnit\Framework {
    class AssertionFailedError extends \Exception
    {
    }

    abstract class TestCase
    {
        public int $assertions = 0;
        private ?string $expectedException = null;

        protected function setUp(): void
        {
        }

        public function __runSetUp(): void
        {
            $this->setUp();
        }

        public function __expected(): ?string
        {
            return $this->expectedException;
        }

        protected function expectException(string $class): void
        {
            $this->expectedException = $class;
        }

        public function fail(string $message = ''): never
        {
            throw new AssertionFailedError($message);
        }

        private function check(bool $ok, string $message, string $default): void
        {
            $this->assertions++;
            if (!$ok) {
                throw new AssertionFailedError($message !== '' ? $message : $default);
            }
        }

        public static function export(mixed $v): string
        {
            return is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
        {
            $this->check($expected === $actual, $message, sprintf('Failed asserting that %s is identical to %s', self::export($actual), self::export($expected)));
        }

        public function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
        {
            $this->check($expected !== $actual, $message, 'Failed asserting that values are not identical: ' . self::export($actual));
        }

        public function assertTrue(mixed $v, string $message = ''): void
        {
            $this->check($v === true, $message, 'Failed asserting that value is true');
        }

        public function assertFalse(mixed $v, string $message = ''): void
        {
            $this->check($v === false, $message, 'Failed asserting that value is false');
        }

        public function assertNull(mixed $v, string $message = ''): void
        {
            $this->check($v === null, $message, 'Failed asserting that value is null');
        }

        public function assertNotNull(mixed $v, string $message = ''): void
        {
            $this->check($v !== null, $message, 'Failed asserting that value is not null');
        }

        public function assertCount(int $n, \Countable|iterable $c, string $message = ''): void
        {
            $actual = is_countable($c) ? count($c) : iterator_count($c);
            $this->check($actual === $n, $message, sprintf('Failed asserting that actual size %d matches expected size %d', $actual, $n));
        }

        public function assertGreaterThan(int|float $min, int|float $actual, string $message = ''): void
        {
            $this->check($actual > $min, $message, sprintf('Failed asserting that %s is greater than %s', $actual, $min));
        }

        public function assertGreaterThanOrEqual(int|float $min, int|float $actual, string $message = ''): void
        {
            $this->check($actual >= $min, $message, sprintf('Failed asserting that %s is greater than or equal to %s', $actual, $min));
        }

        public function assertContains(mixed $needle, iterable $haystack, string $message = ''): void
        {
            $found = false;
            foreach ($haystack as $v) {
                if ($v === $needle) {
                    $found = true;
                    break;
                }
            }
            $this->check($found, $message, sprintf('Failed asserting that %s is in %s', self::export($needle), self::export($haystack)));
        }

        public function assertNotContains(mixed $needle, iterable $haystack, string $message = ''): void
        {
            $found = false;
            foreach ($haystack as $v) {
                if ($v === $needle) {
                    $found = true;
                    break;
                }
            }
            $this->check(!$found, $message, sprintf('Failed asserting that %s is not in %s', self::export($needle), self::export($haystack)));
        }

        public function assertArrayHasKey(int|string $key, array $array, string $message = ''): void
        {
            $this->check(array_key_exists($key, $array), $message, sprintf('Failed asserting that array has key %s', self::export($key)));
        }

        public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
        {
            $this->check(str_contains($haystack, $needle), $message, sprintf('Failed asserting that "%s" contains "%s"', $haystack, $needle));
        }

        public function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
        {
            $this->check(!str_contains($haystack, $needle), $message, sprintf('Failed asserting that "%s" does not contain "%s"', $haystack, $needle));
        }
    }
}

namespace {
    use PHPUnit\Framework\AssertionFailedError;

    require __DIR__ . '/psr_log_stub.php';

    spl_autoload_register(static function (string $class): void {
        foreach (['App\\Tests\\' => __DIR__ . '/', 'App\\' => dirname(__DIR__) . '/src/'] as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        }
    });

    $files = glob(__DIR__ . '/*/*Test.php') ?: [];
    sort($files);

    $total = 0;
    $failed = 0;
    $assertions = 0;

    foreach ($files as $file) {
        $class = 'App\\Tests\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
        $methods = array_filter(get_class_methods($class), static fn (string $m): bool => str_starts_with($m, 'test'));
        foreach ($methods as $method) {
            $total++;
            $test = new $class();
            try {
                $test->__runSetUp();
                $test->$method();
                if ($test->__expected() !== null) {
                    throw new AssertionFailedError('Expected exception ' . $test->__expected() . ' was not thrown');
                }
                echo '.';
            } catch (\Throwable $e) {
                if ($test->__expected() !== null && $e instanceof ($test->__expected())) {
                    echo '.';
                } else {
                    $failed++;
                    echo 'F';
                    fwrite(STDERR, sprintf("\n%s::%s\n  %s: %s\n", $class, $method, $e::class, $e->getMessage()));
                    if (!$e instanceof AssertionFailedError) {
                        fwrite(STDERR, '  at ' . $e->getFile() . ':' . $e->getLine() . "\n");
                    }
                }
            }
            $assertions += $test->assertions;
        }
    }

    echo sprintf("\n\n%d tests, %d assertions, %d failed\n", $total, $assertions, $failed);
    exit($failed === 0 ? 0 : 1);
}
