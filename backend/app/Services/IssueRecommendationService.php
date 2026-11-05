<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\Project;

class IssueRecommendationService
{
    public function __construct(
        private readonly NlpClient $nlp
    ) {
    }

    /**
     * Menghasilkan maksimal 3 rekomendasi untuk draft issue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recommend(
        Project $project,
        string $title,
        string $description
    ): array {
        // Kandidat hanya berasal dari project aktif.
        $issues = Issue::query()
            ->where('project_id', $project->getKey())
            ->get([
                'id',
                'project_id',
                'title',
                'description',
                'status',
                'duplicate_of',
            ]);

        // Tidak memanggil NLP jika belum ada issue pembanding.
        if ($issues->isEmpty()) {
            return [];
        }

        // Peta kandidat asli untuk memverifikasi hasil FastAPI.
        $issueMap = $issues->keyBy(
            fn (Issue $issue) => $issue->getKey()
        );

        $candidates = $issues->map(
            fn (Issue $issue) => [
                'issue_id' => (int) $issue->id,
                'project_id' => (int) $issue->project_id,
                'title' => $issue->title,
                'description' => $issue->description,
            ]
        )->all();

        // Draft belum mempunyai ID database.
        $response = $this->nlp->candidates(
            (int) $project->getKey(),
            [
                'issue_id' => 'draft',
                'title' => $title,
                'description' => $description,
            ],
            $candidates
        );

        $results = [];

        foreach ($response['candidates'] as $item) {
            // Abaikan ID yang tidak ada dalam project aktif.
            $candidate = $issueMap->get($item['issue_id']);

            if (! $candidate) {
                continue;
            }

            $rawScore = (float) $item['similarity'];

            // Skor harus berupa angka finite.
            // Cosine valid berada di [-1, 1]; beri toleransi kecil untuk float.
            if (! is_finite($rawScore) ||
                $rawScore < -1.000001 || $rawScore > 1.000001) {
                continue;
            }

            $target = $candidate;

            // Relasi duplicate_of menentukan issue anak, termasuk saat CLOSED.
            // Status DUPLICATE tanpa parent valid tidak boleh ditautkan ke dirinya.
            if ($candidate->duplicate_of !== null) {
                $parentId = (int) $candidate->duplicate_of;

                if ($parentId <= 0 || $parentId === (int) $candidate->id) {
                    continue;
                }

                $parent = $issueMap->get($parentId);

                // Peta issue sudah dibatasi ke project aktif.
                // Parent harus root (tidak punya parent lain) dan bukan DUPLICATE.
                if (
                    ! $parent ||
                    (int) $parent->project_id !== (int) $project->id ||
                    $parent->status === 'DUPLICATE' ||
                    $parent->duplicate_of !== null
                ) {
                    continue;
                }

                $target = $parent;
            } elseif ($candidate->status === 'DUPLICATE') {
                // Data tidak konsisten: DUPLICATE tanpa relasi induk.
                continue;
            }

            $results[] = [
                'issue_id' => (int) $candidate->id,
                'target_issue_id' => (int) $target->id,
                'title' => $candidate->title,
                'description' => $candidate->description,
                'status' => $candidate->status,

                // Skor tampilan dibatasi pada rentang 0 sampai 1.
                'similarity' => max(
                    0.0,
                    min(1.0, $rawScore)
                ),
                // Skor cosine signed agar nilai negatif tidak disamarkan.
                // similarity adalah kekuatan positif untuk progress UI,
                // bukan probabilitas duplikat.
                'cosine_similarity' => $rawScore,

                'detail_url' => route(
                    'issues.show',
                    [
                        'project' => $project,
                        'issue' => $target,
                    ],
                    false
                ),

                // Skor mentah hanya untuk pengurutan internal.
                '_raw_score' => $rawScore,
            ];
        }

        // Urutkan berdasarkan skor mentah tertinggi.
        // Jika sama, ID issue terkecil didahulukan.
        usort($results, static function (array $a, array $b): int {
            $comparison = $b['_raw_score'] <=> $a['_raw_score'];

            return $comparison !== 0
                ? $comparison
                : $a['issue_id'] <=> $b['issue_id'];
        });

        $recommendations = [];
        $usedTargets = [];

        foreach ($results as $result) {
            $targetId = $result['target_issue_id'];

            // Hindari beberapa rekomendasi menuju parent sama.
            if (isset($usedTargets[$targetId])) {
                continue;
            }

            $usedTargets[$targetId] = true;

            // Jangan bocorkan skor internal ke response JSON.
            unset($result['_raw_score']);

            $recommendations[] = $result;

            if (count($recommendations) === 3) {
                break;
            }
        }

        return $recommendations;
    }
}
