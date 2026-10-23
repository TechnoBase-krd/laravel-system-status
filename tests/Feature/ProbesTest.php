<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Probes\CallbackProbe;
use Technobase\SystemStatus\Probes\DiskSpaceProbe;
use Technobase\SystemStatus\Probes\DnsProbe;
use Technobase\SystemStatus\Probes\FailedJobsProbe;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Probes\QueueSizeProbe;
use Technobase\SystemStatus\Probes\QueueWaitProbe;
use Technobase\SystemStatus\Probes\SslCertificateProbe;
use Technobase\SystemStatus\Probes\TcpPortProbe;
use Technobase\SystemStatus\Tests\Fixtures\InvokableCheck;

it('reads free disk space', function () {
    expect((new DiskSpaceProbe(sys_get_temp_dir(), 0, 0))->run()->status)->toBe(ComponentStatus::Operational)
        ->and((new DiskSpaceProbe(sys_get_temp_dir(), 101, 0))->run()->status)->toBe(ComponentStatus::Degraded)
        ->and((new DiskSpaceProbe(sys_get_temp_dir(), 101, 101))->run()->status)->toBe(ComponentStatus::MajorOutage)
        ->and((new DiskSpaceProbe('/does/not/exist'))->run()->isSkipped())->toBeTrue();
});

it('counts recently failed jobs', function () {
    config()->set('queue.failed.database', 'testing');
    expect((new FailedJobsProbe)->run()->isSkipped())->toBeTrue();

    Schema::create('failed_jobs', function (Blueprint $table) {
        $table->id();
        $table->timestamp('failed_at');
    });
    DB::table('failed_jobs')->insert(array_fill(0, 6, ['failed_at' => now()]));
    DB::table('failed_jobs')->insert(['failed_at' => now()->subDay()]);

    $result = (new FailedJobsProbe(60, 5, 50))->run();
    expect($result->status)->toBe(ComponentStatus::Degraded)->and($result->message)->toContain('6 jobs');
});

it('checks queue size and wait', function () {
    config()->set('queue.default', 'sync');

    expect((new QueueSizeProbe)->run()->status)->toBe(ComponentStatus::Operational)
        ->and((new QueueWaitProbe)->run()->status)->toBeIn([ComponentStatus::Operational, null]);
});

it('opens a TCP connection', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    expect((new TcpPortProbe('127.0.0.1', $port))->run()->status)->toBe(ComponentStatus::Operational);
    fclose($server);

    expect((new TcpPortProbe('127.0.0.1', $port))->run()->status)->toBe(ComponentStatus::MajorOutage);
});

it('skips unsupported DNS record types and fails for unknown hosts', function () {
    expect((new DnsProbe('example.com', 'SRVX'))->run()->isSkipped())->toBeTrue()
        ->and((new DnsProbe('does-not-exist.invalid'))->run()->status)->toBe(ComponentStatus::MajorOutage);
});

it('reports an unreachable TLS host as an outage', function () {
    expect((new SslCertificateProbe('127.0.0.1', 1))->run()->status)->toBe(ComponentStatus::MajorOutage);
});

it('turns callback results into probe results', function () {
    InvokableCheck::$result = true;
    expect((new CallbackProbe(InvokableCheck::class))->run()->status)->toBe(ComponentStatus::Operational);

    InvokableCheck::$result = false;
    expect((new CallbackProbe(InvokableCheck::class))->run()->status)->toBe(ComponentStatus::MajorOutage);

    InvokableCheck::$result = null;
    expect((new CallbackProbe(InvokableCheck::class))->run()->isSkipped())->toBeTrue();

    expect((new CallbackProbe([InvokableCheck::class, 'check']))->run()->status)->toBe(ComponentStatus::Degraded)
        ->and((new CallbackProbe(fn () => ProbeResult::outage('x')))->run()->message)->toBe('x');
});

it('builds probes from config with named arguments', function () {
    config()->set('system-status.groups.platform.disk', [
        'probes' => [['class' => DiskSpaceProbe::class, 'with' => ['path' => sys_get_temp_dir(), 'degradedBelowPercent' => 0, 'outageBelowPercent' => 0]]],
    ]);

    runChecks();

    expect(DB::table('status_checks')->where('component', 'disk')->value('status'))->toBe('operational');
});
