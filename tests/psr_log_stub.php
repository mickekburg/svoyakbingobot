<?php

declare(strict_types=1);

// Minimal stand-in for psr/log, used only when vendor/ is absent (standalone test run).
// With composer's autoloader the real interface is already loaded and this file does nothing.

namespace Psr\Log;

if (!interface_exists(LoggerInterface::class, false)) {
    interface LoggerInterface
    {
        public function emergency(string|\Stringable $message, array $context = []): void;

        public function alert(string|\Stringable $message, array $context = []): void;

        public function critical(string|\Stringable $message, array $context = []): void;

        public function error(string|\Stringable $message, array $context = []): void;

        public function warning(string|\Stringable $message, array $context = []): void;

        public function notice(string|\Stringable $message, array $context = []): void;

        public function info(string|\Stringable $message, array $context = []): void;

        public function debug(string|\Stringable $message, array $context = []): void;

        public function log($level, string|\Stringable $message, array $context = []): void;
    }

    class NullLogger implements LoggerInterface
    {
        public function emergency(string|\Stringable $message, array $context = []): void
        {
        }

        public function alert(string|\Stringable $message, array $context = []): void
        {
        }

        public function critical(string|\Stringable $message, array $context = []): void
        {
        }

        public function error(string|\Stringable $message, array $context = []): void
        {
        }

        public function warning(string|\Stringable $message, array $context = []): void
        {
        }

        public function notice(string|\Stringable $message, array $context = []): void
        {
        }

        public function info(string|\Stringable $message, array $context = []): void
        {
        }

        public function debug(string|\Stringable $message, array $context = []): void
        {
        }

        public function log($level, string|\Stringable $message, array $context = []): void
        {
        }
    }
}
