<?php

use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Tests\Fixtures\User;

const API_TOKEN = 'test-api-token-0123456789abcdef0123456789';

beforeEach(function () {
    config()->set('system-status.api.token', API_TOKEN);
    withFakeProbe('database');
    probeReturns('database', ProbeResult::ok());
});

function apiHeaders(string $token = API_TOKEN): array
{
    return ['Authorization' => 'Bearer '.$token];
}

it('is off (404) while no token is set', function () {
    config()->set('system-status.api.token', null);

    $this->getJson('/system-status/api/summary', apiHeaders())->assertNotFound();
});

it('refuses a missing or wrong token', function () {
    $this->getJson('/system-status/api/summary')->assertUnauthorized();
    $this->getJson('/system-status/api/summary', apiHeaders('wrong-token'))->assertUnauthorized();
});

it('returns the summary without a session, with meta', function () {
    runChecks(2);

    $this->getJson('/system-status/api/summary?range=1h', apiHeaders())
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['overall' => ['level', 'label'], 'groups' => [['key', 'label', 'components' => [['key', 'label', 'status']]]], 'incidents'],
            'meta' => ['app', 'package_version', 'generated_at'],
        ])
        ->assertJsonPath('data.groups.0.components.0.key', 'database')
        ->assertJsonPath('data.groups.0.components.0.status', ComponentStatus::Operational->value)
        ->assertJsonPath('meta.app', config('app.name'));
});

it('accepts the token in the X-System-Status-Token header', function () {
    $this->getJson('/system-status/api/summary', ['X-System-Status-Token' => API_TOKEN])->assertOk();
});

it('rejects an unknown range', function () {
    $this->getJson('/system-status/api/summary?range=forever', apiHeaders())->assertUnprocessable();
});

it('returns one component and 404 for unknown ones', function () {
    runChecks();

    $this->getJson('/system-status/api/components/database', apiHeaders())
        ->assertOk()->assertJsonPath('data.key', 'database')->assertJsonStructure(['meta' => ['app']]);
    $this->getJson('/system-status/api/components/nope', apiHeaders())->assertNotFound();
});

it('pages the incidents', function () {
    StatusIncident::query()->create([
        'component' => 'database', 'severity' => ComponentStatus::MajorOutage, 'title' => 'Database down',
        'started_at' => now()->subHour(), 'source' => 'auto',
    ]);

    $this->getJson('/system-status/api/incidents?per_page=10', apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Database down')
        ->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total', 'has_more', 'app', 'package_version']]);
});

it('does not open the page or the management routes to the API token', function () {
    $this->getJson('/system-status', apiHeaders())->assertUnauthorized();
    $this->getJson('/system-status/summary', apiHeaders())->assertUnauthorized();
    $this->postJson('/system-status/incidents', ['title' => 'x'], apiHeaders())->assertUnauthorized();
});

it('does not accept the API token for deploys', function () {
    config()->set('system-status.deploys.token', 'deploy-token-abcdefabcdefabcdefabcdef12');

    $this->postJson('/system-status/deploys', ['version' => '1.0.0'], apiHeaders())->assertUnauthorized();
});

it('answers 404 when the package is turned off', function () {
    config()->set('system-status.enabled', false);

    $this->getJson('/system-status/api/summary', apiHeaders())->assertNotFound();
});

it('reports the API token in status:doctor', function () {
    config()->set('system-status.api.token', 'short');
    $this->artisan('status:doctor', ['--no-http' => true])->expectsOutputToContain('shorter than 32 characters');

    config()->set('system-status.api.token', API_TOKEN);
    config()->set('system-status.deploys.token', API_TOKEN);
    $this->artisan('status:doctor', ['--no-http' => true])->expectsOutputToContain('same as the deploy token');
});

it('keeps session users on the page endpoints', function () {
    $admin = User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']);

    $this->actingAs($admin)->getJson('/system-status/summary')->assertOk();
});
