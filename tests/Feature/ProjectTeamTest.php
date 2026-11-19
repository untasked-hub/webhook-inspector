<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class ProjectTeamTest extends TestCase
{
    use RefreshDatabase;

    private function account(): User
    {
        return User::create(['name' => 'Synthetic', 'email' => bin2hex(random_bytes(8)).'@example.invalid', 'password' => bcrypt('long-test-password')]);
    }

    public function test_invites_roles_isolation_and_revocation(): void
    {
        $owner = $this->account(); $member = $this->account(); $outsider = $this->account();
        $this->actingAs($owner);
        $project = $this->postJson('/api/projects', ['name' => 'Team'])->json('id');
        $endpoint = $this->postJson('/api/endpoints', ['project_id' => $project, 'name' => 'Receiver'])->json('id');
        $this->postJson("/api/projects/$project/invitations", ['role' => 'owner'])->assertUnprocessable();
        $url = $this->postJson("/api/projects/$project/invitations", ['role' => 'viewer'])->assertCreated()->json('url');
        $token = explode('#invite=', $url)[1];
        $this->assertNotSame($token, DB::table('project_invitations')->value('token_hash'));
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertUnprocessable();
        $this->actingAs($outsider);
        $this->getJson("/api/projects/$project/team")->assertNotFound();
        $this->getJson("/api/endpoints/$endpoint/requests")->assertNotFound();
        $this->actingAs($member);
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertOk()->assertJsonPath('project_id', $project);
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertNotFound();
        $this->getJson('/api/projects')->assertJsonPath('projects.0.role', 'viewer');
        $this->getJson("/api/endpoints/$endpoint/requests")->assertOk();
        $this->getJson("/api/endpoints/$endpoint/receiver-settings")->assertOk();
        $this->getJson("/api/endpoints/$endpoint/forward")->assertOk();
        $this->getJson("/api/endpoints/$endpoint/deliveries")->assertOk();
        foreach (['delete', 'put', 'post'] as $method) {
            $path = match ($method) { 'delete' => "/api/endpoints/$endpoint/requests", 'put' => "/api/endpoints/$endpoint/retention", default => "/api/endpoints/$endpoint/requests/1/replay" };
            $this->json(strtoupper($method), $path, [])->assertForbidden();
        }
        $this->postJson('/api/endpoints', ['project_id' => $project, 'name' => 'Denied'])->assertForbidden();
        $this->postJson("/api/projects/$project/invitations", ['role' => 'member'])->assertForbidden();
        $this->deleteJson("/api/projects/$project")->assertNotFound();
        $membership = DB::table('project_memberships')->value('id');
        $this->actingAs($owner);
        $this->getJson("/api/projects/$project/team")->assertJsonCount(1, 'members');
        $this->patchJson("/api/projects/$project/members/9999", ['role' => 'member'])->assertNotFound();
        $this->patchJson("/api/projects/$project/members/$membership", ['role' => 'member'])->assertNoContent();
        $this->actingAs($member);
        $this->postJson('/api/endpoints', ['project_id' => $project, 'name' => 'Allowed'])->assertCreated();
        $this->putJson("/api/endpoints/$endpoint/retention", ['retention_days' => 1])->assertNoContent();
        $this->deleteJson("/api/endpoints/$endpoint/requests")->assertNoContent();
        $this->actingAs($owner);
        $this->deleteJson("/api/projects/$project/members/$membership")->assertNoContent();
        $this->deleteJson("/api/projects/$project/members/$membership")->assertNotFound();
        $this->actingAs($member);
        $this->getJson("/api/endpoints/$endpoint/requests")->assertNotFound();
        $this->getJson('/api/projects')->assertJsonCount(0, 'projects');
    }

    public function test_expired_revoked_bounded_and_existing_membership_invitations(): void
    {
        $owner = $this->account(); $member = $this->account();
        $this->actingAs($owner);
        $project = $this->postJson('/api/projects', ['name' => 'Team'])->json('id');
        $invite = fn () => explode('#invite=', $this->postJson("/api/projects/$project/invitations", ['role' => 'member'])->assertCreated()->json('url'))[1];
        $expired = $invite();
        DB::table('project_invitations')->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($member)->postJson('/api/invitations/accept', ['token' => $expired])->assertNotFound();
        $this->actingAs($owner); $token = $invite();
        $invitation = DB::table('project_invitations')->value('id');
        $this->deleteJson("/api/projects/$project/invitations/$invitation")->assertNoContent();
        $this->deleteJson("/api/projects/$project/invitations/$invitation")->assertNotFound();
        $this->actingAs($member)->postJson('/api/invitations/accept', ['token' => $token])->assertNotFound();
        $this->actingAs($owner); $token = $invite();
        DB::table('project_memberships')->insert(['project_id' => $project, 'user_id' => $member->id, 'role' => 'viewer']);
        $this->actingAs($member)->postJson('/api/invitations/accept', ['token' => $token])->assertOk();
        $this->assertSame('viewer', DB::table('project_memberships')->value('role'));
        $this->actingAs($owner);
        for ($i = 0; $i < 49; $i++) DB::table('project_invitations')->insert(['project_id' => $project, 'role' => 'viewer', 'token_hash' => hash('sha256', (string)$i), 'expires_at' => now()->addDay()]);
        $this->postJson("/api/projects/$project/invitations", ['role' => 'viewer'])->assertUnprocessable();
        $this->deleteJson("/api/projects/$project")->assertNoContent();
        $this->assertDatabaseCount('project_memberships', 0);
        $this->assertDatabaseCount('project_invitations', 0);
    }
}
