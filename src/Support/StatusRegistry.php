<?php

namespace Technobase\SystemStatus\Support;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Reads the component registry in config/system-status.php.
 */
class StatusRegistry
{
    /**
     * @return array<string, array<string, array>> group => component => definition
     */
    public function groups(): array
    {
        return config('system-status.groups', []);
    }

    /**
     * @return array<string, array{group: string, definition: array}>
     */
    public function components(): array
    {
        $components = [];

        foreach ($this->groups() as $group => $definitions) {
            foreach ($definitions as $component => $definition) {
                $components[$component] = ['group' => $group, 'definition' => $definition];
            }
        }

        return $components;
    }

    public function exists(string $component): bool
    {
        return array_key_exists($component, $this->components());
    }

    public function label(string $component, ?string $locale = null): string
    {
        return $this->translated('status_component_'.$component, $component, $locale);
    }

    public function groupLabel(string $group, ?string $locale = null): string
    {
        return $this->translated('status_group_'.$group, $group, $locale);
    }

    /**
     * The translation, or the key as words ("api_orders" → "Api Orders") when the application
     * has not added one in lang/vendor/system-status.
     */
    private function translated(string $key, string $fallback, ?string $locale): string
    {
        $line = 'system-status::status.'.$key;

        return Lang::has($line, $locale) ? __($line, [], $locale) : Str::headline($fallback);
    }
}
