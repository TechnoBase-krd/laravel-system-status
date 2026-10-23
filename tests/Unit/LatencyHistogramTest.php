<?php

use Technobase\SystemStatus\Support\LatencyHistogram;

it('puts response times in the right bucket', function (int $ms, int $index) {
    expect(LatencyHistogram::index($ms))->toBe($index);
})->with([[0, 0], [50, 0], [51, 1], [999, 4], [5000, 6], [12000, 7]]);

it('interpolates percentiles inside a bucket', function () {
    // 100 requests, all between 100 and 250 ms.
    $counts = [0, 0, 100, 0, 0, 0, 0, 0];

    expect(LatencyHistogram::percentile($counts, 50))->toBe(175)
        ->and(LatencyHistogram::percentile($counts, 100))->toBe(250);
});

it('finds p95 across buckets', function () {
    // 90 fast requests, 10 slow ones (2.5 s – 5 s).
    $counts = ['le_50' => 90, 'le_5000' => 10];

    expect(LatencyHistogram::percentile($counts, 50))->toBeLessThanOrEqual(50)
        ->and(LatencyHistogram::percentile($counts, 95))->toBe(3750);
});

it('returns null without requests', function () {
    expect(LatencyHistogram::percentile([], 95))->toBeNull();
});
