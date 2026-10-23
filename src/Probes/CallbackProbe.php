<?php

namespace Technobase\SystemStatus\Probes;

use Closure;
use InvalidArgumentException;

/**
 * A quick check without a probe class. `callback` is an invokable class name, a
 * [Class::class, 'method'] pair (resolved from the container) or a closure. It returns a
 * ProbeResult, true (operational), false (major outage), null (skipped) or a string (degraded,
 * with that message). Closures cannot be cached by `php artisan config:cache`; prefer a class.
 */
class CallbackProbe implements Probe
{
    public function __construct(private readonly mixed $callback, private readonly string $label = 'Callback') {}

    public function name(): string
    {
        return $this->label;
    }

    public function run(): ProbeResult
    {
        $result = app()->call($this->callable());

        return match (true) {
            $result instanceof ProbeResult => $result,
            $result === true => ProbeResult::ok(),
            $result === false => ProbeResult::outage($this->label.' failed'),
            $result === null => ProbeResult::skipped($this->label.' is not configured'),
            is_string($result) => ProbeResult::degraded($result),
            default => ProbeResult::outage($this->label.' returned an unexpected value'),
        };
    }

    private function callable(): callable|array|string
    {
        $callback = $this->callback;

        if ($callback instanceof Closure) {
            return $callback;
        }

        if (is_string($callback) && class_exists($callback)) {
            return [app($callback), '__invoke'];
        }

        if (is_array($callback) && count($callback) === 2 && is_string($callback[0])) {
            return [app($callback[0]), $callback[1]];
        }

        if (is_callable($callback)) {
            return $callback;
        }

        throw new InvalidArgumentException('CallbackProbe needs an invokable class, [Class, method] or a closure.');
    }
}
