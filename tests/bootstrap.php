<?php

declare(strict_types=1);

// The domain code under src/Quiz has no framework dependencies, so tests only need the PSR-4 autoloader.
// Works with composer's autoloader when present, and with a tiny fallback otherwise.
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
    return;
}

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
