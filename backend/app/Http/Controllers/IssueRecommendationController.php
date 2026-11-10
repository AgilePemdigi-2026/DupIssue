<?php

namespace App\Http\Controllers;

use App\Exceptions\NlpClientException;
use App\Models\Project;
use App\Services\IssueRecommendationService;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IssueRecommendationController extends Controller
{
    public function __construct(
        private readonly IssueRecommendationService $recommendations,
        private readonly ProjectService $projects
    ) {
    }

    /**
     * Menghasilkan rekomendasi issue serupa sebelum submit.
     */
    public function __invoke(
        Request $request,
        Project $project
    ): JsonResponse {
        // 1. Pastikan pengguna adalah Reporter
        // yang memiliki akses ke project.
        $user = $request->user();

        abort_unless(
            $user
                && $user->role === 'REPORTER'
                && $this->projects->canAccessProject($user, $project),
            403,
            'Akses tidak tersedia'
        );

        // 2. Verifikasi membership aktif.
        // Mengikuti aturan IssueController Sprint 1.
        abort_unless(
            $project->memberships()
                ->where('user_id', $user->getKey())
                ->where('is_active', true)
                ->exists(),
            403,
            'Akses tidak tersedia'
        );

        // 3. Validasi draft issue.
        // Batas sama dengan form Submit Issue.
        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'min:5',
                'max:200',
            ],
            'description' => [
                'required',
                'string',
                'min:10',
                'max:10000',
            ],
        ]);

        // 4. Panggil service rekomendasi Top 3.
        try {
            $results = $this->recommendations->recommend(
                $project,
                $validated['title'],
                $validated['description']
            );
        } catch (NlpClientException $exception) {
            // Kegagalan NLP tidak boleh menggagalkan
            // proses Submit Issue yang terpisah.
            Log::warning('Pre-submit issue recommendation unavailable', [
                'project_id' => $project->getKey(),
                'exception_type' => get_class($exception),
                'nlp_status' => $exception->statusCode(),
            ]);

            return response()->json([
                'recommendations' => [],
                'code' => 'NLP_UNAVAILABLE',
                'message' => 'Rekomendasi belum dapat dimuat',
            ], 503);
        }

        // 5. Respons sukses, termasuk jika tidak
        // ditemukan issue pembanding.
        return response()->json([
            'recommendations' => $results,
        ], 200);
    }
}
