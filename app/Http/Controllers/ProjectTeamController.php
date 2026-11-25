<?php

namespace App\Http\Controllers;

use App\Support\ProjectAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectTeamController extends Controller
{
    public function index(Request $request, int $id)
    {
        ProjectAccess::authorize($request, $id, false, true);
        return response()->json([
            'members' => DB::table('project_memberships')
                ->join('users', 'users.id', '=', 'project_memberships.user_id')
                ->where('project_memberships.project_id', $id)
                ->orderBy('project_memberships.id')
                ->get(['project_memberships.id', 'users.name', 'project_memberships.role', 'project_memberships.created_at']),
            'invitations' => DB::table('project_invitations')->where('project_id', $id)->where('expires_at', '>', now())->get(['id', 'role', 'expires_at']),
        ])->header('Cache-Control', 'no-store');
    }

    public function invite(Request $request, int $id)
    {
        ProjectAccess::authorize($request, $id, true, true);
        $data = $request->validate(['role' => 'required|in:member,viewer']);
        $token = Str::random(64);
        DB::transaction(function () use ($id, $data, $token) {
            DB::table('projects')->where('id', $id)->lockForUpdate()->first();
            DB::table('project_invitations')->where('project_id', $id)->where('expires_at', '<=', now())->delete();
            abort_if(DB::table('project_invitations')->where('project_id', $id)->count() + DB::table('project_memberships')->where('project_id', $id)->count() >= 50, 422, 'Project team limit reached. Revoke unused invitations first.');
            DB::table('project_invitations')->insert(['project_id' => $id, 'role' => $data['role'], 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        });
        // Fragment is never sent to the server/access logs. Acceptance requires explicit POST.
        return response()->json(['url' => url('/dashboard').'#invite='.$token], 201)->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|size:64']);
        $projectId = DB::transaction(function () use ($request, $data) {
            $invite = DB::table('project_invitations')->where('token_hash', hash('sha256', $data['token']))->lockForUpdate()->first();
            abort_unless($invite && now()->lt($invite->expires_at), 404, 'Invitation expired, revoked, or already used.');
            $project = DB::table('projects')->find($invite->project_id);
            abort_if($project->user_id === $request->user()->id, 422, 'Project owners do not need an invitation.');
            // Existing roles cannot be elevated or downgraded through stale invitations.
            DB::table('project_memberships')->insertOrIgnore(['project_id' => $invite->project_id, 'user_id' => $request->user()->id, 'role' => $invite->role, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('project_invitations')->where('id', $invite->id)->delete();
            return $invite->project_id;
        });
        return response()->json(['project_id' => $projectId]);
    }

    public function update(Request $request, int $id, int $member)
    {
        ProjectAccess::authorize($request, $id, true, true);
        $data = $request->validate(['role' => 'required|in:member,viewer']);
        abort_unless(DB::table('project_memberships')->where('project_id', $id)->where('id', $member)->exists(), 404);
        DB::table('project_memberships')->where('project_id', $id)->where('id', $member)->update($data + ['updated_at' => now()]);
        return response()->noContent();
    }

    public function remove(Request $request, int $id, int $member)
    {
        ProjectAccess::authorize($request, $id, true, true);
        abort_unless(DB::table('project_memberships')->where('project_id', $id)->where('id', $member)->delete(), 404);
        return response()->noContent();
    }

    public function revoke(Request $request, int $id, int $invite)
    {
        ProjectAccess::authorize($request, $id, true, true);
        abort_unless(DB::table('project_invitations')->where('project_id', $id)->where('id', $invite)->delete(), 404);
        return response()->noContent();
    }
}
