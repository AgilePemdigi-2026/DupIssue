<?php

namespace App\Http\Controllers;

use App\Services\ExtensionRepositoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExtensionRepositoryController extends Controller
{
    public function __construct(
        private readonly ExtensionRepositoryService $repositories
    ) {
    }

    public function resolve(
        Request $request
    ): JsonResponse {
        $actor = $request->user();

        abort_unless(
            $actor !== null
                && $actor->role === 'REPORTER',
            403,
            'Akses tidak tersedia.'
        );

        $validated = $request->validate([
            'repository' =>
                ExtensionRepositoryService::repositoryRules(),
        ]);

        $repository = strtolower(
            $validated['repository']
        );

        return response()->json([
            'repository' => $repository,
            'projects' => $this->repositories
                ->availableProjects($actor, $repository),
        ], 200, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
