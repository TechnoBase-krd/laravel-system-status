<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Enums\IncidentState;
use Technobase\SystemStatus\Events\IncidentUpdated;
use Technobase\SystemStatus\Models\StatusComponent;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Services\IncidentService;
use Technobase\SystemStatus\Tests\Fixtures\User;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    withFakeProbe('database');
    probeReturns('database', ProbeResult::ok());
    $this->admin = User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']);
});

afterEach(fn () => Carbon::setTestNow());

function postIncident(string $component = 'api_orders', ComponentStatus $severity = ComponentStatus::Degraded): StatusIncident
{
    return app(IncidentService::class)->post($component, $severity, 'Payment provider is slow', 'We are on it', author: 'Admin');
}

it('posts an incident with a first timeline entry and an alert', function () {
    Event::fake([IncidentUpdated::class]);
    Log::spy();

    $incident = postIncident();

    expect($incident->isManual())->toBeTrue()
        ->and($incident->state)->toBe(IncidentState::Investigating)
        ->and($incident->updates()->count())->toBe(1);
    Event::assertDispatched(IncidentUpdated::class, fn ($e) => $e->action === IncidentUpdated::POSTED);
    Log::shouldHaveReceived('info')->withArgs(fn ($message) => str_contains($message, 'Payment provider is slow'));
});

it('holds the component at the incident severity until resolved', function () {
    $incident = postIncident();
    runChecks(2);

    $state = StatusComponent::query()->where('component', 'api_orders')->first();
    expect($state->status)->toBe(ComponentStatus::Degraded)
        ->and($state->message)->toBe('Payment provider is slow')
        // No second, automatic incident for the same problem.
        ->and(StatusIncident::query()->count())->toBe(1);

    app(IncidentService::class)->update($incident, IncidentState::Resolved, 'Provider fixed it');
    runChecks(2);

    expect(StatusComponent::query()->where('component', 'api_orders')->first()->status)->toBe(ComponentStatus::Operational)
        ->and($incident->fresh()->resolved_at)->not->toBeNull()
        ->and($incident->fresh()->updates()->count())->toBe(2);
});

it('does not resolve automatic incidents by hand', function () {
    probeReturns('database', ProbeResult::outage('down'));
    runChecks(2);
    $incident = StatusIncident::query()->sole();

    expect(fn () => app(IncidentService::class)->update($incident, IncidentState::Resolved, 'done'))
        ->toThrow(InvalidArgumentException::class);

    app(IncidentService::class)->update($incident, IncidentState::Identified, 'Disk full');
    expect($incident->fresh()->state)->toBe(IncidentState::Identified);
});

it('keeps manual incidents open when the automatic checks recover', function () {
    $incident = postIncident('database', ComponentStatus::MajorOutage);
    probeReturns('database', ProbeResult::ok());
    runChecks(4);

    expect($incident->fresh()->resolved_at)->toBeNull();
});

it('posts, updates and writes a postmortem through the page', function () {
    $response = $this->actingAs($this->admin)->postJson('/system-status/incidents', [
        'component' => 'api_orders',
        'severity' => 'major_outage',
        'state' => 'identified',
        'title' => 'Checkout fails',
        'message' => 'Card payments fail',
    ])->assertCreated()
        ->assertJsonPath('data.source', 'manual')
        ->assertJsonPath('data.created_by', 'Admin');

    $id = $response->json('data.id');

    $this->actingAs($this->admin)->postJson("/system-status/incidents/{$id}/updates", ['state' => 'resolved', 'message' => 'Fixed'])
        ->assertCreated()->assertJsonPath('data.ongoing', false)->assertJsonCount(2, 'data.updates');

    $this->actingAs($this->admin)->patchJson("/system-status/incidents/{$id}", ['postmortem' => 'Expired API key.'])
        ->assertOk()->assertJsonPath('data.postmortem', 'Expired API key.');

    $this->actingAs($this->admin)->getJson('/system-status/summary')
        ->assertJsonPath('data.incidents.0.postmortem', 'Expired API key.');
});

it('rejects updates to resolved incidents', function () {
    $incident = postIncident();
    app(IncidentService::class)->update($incident, IncidentState::Resolved, 'ok');

    $this->actingAs($this->admin)->postJson("/system-status/incidents/{$incident->id}/updates", ['state' => 'monitoring', 'message' => 'x'])
        ->assertUnprocessable();
});

it('validates the incident form', function () {
    $this->actingAs($this->admin)->postJson('/system-status/incidents', ['component' => 'nope', 'severity' => 'operational'])
        ->assertUnprocessable()->assertJsonValidationErrors(['component', 'severity', 'title']);
});
