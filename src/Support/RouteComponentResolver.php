<?php

namespace Technobase\SystemStatus\Support;

use Illuminate\Support\Str;

/**
 * Maps a route to its System Status component, using the rules in config/system-status.php.
 * Route names are tried first, then URI patterns; among matching patterns the longest one
 * wins, so "api/v1/auth/social*" beats "api/v1/auth*". Returns null for ignored routes.
 */
class RouteComponentResolver
{
    private static array $cache = [];

    public function resolve(?string $name, string $uri): ?string
    {
        $cacheKey = $name.'|'.$uri;

        if (! array_key_exists($cacheKey, self::$cache)) {
            self::$cache[$cacheKey] = $this->match($name, ltrim($uri, '/') ?: '/');
        }

        return self::$cache[$cacheKey];
    }

    private function match(?string $name, string $uri): ?string
    {
        if (Str::is(config('system-status.ignore_uris', []), $uri)) {
            return null;
        }

        $rules = $this->rules();

        if ($name !== null && ($component = $this->longestMatch($rules['names'], $name))) {
            return $component;
        }

        if ($component = $this->longestMatch($rules['uris'], $uri)) {
            return $component;
        }

        foreach (config('system-status.fallback', []) as $pattern => $component) {
            if (Str::is($pattern, $uri)) {
                return $component;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $rules  [pattern, component]
     */
    private function longestMatch(array $rules, string $subject): ?string
    {
        $best = null;
        $bestLength = -1;

        foreach ($rules as [$pattern, $component]) {
            if (strlen($pattern) > $bestLength && Str::is($pattern, $subject)) {
                $best = $component;
                $bestLength = strlen($pattern);
            }
        }

        return $best;
    }

    private function rules(): array
    {
        $rules = ['names' => [], 'uris' => []];

        foreach (config('system-status.groups', []) as $components) {
            foreach ($components as $component => $definition) {
                foreach ($definition['names'] ?? [] as $pattern) {
                    $rules['names'][] = [$pattern, $component];
                }
                foreach ($definition['uris'] ?? [] as $pattern) {
                    $rules['uris'][] = [$pattern, $component];
                }
            }
        }

        return $rules;
    }
}
