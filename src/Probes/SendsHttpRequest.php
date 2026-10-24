<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * run() for a ConcurrentProbe: the same precheck, request and result, sent on its own.
 */
trait SendsHttpRequest
{
    public function run(): ProbeResult
    {
        if ($result = $this->precheck()) {
            return $result;
        }

        try {
            $response = $this->send(Http::createPendingRequest());
        } catch (Throwable $e) {
            $response = $e;
        }

        return $this->result($response);
    }
}
