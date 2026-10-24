<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Technobase\SystemStatus\Events\DeployRecorded;
use Technobase\SystemStatus\Models\StatusDeploy;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Services\DeployService;
use Technobase\SystemStatus\Tests\Fixtures\User;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    withFakeProbe('database');
    probeReturns('database', ProbeResult::ok());
    Http::fake();
    config()->set('system-status.alerts.channels', ['slack']);
    config()->set('system-status.alerts.slack_webhook', 'https://hooks.slack.test/abc');
    config()->set('system-status.thresholds.heartbeat_stale_minutes', 1000);
});

afterEach(fn () => Carbon::setTestNow());

function deploySlackTexts(): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'hooks.slack.test'))
        ->map(fn ($pair) => $pair[0]->data()['text'])
        ->values()->all();
}

it('records a deploy from the command and fires DeployRecorded', function () {
    Event::fake([DeployRecorded::class]);

    $this->artisan('status:deploy', ['release' => '1.8.2', '--commit' => 'a1b2c3d4e5', '--note' => 'New checkout', '--by' => 'ali'])
        ->expectsOutputToContain('Deploy 1.8.2 (a1b2c3d) recorded')
        ->assertSuccessful();

    $deploy = StatusDeploy::query()->sole();
    expect($deploy->version)->toBe('1.8.2')
        ->and($deploy->note)->toBe('New checkout')
        ->and($deploy->deployed_by)->toBe('ali');
    Event::assertDispatched(DeployRecorded::class);

    $this->artisan('status:deploy', ['--list' => true])->expectsOutputToContain('New checkout')->assertSuccessful();
});

it('reads the version and commit from git when none is given', function () {
    Process::fake([
        'git rev-parse --short HEAD' => Process::result('a1b2c3d'),
        'git describe --tags --always' => Process::result('v1.9.0'),
    ]);

    $this->artisan('status:deploy')->assertSuccessful();

    expect(StatusDeploy::query()->sole()->label())->toBe('v1.9.0 (a1b2c3d)');
});

it('fails without a version when git is not available', function () {
    Process::fake(['*' => Process::result('', 'not a git repository', 128)]);

    $this->artisan('status:deploy')->assertFailed();
    expect(StatusDeploy::query()->count())->toBe(0);
});

it('accepts deploys from CI only with the token', function () {
    $this->postJson('/system-status/deploys', ['version' => '1.8.2'])->assertNotFound();

    config()->set('system-status.deploys.token', 'secret-token');

    $this->postJson('/system-status/deploys', ['version' => '1.8.2'])->assertUnauthorized();
    $this->withToken('wrong')->postJson('/system-status/deploys', ['version' => '1.8.2'])->assertUnauthorized();
    $this->withToken('secret-token')->postJson('/system-status/deploys', [])->assertUnprocessable();
    $this->withToken('secret-token')->postJson('/system-status/deploys', ['version' => '1.8.2', 'commit' => 'abc1234'])
        ->assertCreated()
        ->assertJsonPath('data.version', '1.8.2');

    expect(StatusDeploy::query()->count())->toBe(1);
});

it('names the deploy in the alert and on an incident that starts soon after it', function () {
    app(DeployService::class)->record('1.8.2');
    Carbon::setTestNow(now()->addMinutes(3));

    probeReturns('database', ProbeResult::outage('connection refused'));
    runChecks(2);

    expect(deploySlackTexts()[0])->toContain('Database: major outage')->toContain('min after deploy 1.8.2');

    $admin = User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']);
    $this->actingAs($admin)->getJson('/system-status/summary?range=24h')
        ->assertOk()
        ->assertJsonPath('data.incidents.0.deploy.label', '1.8.2')
        ->assertJsonPath('data.incidents.0.deploy.minutes', 4)
        ->assertJsonPath('data.deploys.0.label', '1.8.2')
        ->assertJsonPath('data.recent_deploys.0.label', '1.8.2');
});

it('does not name a deploy older than the incident window', function () {
    app(DeployService::class)->record('1.8.2');
    Carbon::setTestNow(now()->addMinutes(45));

    probeReturns('database', ProbeResult::outage('connection refused'));
    runChecks(2);

    expect(deploySlackTexts()[0])->not->toContain('after deploy');
});

it('alerts once when errors got worse after a deploy', function () {
    config()->set('system-status.deploys.compare.enabled', true);
    withFakeProbe('api_orders', 'api', ['uris' => ['api/orders*']]);
    probeReturns('api_orders', ProbeResult::ok());

    $deployedAt = now();
    addTraffic('api_orders', 200, 1, at: $deployedAt->copy()->subMinutes(10));
    addTraffic('api_orders', 200, 20, at: $deployedAt->copy()->addMinutes(5));
    app(DeployService::class)->record('1.8.2');

    Carbon::setTestNow($deployedAt->copy()->addMinutes(16));
    runChecks();
    runChecks();

    $alerts = array_values(array_filter(deploySlackTexts(), fn ($t) => str_contains($t, 'Worse in the 15 min after deploy 1.8.2')));
    expect($alerts)->toHaveCount(1)
        ->and($alerts[0])->toContain('errors 0.5% → 10%');

    $deploy = StatusDeploy::query()->sole();
    expect($deploy->compared_at)->not->toBeNull()
        ->and($deploy->comparison[0]['component'])->toBe('api_orders');
});

it('does not compare deploys while the option is off', function () {
    app(DeployService::class)->record('1.8.2');
    Carbon::setTestNow(now()->addMinutes(20));
    runChecks();

    expect(StatusDeploy::query()->sole()->compared_at)->toBeNull();
});
