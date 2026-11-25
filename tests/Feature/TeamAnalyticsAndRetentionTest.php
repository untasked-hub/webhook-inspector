<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamAnalyticsAndRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => bin2hex(random_bytes(8)).'@example.invalid',
            'password' => bcrypt('long-test-password'),
        ]);
    }

    public function test_analytics_includes_shared_projects_and_supports_project_filter_with_isolation(): void
    {
        config(['billing.managed' => true]);
        $owner = $this->account('Alice Owner');
        $member = $this->account('Bob Teammate');
        $outsider = $this->account('Eve Outsider');

        DB::table('billing_accounts')->insert([
            'user_id' => $owner->id,
            'plan' => 'pro',
            'status' => 'active',
            'paid_until' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner);
        $sharedProject = $this->postJson('/api/projects', ['name' => 'Shared Ops'])->assertCreated()->json('id');
        $privateProject = $this->postJson('/api/projects', ['name' => 'Private Ops'])->assertCreated()->json('id');

        // Pro plan allows up to 60 days retention on endpoint creation (not hardcoded 7).
        $sharedEndpoint = $this->postJson('/api/endpoints', [
            'project_id' => $sharedProject,
            'name' => 'Stripe Prod',
            'retention_days' => 60,
        ])->assertCreated()->json('id');

        // Exceeding Pro retention limit (60d) is rejected.
        $this->postJson('/api/endpoints', [
            'project_id' => $sharedProject,
            'name' => 'Too Long',
            'retention_days' => 61,
        ])->assertUnprocessable();

        $privateEndpoint = $this->postJson('/api/endpoints', [
            'project_id' => $privateProject,
            'name' => 'Internal Only',
        ])->assertCreated()->json('id');

        $sharedToken = DB::table('endpoints')->where('id', $sharedEndpoint)->value('token');
        $privateToken = DB::table('endpoints')->where('id', $privateEndpoint)->value('token');
        $this->postJson('/c/'.$sharedToken, ['event' => 'shared.one'])->assertStatus(202);
        $this->postJson('/c/'.$sharedToken, ['event' => 'shared.two'])->assertStatus(202);
        $this->postJson('/c/'.$privateToken, ['event' => 'private.one'])->assertStatus(202);

        $inviteUrl = $this->postJson("/api/projects/{$sharedProject}/invitations", ['role' => 'member'])->assertCreated()->json('url');
        $inviteToken = explode('#invite=', $inviteUrl)[1];

        $this->actingAs($member);
        $this->postJson('/api/invitations/accept', ['token' => $inviteToken])->assertOk();

        // Owner team listing includes member name without exposing email.
        $this->actingAs($owner);
        $teamRes = $this->getJson("/api/projects/{$sharedProject}/team")->assertOk();
        $teamRes->assertJsonPath('members.0.name', 'Bob Teammate');
        $teamRes->assertJsonPath('members.0.role', 'member');
        $this->assertArrayNotHasKey('email', $teamRes->json('members.0'));

        // Shared member sees owner's retention_limit on /api/projects and can create an endpoint up to owner's limit.
        $this->actingAs($member);
        $projectsRes = $this->getJson('/api/projects')->assertOk();
        $projectsRes->assertJsonPath('projects.0.role', 'member');
        $projectsRes->assertJsonPath('projects.0.retention_limit', 60);

        $this->postJson('/api/endpoints', [
            'project_id' => $sharedProject,
            'name' => 'Team Added',
            'retention_days' => 21,
        ])->assertCreated();

        // Member's analytics includes shared project captures, role, and owner retention limit, but excludes private project.
        $analytics = $this->getJson('/api/analytics?days=30')->assertOk()->assertJsonPath('days', 30);
        $analytics->assertJsonCount(2, 'endpoints');
        $analytics->assertJsonPath('endpoints.0.project', 'Shared Ops');
        $analytics->assertJsonPath('endpoints.0.role', 'member');
        $analytics->assertJsonPath('endpoints.0.retention_days', 60);
        $analytics->assertJsonPath('endpoints.0.retention_limit', 60);
        $analytics->assertJsonPath('endpoints.0.requests', 2);
        $this->assertSame(2, collect($analytics->json('daily'))->sum('requests'));

        // Filtering analytics by shared project_id works; filtering by unshared privateProject returns 404.
        $this->getJson("/api/analytics?days=7&project_id={$sharedProject}")
            ->assertOk()
            ->assertJsonPath('project_id', $sharedProject)
            ->assertJsonCount(2, 'endpoints');
        $this->getJson("/api/analytics?days=7&project_id={$privateProject}")->assertNotFound();

        // Outsider cannot see any shared or private project analytics.
        $this->actingAs($outsider);
        $this->getJson("/api/analytics?days=7&project_id={$sharedProject}")->assertNotFound();
        $outsiderAnalytics = $this->getJson('/api/analytics?days=7')->assertOk();
        $outsiderAnalytics->assertJsonCount(0, 'endpoints');
        $this->assertSame(0, collect($outsiderAnalytics->json('daily'))->sum('requests'));
    }
}
