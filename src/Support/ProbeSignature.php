<?php

namespace Technobase\SystemStatus\Support;

use Illuminate\Http\Request;
use Throwable;

/**
 * Signs the package's own requests to risky paths (.env, .git/config, …) with the APP_KEY, so the
 * suspicious activity monitor does not report the server as a scanner. A plain header would not
 * do: anyone can send one. A signature is valid for its path for 5 to 10 minutes.
 */
class ProbeSignature
{
    public const HEADER = 'X-Status-Probe-Signature';

    private const WINDOW = 300;

    /**
     * @return array<string, string>
     */
    public static function headers(string $url): array
    {
        $signature = self::sign((string) parse_url($url, PHP_URL_PATH), intdiv(time(), self::WINDOW));

        return $signature === null ? [] : [self::HEADER => $signature];
    }

    public static function valid(Request $request): bool
    {
        $given = (string) $request->headers->get(self::HEADER, '');

        if ($given === '') {
            return false;
        }

        $slot = intdiv(time(), self::WINDOW);

        foreach ([$slot, $slot - 1] as $window) {
            $expected = self::sign($request->getPathInfo(), $window);

            if ($expected !== null && hash_equals($expected, $given)) {
                return true;
            }
        }

        return false;
    }

    private static function sign(string $path, int $window): ?string
    {
        try {
            $key = (string) config('app.key');
        } catch (Throwable) {
            return null;
        }

        if ($key === '') {
            return null;
        }

        return hash_hmac('sha256', 'system-status-probe|'.rawurldecode('/'.ltrim($path, '/')).'|'.$window, $key);
    }
}
