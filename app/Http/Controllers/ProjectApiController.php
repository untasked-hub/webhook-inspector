<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ProjectAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectApiController extends Controller
{
    public function tokens(Request $request, int $project)
    {
        $owner = ProjectAccess::authorize($request, $project, true)->user_id === $request->user()->id;
        $query = DB::table('project_api_tokens')->where('project_id', $project);
        if (!$owner) $query->where('user_id', $request->user()->id);
        return response()->json(['tokens' => $query->orderByDesc('id')->get(['id', 'name', 'prefix', 'scope', 'created_at', 'last_used_at'])])->header('Cache-Control', 'no-store');
    }

    public function create(Request $request, int $project)
    {
        ProjectAccess::authorize($request, $project, true);
        $data = $request->validate(['name' => 'required|string|max:80', 'scope' => 'required|in:read,read+replay']);
        $raw = 'wi_'.Str::random(64);
        $id = DB::transaction(function () use ($request, $project, $data, $raw) {
            DB::table('projects')->where('id', $project)->lockForUpdate()->first();
            abort_if(DB::table('project_api_tokens')->where('project_id', $project)->count() >= 50, 422, 'Revoke an unused token first (50 per project).');
            return DB::table('project_api_tokens')->insertGetId($data + ['project_id' => $project, 'user_id' => $request->user()->id, 'token_hash' => hash('sha256', $raw), 'prefix' => substr($raw, 0, 12), 'created_at' => now(), 'updated_at' => now()]);
        });
        return response()->json(['id' => $id, 'token' => $raw], 201)->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, int $project, int $token)
    {
        $owner = ProjectAccess::authorize($request, $project, true)->user_id === $request->user()->id;
        $query = DB::table('project_api_tokens')->where('project_id', $project)->where('id', $token);
        if (!$owner) $query->where('user_id', $request->user()->id);
        abort_unless($query->delete(), 404);
        return response()->noContent();
    }

    public function endpoints(Request $request, int $project)
    {
        abort_unless($project === (int) $request->attributes->get('api_project'), 404);
        return response()->json(['endpoints' => DB::table('endpoints')->where('project_id', $project)->orderBy('id')->get(['id', 'project_id', 'name', 'retention_days', 'created_at'])])->header('Cache-Control', 'no-store');
    }

    private function endpoint(Request $request, int $id): void
    {
        abort_unless(DB::table('endpoints')->where('id', $id)->where('project_id', $request->attributes->get('api_project'))->exists(), 404);
    }

    public function history(Request $request, int $endpoint)
    {
        $this->endpoint($request, $endpoint);
        // REST always returns the documented JSON envelope; exports use the dashboard API.
        $request->query->remove('format');
        return app(InspectorController::class)->history($request, $endpoint);
    }

    public function detail(Request $request, int $capture)
    {
        $endpoint = (int) DB::table('captures')->where('id', $capture)->value('endpoint_id');
        $this->endpoint($request, $endpoint);
        return app(InspectorController::class)->detail($request, $endpoint, $capture);
    }

    public function replay(Request $request, int $capture)
    {
        $endpoint = (int) DB::table('captures')->where('id', $capture)->value('endpoint_id');
        $this->endpoint($request, $endpoint);
        return app(InspectorController::class)->replay($request, $endpoint, $capture);
    }
}
