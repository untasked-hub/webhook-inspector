<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProjectAccess
{
    public static function authorize(Request $request, int $id, bool $write = false, bool $ownerOnly = false)
    {
        $project = DB::table('projects')->find($id);
        abort_unless($project, 404);
        if ($project->user_id === $request->user()->id) return $project;
        $role = DB::table('project_memberships')->where('project_id', $id)->where('user_id', $request->user()->id)->value('role');
        abort_unless($role, 404);
        abort_if($ownerOnly || ($write && $role !== 'member'), 403, 'Your project role does not permit this action.');
        return $project;
    }
}
