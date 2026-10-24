<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * A probe that makes one HTTP request. status:check sends the requests of all such probes that
 * are due at the same time (Http::pool), so slow endpoints do not add up; run() still works on
 * its own. Use the SendsHttpRequest trait for run().
 */
interface ConcurrentProbe extends Probe
{
    /**
     * A result without a request (for example skipped because it is not set up), or null to
     * send the request.
     */
    public function precheck(): ?ProbeResult;

    /**
     * Sends the request on $request (asynchronous inside the pool) and returns what the
     * request method returns.
     */
    public function send(PendingRequest $request): mixed;

    /**
     * Turns the response, or the exception of a request that failed, into the result.
     */
    public function result(Response|Throwable $response): ProbeResult;
}
