<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Queue, RateLimiter};
use App\Models\User;

class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    private function setupProject(): array
    {
        Queue::fake();
        $user = User::create(['name' => 'Synthetic', 'email' => bin2hex(random_bytes(8)).'@example.invalid', 'password' => bcrypt('long-test-password')]);
        $this->actingAs($user);
        $project = $this->postJson('/api/projects', ['name' => 'API tests'])->json('id');
        $endpoint = $this->postJson('/api/endpoints', ['project_id' => $project, 'name' => 'CI'])->json('id');
        $receiver = DB::table('endpoints')->where('id', $endpoint)->value('token');
        $this->postJson('/c/'.$receiver, ['event' => 'created'])->assertStatus(202);
        $capture = DB::table('captures')->where('endpoint_id', $endpoint)->value('id');
        return [$user, $project, $endpoint, $capture, $receiver];
    }

    private function token(int $project, string $scope = 'read'): string
    {
        return $this->postJson("/api/projects/$project/tokens", ['name' => 'CI', 'scope' => $scope])->assertCreated()->json('token');
    }

    public function test_tokens_are_hashed_scoped_revocable_and_never_expose_receivers(): void
    {
        [$user, $p, $e, $c] = $this->setupProject();
        $this->postJson("/api/projects/$p/tokens", ['name' => 'CI', 'scope' => 'admin'])->assertUnprocessable();
        $token = $this->token($p);
        $this->assertSame(hash('sha256', $token), DB::table('project_api_tokens')->value('token_hash'));
        $this->getJson("/api/projects/$p/tokens")->assertOk()->assertJsonCount(1, 'tokens')->assertDontSee($token)->assertDontSee('token_hash');
        $this->getJson("/api/v1/projects/$p/endpoints")->assertUnauthorized();
        $this->withToken('bad')->getJson("/api/v1/projects/$p/endpoints")->assertUnauthorized();
        $this->withToken('wi_'.str_repeat('a', 64))->getJson("/api/v1/projects/$p/endpoints")->assertUnauthorized();
        $this->withToken($token)->getJson("/api/v1/projects/$p/endpoints")->assertOk()->assertJsonCount(1, 'endpoints')->assertDontSee('token');
        $this->assertNotNull(DB::table('project_api_tokens')->value('last_used_at'));
        $this->getJson("/api/v1/endpoints/$e/requests?format=csv")->assertOk()->assertJsonCount(1, 'requests');
        $this->getJson("/api/v1/requests/$c")->assertOk()->assertJsonPath('request.id', $c)->assertJsonStructure(['payload' => ['headers', 'body_base64']]);
        $this->postJson("/api/v1/requests/$c/replay", ['url' => 'https://8.8.8.8/hook', 'confirm' => true])->assertForbidden();
        $this->getJson('/api/v1/projects/99999/endpoints')->assertNotFound();
        $this->getJson('/api/v1/endpoints/99999/requests')->assertNotFound();
        $this->getJson('/api/v1/requests/99999')->assertNotFound();
        $this->postJson('/api/v1/requests/99999/replay', [])->assertForbidden();
        $id = DB::table('project_api_tokens')->value('id');
        $this->deleteJson("/api/projects/$p/tokens/$id")->assertNoContent();
        $this->deleteJson("/api/projects/$p/tokens/$id")->assertNotFound();
        $this->getJson("/api/v1/requests/$c")->assertUnauthorized();
    }

    public function test_replay_rate_limits_expiry_and_tenant_isolation(): void
    {
        [$user, $p, $e, $c] = $this->setupProject();
        $token = $this->token($p, 'read+replay');
        [, $p2, $e2, $c2] = $this->setupProject();
        $this->withToken($token)->getJson("/api/v1/endpoints/$e2/requests")->assertNotFound();
        $this->getJson("/api/v1/requests/$c2")->assertNotFound();
        $this->postJson("/api/v1/requests/$c2/replay", [])->assertNotFound();
        $this->postJson("/api/v1/requests/$c/replay", ['url' => 'http://127.0.0.1', 'confirm' => true])->assertUnprocessable();
        $this->postJson("/api/v1/requests/$c/replay", ['url' => 'https://8.8.8.8/hook', 'confirm' => true])->assertStatus(202);
        $this->assertDatabaseCount('deliveries', 1);
        DB::table('captures')->where('id', $c)->update(['expires_at' => now()->subSecond()]);
        $this->getJson("/api/v1/requests/$c")->assertNotFound();
        RateLimiter::hit('project-api:'.$p, 60);
        for ($i = 0; $i < 120; $i++) RateLimiter::hit('project-api:'.$p, 60);
        $this->getJson("/api/v1/endpoints/$e/requests")->assertStatus(429);
    }

    public function test_members_can_only_manage_their_tokens_and_access_tracks_current_role(): void
    {
        [$owner, $p, $e, $c] = $this->setupProject();
        $ownerToken = $this->token($p);
        $ownerId = DB::table('project_api_tokens')->value('id');
        [$member] = $this->setupProject();
        $this->getJson("/api/projects/$p/tokens")->assertNotFound();
        $this->postJson("/api/projects/$p/tokens", ['name' => 'CI', 'scope' => 'read'])->assertNotFound();
        DB::table('project_memberships')->insert(['project_id' => $p, 'user_id' => $member->id, 'role' => 'viewer']);
        $this->getJson("/api/projects/$p/tokens")->assertForbidden();
        DB::table('project_memberships')->where('project_id', $p)->update(['role' => 'member']);
        $this->getJson("/api/projects/$p/tokens")->assertJsonCount(0, 'tokens');
        $this->deleteJson("/api/projects/$p/tokens/$ownerId")->assertNotFound();
        $token = $this->token($p, 'read+replay');
        $this->actingAs($owner)->getJson("/api/projects/$p/tokens")->assertJsonCount(2, 'tokens');
        $this->withToken($token)->getJson("/api/v1/requests/$c")->assertOk();
        DB::table('project_memberships')->where('project_id', $p)->update(['role' => 'viewer']);
        $this->postJson("/api/v1/requests/$c/replay", ['url' => 'https://8.8.8.8', 'confirm' => true])->assertForbidden();
        DB::table('project_memberships')->where('project_id', $p)->delete();
        $this->getJson("/api/v1/requests/$c")->assertNotFound();
    }

    public function test_bulk_replay_is_bounded_atomic_filtered_and_ssrf_safe(): void
    {
        [$user, $p, $e, $c, $receiver] = $this->setupProject();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $path = "/api/endpoints/$e/bulk-replay";
        $body = ['url' => 'https://8.8.8.8/hook', 'confirm' => true];
        $this->postJson($path, [])->assertUnprocessable();
        $this->postJson($path, $body + ['ids' => []])->assertUnprocessable();
        $this->postJson($path, $body + ['ids' => [$c, 99999]])->assertUnprocessable();
        $this->postJson($path, ['url' => 'http://[::1]', 'confirm' => true, 'ids' => [$c]])->assertUnprocessable();
        $this->assertDatabaseCount('deliveries', 0);
        $this->postJson($path, $body + ['ids' => [$c]])->assertStatus(202)->assertJsonPath('count', 1);
        $this->postJson($path.'?method=GET', $body)->assertUnprocessable();
        $this->postJson($path.'?q=created', $body)->assertStatus(202)->assertJsonPath('count', 1);
        $this->assertDatabaseCount('deliveries', 2);
        for ($i = 0; $i < 20; $i++) $this->postJson('/c/'.$receiver, ['event' => 'more']);
        $this->postJson($path, $body)->assertUnprocessable();
        $this->postJson($path, $body + ['ids' => range(1, 21)])->assertUnprocessable();
        $this->assertDatabaseCount('deliveries', 2);
        $this->postJson($path.'?q=created&format=csv', $body)->assertStatus(202)->assertJsonPath('count', 1);
        DB::table('captures')->where('id', $c)->update(['expires_at' => now()->subSecond()]);
        $this->postJson($path, $body + ['ids' => [$c]])->assertUnprocessable();
    }
}
