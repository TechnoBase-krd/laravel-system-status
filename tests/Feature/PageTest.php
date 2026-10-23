<?php

use Illuminate\Support\Facades\Gate;
use Technobase\SystemStatus\Tests\Fixtures\User;

function admin(): User
{
    return User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']);
}

it('refuses guests and users without the role', function () {
    $this->getJson('/system-status')->assertUnauthorized();

    $user = User::query()->create(['name' => 'Staff', 'role' => 'Staff']);
    $this->actingAs($user)->get('/system-status')->assertForbidden();
});

it('shows the standalone page to a super admin', function () {
    $this->actingAs(admin())->get('/system-status')->assertOk()->assertSee('sstHero', false);
});

it('uses a gate when configured', function () {
    config()->set('system-status.access.gate', 'viewSystemStatus');
    Gate::define('viewSystemStatus', fn ($user) => $user->name === 'Gatekeeper');

    $this->actingAs(admin())->get('/system-status')->assertForbidden();
    $this->actingAs(User::query()->create(['name' => 'Gatekeeper']))->get('/system-status')->assertOk();
});

it('returns the summary for every range', function (string $range) {
    runChecks();

    $this->actingAs(admin())->getJson('/system-status/summary?range='.$range)
        ->assertOk()
        ->assertJsonStructure(['data' => ['overall' => ['level', 'label'], 'groups' => [['key', 'components']], 'incidents']]);
})->with(['1h', '24h', '7d', '90d']);

it('returns component details and 404 for unknown components', function () {
    runChecks();

    $this->actingAs(admin())->getJson('/system-status/components/database')
        ->assertOk()->assertJsonPath('data.key', 'database');
    $this->actingAs(admin())->getJson('/system-status/components/nope')->assertNotFound();
});
