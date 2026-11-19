<?php

use App\Jobs\DeliverWebhook;
use App\Models\User;
use App\Support\SafeHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('round-trips encrypted conditions, captures non-matches and tracks current destination health', function () {
    Queue::fake();
    $owner = User::create(['name' => 'Synthetic owner', 'email' => 'forward-owner@example.invalid', 'password' => bcrypt('synthetic-test-password')]);
    $this->actingAs($owner);
    $project = $this->postJson('/api/projects', ['name' => 'Filters'])->assertCreated()->json('id');
    $endpoint = $this->postJson('/api/endpoints', ['project_id' => $project, 'name' => 'Events'])->assertCreated()->json('id');
    $token = $this->getJson('/api/projects')->json('endpoints.0.token');
    $path = "/api/endpoints/{$endpoint}/forward";
    $url = 'https://93.184.216.34/first';
    $settings = ['url' => $url, 'enabled' => true];
    $filter = ['method' => 'POST', 'header' => 'X-Event', 'header_value' => 'orders', 'json_path' => 'data.type', 'json_value' => 'paid'];

    $this->putJson($path, ['enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('url');
    $this->putJson($path, $settings + ['filter' => ['json_path' => 'data.type']])->assertUnprocessable()->assertJsonValidationErrors('filter.json_value');
    $this->putJson($path, $settings + ['filter' => ['header' => 'Authorization', 'header_value' => 'value']])->assertUnprocessable()->assertJsonValidationErrors('filter.header');
    $this->putJson($path, $settings + ['filter' => $filter])->assertNoContent();
    expect(DB::table('forward_targets')->value('filter'))->not->toContain('data.type');
    $this->getJson($path)->assertOk()->assertJsonPath('target.health', 'unverified')->assertJsonPath('target.filter', $filter);

    $this->getJson("/c/{$token}")->assertAccepted();
    $this->postJson("/c/{$token}", ['data' => ['type' => 'paid']])->assertAccepted();
    $this->postJson("/c/{$token}", ['data' => ['type' => 'pending']], ['X-Event' => 'orders'])->assertAccepted();
    Queue::assertNothingPushed();
    $this->postJson("/c/{$token}", ['data' => ['type' => 'paid']], ['X-Event' => 'orders'])->assertAccepted();
    Queue::assertPushed(DeliverWebhook::class, 1);
    expect(DB::table('captures')->count())->toBe(4);
    $delivery = DB::table('deliveries')->first();

    try {
        SafeHttp::fakeTransport(fn () => ['status' => 200, 'body' => '']);
        (new DeliverWebhook($delivery->id))->handle();
    } finally {
        SafeHttp::fakeTransport(null);
    }
    $this->getJson($path)->assertJsonPath('target.health', 'healthy');
    DB::table('forward_targets')->update(['consecutive_failures' => 1]);
    $this->getJson($path)->assertJsonPath('target.health', 'degraded');
    DB::table('forward_targets')->update(['disabled_until' => now()->addMinutes(15)]);
    $this->getJson($path)->assertJsonPath('target.health', 'paused');
    $this->putJson($path, ['enabled' => false])->assertNoContent();
    $this->getJson($path)->assertJsonPath('target.health', 'off')->assertJsonPath('target.filter', $filter);
    $this->putJson($path, ['url' => 'https://93.184.216.34/second', 'enabled' => true, 'filter' => ['method' => null, 'header' => '', 'header_value' => '']])->assertNoContent();
    $this->getJson($path)->assertJsonPath('target.health', 'unverified')->assertJsonPath('target.filter', null);
    $other = User::create(['name' => 'Synthetic outsider', 'email' => 'forward-outsider@example.invalid', 'password' => bcrypt('synthetic-test-password')]);
    $this->actingAs($other)->getJson($path)->assertNotFound();
    $this->putJson($path, $settings)->assertNotFound();
});
