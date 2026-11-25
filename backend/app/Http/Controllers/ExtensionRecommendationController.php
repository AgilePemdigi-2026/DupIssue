<?php

namespace App\Http\Controllers;

use App\Services\ExtensionAuthService;
use App\Services\ExtensionRepositoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExtensionRecommendationController extends Controller
{
    // Match the existing duplicate detection threshold.
    private const MINIMUM_SIMILARITY = 0.75;

    public function __construct(
        private readonly ExtensionRepositoryService $repositories,
        private readonly ExtensionAuthService $auth,
        private readonly IssueRecommendationController $recommendations
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'repository' => ExtensionRepositoryService::repositoryRules(),
            'project_id' => ['required', 'integer', 'min:1'],
        ]);

        $repository = strtolower($validated['repository']);
        $projectId = (int) $validated['project_id'];
        $project = $this->repositories->resolveProject(
            $request->user(),
            $repository,
            $projectId
        );

        abort_unless($project !== null, 403, 'Akses tidak tersedia.');

        // Reuse web draft validation, ranking, parent resolution and safe NLP errors.
        $response = ($this->recommendations)($request, $project);

        // NLP may outlive authorization. Discard its response if access changed,
        // including when the shared controller has returned an NLP failure.
        $actor = $this->auth->authenticate((string) $request->bearerToken());

        if ($actor === null) {
            return response()->json([
                'code' => 'UNAUTHENTICATED',
                'message' => 'Sesi extension tidak valid atau telah berakhir.',
            ], 401, [
                'Cache-Control' => 'no-store',
                'WWW-Authenticate' => 'Bearer',
            ]);
        }

        abort_unless(
            $this->repositories->resolveProject($actor, $repository, $projectId) !== null,
            403,
            'Akses tidak tersedia.'
        );

        // Filter extension recommendations only.
        // The shared service already ranks and limits unique targets.
        // Preserve the original HTTP response and error semantics.
        if ($response->getStatusCode() === 200) {
            $payload = $response->getData(true);

            $payload['recommendations'] = array_values(
                array_filter(
                    $payload['recommendations'],
                    static fn (array $row): bool =>
                        $row['cosine_similarity'] >= self::MINIMUM_SIMILARITY
                )
            );

            $response->setData($payload);
        }

        return $response;
    }
}
