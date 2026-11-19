<?php

use App\Jobs\DeliverWebhook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('serves discoverable public sitemap and excludes private routes', function () {
    $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /api/', false)->assertSee('Sitemap:', false);
    $this->get('/sitemap.xml')->assertOk()->assertSee('/self-hosting', false)->assertDontSee('/dashboard', false);
});

it('retries only owned failed retained deliveries with confirmation', function () {
    Queue::fake();
    $owner = User::create(['name' => 'Synthetic tester', 'email' => 'retry-'.uniqid().'@example.invalid', 'password' => bcrypt('synthetic-password')]);
    $this->actingAs($owner);
    $project = $this->postJson('/api/projects', ['name' => 'Retry test'])->assertCreated()->json('id');
    $endpoint = $this->postJson('/api/endpoints', ['project_id' => $project, 'name' => 'Receiver'])->assertCreated()->json('id');
    $token = DB::table('endpoints')->where('id', $endpoint)->value('token');
    $this->postJson('/c/'.$token, ['test' => true])->assertStatus(202);
    $capture = DB::table('captures')->value('id');
    $delivery = DB::table('deliveries')->insertGetId(['endpoint_id' => $endpoint, 'capture_id' => $capture, 'kind' => 'replay', 'target_url' => Crypt::encryptString('http://127.0.0.1/hook'), 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
    $url = "/api/endpoints/$endpoint/deliveries/$delivery/retry";
    $this->postJson($url)->assertUnprocessable();
    $this->postJson($url, ['confirm' => true])->assertUnprocessable(); // SSRF remains blocked
    DB::table('deliveries')->where('id', $delivery)->update(['target_url' => Crypt::encryptString('https://8.8.8.8/hook')]);
    $retry = $this->postJson($url, ['confirm' => true])->assertStatus(202)->json('id');
    expect($retry)->not->toBe($delivery);
    Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->deliveryId === $retry);
    Queue::fake();
    DB::table('deliveries')->where('id', $delivery)->update(['status' => 'queued']);
    $this->postJson($url, ['confirm' => true])->assertStatus(409);
    DB::table('deliveries')->where('id', $delivery)->update(['status' => 'failed']);
    DB::table('captures')->where('id', $capture)->update(['expires_at' => now()->subMinute()]);
    $this->postJson($url, ['confirm' => true])->assertStatus(410);
    $this->actingAs(User::create(['name' => 'Synthetic tester', 'email' => 'retry-'.uniqid().'@example.invalid', 'password' => bcrypt('synthetic-password')]))->postJson($url, ['confirm' => true])->assertNotFound();
    Queue::assertNothingPushed();
});
