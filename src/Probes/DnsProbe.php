<?php

namespace Technobase\SystemStatus\Probes;

/**
 * Resolves a host name: major outage when there is no record of the given type.
 */
class DnsProbe implements Probe
{
    private const TYPES = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX, 'TXT' => DNS_TXT, 'NS' => DNS_NS];

    public function __construct(private readonly string $host, private readonly string $type = 'A') {}

    public function name(): string
    {
        return 'DNS '.strtoupper($this->type).' '.$this->host;
    }

    public function run(): ProbeResult
    {
        $type = self::TYPES[strtoupper($this->type)] ?? null;

        if ($type === null) {
            return ProbeResult::skipped('Unsupported record type '.$this->type);
        }

        $records = @dns_get_record($this->host, $type);

        if (! $records) {
            return ProbeResult::outage('No '.strtoupper($this->type).' record for '.$this->host);
        }

        return ProbeResult::ok(count($records).' '.strtoupper($this->type).' record(s)');
    }
}
