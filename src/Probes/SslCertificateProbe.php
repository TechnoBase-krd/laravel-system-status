<?php

namespace Technobase\SystemStatus\Probes;

/**
 * Days until a TLS certificate expires: degraded within `warnDays`, major outage once expired.
 * Only the expiry date is checked (the chain is not validated), so an expired certificate can
 * still be read. Host defaults to the host of APP_URL.
 */
class SslCertificateProbe implements Probe
{
    public function __construct(
        private readonly ?string $host = null,
        private readonly int $port = 443,
        private readonly int $warnDays = 14,
    ) {}

    public function name(): string
    {
        return 'TLS certificate '.$this->target();
    }

    public function run(): ProbeResult
    {
        $host = $this->host ?: parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! $host) {
            return ProbeResult::skipped('No host (set "host" or APP_URL)');
        }

        if (! function_exists('openssl_x509_parse')) {
            return ProbeResult::skipped('The openssl extension is not installed');
        }

        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        $client = @stream_socket_client(
            'ssl://'.$host.':'.$this->port,
            $errorCode,
            $errorMessage,
            (int) config('system-status.thresholds.probe_timeout_seconds', 10),
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (! $client) {
            return ProbeResult::outage('Could not open a TLS connection to '.$host.':'.$this->port);
        }

        $certificate = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
        fclose($client);
        $info = $certificate ? openssl_x509_parse($certificate) : false;

        if (! $info || empty($info['validTo_time_t'])) {
            return ProbeResult::outage('Could not read the certificate of '.$host);
        }

        $days = (int) floor(($info['validTo_time_t'] - time()) / 86400);
        $date = date('Y-m-d', (int) $info['validTo_time_t']);

        return match (true) {
            $info['validTo_time_t'] <= time() => ProbeResult::outage("The certificate of {$host} expired on {$date}"),
            $days < $this->warnDays => ProbeResult::degraded("The certificate of {$host} expires in {$days} days ({$date})"),
            default => ProbeResult::ok("Valid until {$date} ({$days} days)"),
        };
    }

    private function target(): string
    {
        return ($this->host ?: 'APP_URL').($this->port !== 443 ? ':'.$this->port : '');
    }
}
