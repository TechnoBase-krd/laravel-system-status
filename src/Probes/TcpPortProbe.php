<?php

namespace Technobase\SystemStatus\Probes;

/**
 * Opens (and closes) a TCP connection, for services without an HTTP check: SMTP, a database
 * or search engine on its own port. Slow connections are degraded (thresholds.slow_probe_ms).
 */
class TcpPortProbe implements Probe
{
    public function __construct(private readonly string $host, private readonly int $port) {}

    public function name(): string
    {
        return 'TCP '.$this->host.':'.$this->port;
    }

    public function run(): ProbeResult
    {
        $startedAt = microtime(true);
        $socket = @fsockopen($this->host, $this->port, $errorCode, $errorMessage, (int) config('system-status.thresholds.probe_timeout_seconds', 10));

        if (! $socket) {
            return ProbeResult::outage('Could not connect to '.$this->host.':'.$this->port);
        }

        fclose($socket);
        $ms = (int) round((microtime(true) - $startedAt) * 1000);

        return $ms >= (int) config('system-status.thresholds.slow_probe_ms', 3000)
            ? ProbeResult::degraded("Connecting to {$this->host}:{$this->port} took {$ms} ms")
            : ProbeResult::ok();
    }
}
