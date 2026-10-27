<?php

namespace Technobase\SystemStatus\Probes;

use Technobase\SystemStatus\Support\FileManifest;

/**
 * Executable files where only uploads and assets belong: a PHP file (any of `extensions`) in
 * `paths`, or an upload whose first bytes are PHP code (an image with a shell inside). Major
 * outage, naming the files. `allow` lists files that belong there (public/index.php).
 */
class WebShellProbe implements Probe
{
    /**
     * @param  array<int, string>|null  $paths  relative to base_path() (or absolute); null = public and storage/app/public
     * @param  array<int, string>  $allow  relative paths that are expected
     * @param  array<int, string>  $extensions
     */
    public function __construct(
        private readonly ?array $paths = null,
        private readonly array $allow = ['public/index.php'],
        private readonly array $extensions = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'pht', 'phps', 'shtml', 'cgi', 'pl', 'jsp', 'asp', 'aspx'],
        private readonly int $maxFiles = 20000,
    ) {}

    public function name(): string
    {
        return 'Web shells in public and upload folders';
    }

    public function run(): ProbeResult
    {
        $root = rtrim(base_path(), DIRECTORY_SEPARATOR);
        $found = [];
        $seen = 0;

        foreach ($this->paths ?? ['public', 'storage/app/public'] as $path) {
            foreach (FileManifest::files(FileManifest::absolute($path)) as $file) {
                if (++$seen > $this->maxFiles) {
                    break 2;
                }

                $relative = str_replace('\\', '/', ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR));

                if (in_array($relative, $this->allow, true)) {
                    continue;
                }

                if (FileManifest::matches($file->getFilename(), $this->extensions) || $this->hidesCode($file->getPathname())) {
                    $found[] = $relative;
                }
            }
        }

        if ($found === []) {
            return ProbeResult::ok("No executable files ({$seen} checked)");
        }

        return ProbeResult::outage(count($found).' executable file(s) where none belong: '
            .implode(', ', array_slice($found, 0, 5)).(count($found) > 5 ? ' (+'.(count($found) - 5).')' : ''));
    }

    /**
     * PHP code in the first bytes of a file that is not PHP (a polyglot image).
     */
    private function hidesCode(string $file): bool
    {
        $head = @file_get_contents($file, false, null, 0, 1024);

        return is_string($head) && (str_contains($head, '<?php') || str_contains($head, '<?='));
    }
}
