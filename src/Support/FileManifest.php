<?php

namespace Technobase\SystemStatus\Support;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Hashes of the application's code files (security.files), saved at a deploy and compared by
 * SecurityFilesProbe: a PHP file added or changed outside a deploy is a web shell or a
 * tampered file until proven otherwise.
 */
class FileManifest
{
    /** Stops a misconfigured path (e.g. the whole disk) from taking forever. */
    public const MAX_FILES = 50000;

    /**
     * relative path => sha1 of every code file under security.files.paths.
     *
     * @return array<string, string>
     */
    public static function build(): array
    {
        $settings = (array) config('system-status.security.files', []);
        $extensions = array_map('strtolower', (array) ($settings['extensions'] ?? ['php']));
        $root = rtrim(base_path(), DIRECTORY_SEPARATOR);
        $hashes = [];

        foreach ((array) ($settings['paths'] ?? []) as $path) {
            $absolute = self::absolute((string) $path);

            foreach (self::files($absolute) as $file) {
                if (count($hashes) >= self::MAX_FILES) {
                    break 2;
                }

                if (self::matches($file->getFilename(), $extensions)) {
                    $relative = ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                    $hashes[str_replace('\\', '/', $relative)] = (string) sha1_file($file->getPathname());
                }
            }
        }

        ksort($hashes);

        return $hashes;
    }

    /**
     * Saves the current hashes as the baseline. Returns the number of files.
     */
    public static function saveBaseline(): int
    {
        $hashes = self::build();
        $file = self::baselinePath();

        File::ensureDirectoryExists(dirname($file));
        File::put($file, json_encode(['saved_at' => now()->toIso8601String(), 'files' => $hashes], JSON_UNESCAPED_SLASHES));

        return count($hashes);
    }

    /**
     * @return array{saved_at: string, files: array<string, string>}|null
     */
    public static function baseline(): ?array
    {
        try {
            $data = json_decode((string) @file_get_contents(self::baselinePath()), true);

            return is_array($data) && is_array($data['files'] ?? null) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Differences between the baseline and now.
     *
     * @return array{added: array<int, string>, changed: array<int, string>, removed: array<int, string>}|null
     */
    public static function compare(): ?array
    {
        $baseline = self::baseline();

        if ($baseline === null) {
            return null;
        }

        $before = $baseline['files'];
        $now = self::build();

        return [
            'added' => array_values(array_keys(array_diff_key($now, $before))),
            'changed' => array_values(array_keys(array_filter($now, fn ($hash, $path) => isset($before[$path]) && $before[$path] !== $hash, ARRAY_FILTER_USE_BOTH))),
            'removed' => array_values(array_keys(array_diff_key($before, $now))),
        ];
    }

    /**
     * A path relative to base_path(), or an absolute one as it is.
     */
    public static function absolute(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
            ? rtrim($path, '/\\')
            : rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($path, '/\\');
    }

    public static function baselinePath(): string
    {
        return (string) (config('system-status.security.files.baseline') ?: storage_path('app/system-status/files.json'));
    }

    /**
     * Regular files under $path, without following symlinks (public/storage points at uploads).
     *
     * @return iterable<SplFileInfo>
     */
    public static function files(string $path): iterable
    {
        if (is_file($path)) {
            return [new SplFileInfo($path)];
        }

        if (! is_dir($path) || is_link($path)) {
            return [];
        }

        try {
            return new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int, string>  $extensions  "php", "htaccess", "user.ini"
     */
    public static function matches(string $filename, array $extensions): bool
    {
        $filename = strtolower($filename);

        foreach ($extensions as $extension) {
            if ($filename === '.'.$extension || str_ends_with($filename, '.'.$extension)) {
                return true;
            }
        }

        return false;
    }
}
