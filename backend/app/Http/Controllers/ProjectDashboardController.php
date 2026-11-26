<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectDashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectDashboardController extends Controller
{
    public function show(
        Request $request,
        Project $project,
        ProjectDashboardService $dashboardService
    ): View {
        $user = $request->user();
        abort_unless(
            $user
                && $user->role === 'MAINTAINER'
                && (int) $project->owner_id === (int) $user->id,
            403,
            'Akses tidak tersedia'
        );

        return view('projects.dashboard', [
            'project' => $project,
            'stats' => $dashboardService->forProject($project),
        ]);
    }
}
