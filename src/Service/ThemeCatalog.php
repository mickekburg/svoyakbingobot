<?php

declare(strict_types=1);

namespace App\Service;

use App\Quiz\ConfigException;
use App\Quiz\ThemeConfig;
use App\Quiz\ThemeConfigLoader;
use Psr\Log\LoggerInterface;

/**
 * Finds theme configs on disk: data/themes/<slug>/<slug>.json.
 * Configs are cached per process and re-read when the file changes, so a long-running
 * polling worker picks up an edited config without a restart.
 */
final class ThemeCatalog
{
    /** @var array<string, array{mtime: int, config: ThemeConfig}> */
    private array $cache = [];

    public function __construct(
        private readonly string $themesDir,
        private readonly ThemeConfigLoader $loader,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<string> theme folders that contain <slug>/<slug>.json */
    public function slugs(): array
    {
        $slugs = [];
        foreach (glob(rtrim($this->themesDir, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);
            if (is_file($this->configPath($slug))) {
                $slugs[] = $slug;
            }
        }
        sort($slugs);

        return $slugs;
    }

    /** @return array<string, ThemeConfig> valid themes only (broken ones are logged and skipped) */
    public function all(): array
    {
        $result = [];
        foreach ($this->slugs() as $slug) {
            try {
                $result[$slug] = $this->configOrFail($slug);
            } catch (ConfigException $e) {
                $this->logger->error('Theme config is invalid, theme skipped', ['theme' => $slug, 'errors' => $e->errors]);
            }
        }

        return $result;
    }

    public function get(string $slug): ?ThemeConfig
    {
        try {
            return $this->configOrFail($slug);
        } catch (ConfigException $e) {
            $this->logger->error('Theme config is invalid', ['theme' => $slug, 'errors' => $e->errors]);

            return null;
        }
    }

    /** @throws ConfigException */
    public function configOrFail(string $slug): ThemeConfig
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $slug) !== 1) {
            throw new ConfigException([sprintf('"%s" is not a valid theme slug', $slug)]);
        }

        $path = $this->configPath($slug);
        $mtime = is_file($path) ? (int) filemtime($path) : 0;
        if (isset($this->cache[$slug]) && $this->cache[$slug]['mtime'] === $mtime) {
            return $this->cache[$slug]['config'];
        }

        $config = $this->loader->loadFile($path);
        if ($config->slug !== $slug) {
            throw new ConfigException([sprintf('%s: "slug" is "%s" but the folder is "%s" — they must match', basename($path), $config->slug, $slug)]);
        }

        $this->cache[$slug] = ['mtime' => $mtime, 'config' => $config];

        return $config;
    }

    public function themeDir(string $slug): string
    {
        return rtrim($this->themesDir, '/') . '/' . $slug;
    }

    public function configPath(string $slug): string
    {
        return $this->themeDir($slug) . '/' . $slug . '.json';
    }

    public function sourcePath(ThemeConfig $config): string
    {
        return $this->themeDir($config->slug) . '/' . $config->source;
    }

    public function imageAbsolutePath(ThemeConfig $config, string $relative): string
    {
        return $this->themeDir($config->slug) . '/' . trim($config->imageDir, '/') . '/' . $relative;
    }
}
