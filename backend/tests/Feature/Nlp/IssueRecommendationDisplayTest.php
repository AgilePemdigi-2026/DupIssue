<?php

namespace Tests\Feature\Nlp;

use App\Models\DuplicateCandidate;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class IssueRecommendationDisplayTest extends TestCase
{
    use DatabaseMigrations;

    private function setupReporter(): array
    {
        $project = Project::factory()->create();
        $reporter = User::factory()->create(['role' => 'REPORTER']);
        Membership::factory()->create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => true,
        ]);

        return [$project, $reporter];
    }

    private function makeIssue(Project $project, User $reporter, string $title, string $state): Issue
    {
        return Issue::forceCreate([
            'title' => $title,
            'description' => 'Deskripsi simulasi untuk pengujian rekomendasi issue.',
            'project_id' => $project->id,
            'author_id' => $reporter->id,
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => $state,
        ]);
    }

    public function test_reporter_sees_scored_candidate_and_project_scoped_link(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $source = $this->makeIssue($project, $reporter, 'Issue sumber', 'COMPLETED');
        $candidate = $this->makeIssue($project, $reporter, 'Issue pembanding autentikasi', 'COMPLETED');
        DuplicateCandidate::query()->create([
            'issue_id' => $source->id,
            'candidate_issue_id' => $candidate->id,
            'similarity_score' => 1.0,
        ]);

        $this->actingAs($reporter)
            ->get(route('issues.show', ['project' => $project, 'issue' => $source]))
            ->assertOk()
            ->assertSee('Rekomendasi Issue Serupa')
            ->assertSee('Issue pembanding autentikasi')
            ->assertSee('100,00%')
            ->assertSee(route('issues.show', ['project' => $project, 'issue' => $candidate]), false)
            ->assertSee('Keputusan duplikat tetap dilakukan Maintainer');

        // Project owner/Maintainer can inspect the same recommendations.
        $this->actingAs($project->owner)
            ->get(route('issues.show', ['project' => $project, 'issue' => $source]))
            ->assertOk()
            ->assertSee('Issue pembanding autentikasi');
    }

    public static function csvDisplaySourceCases(): array
    {
        return [
            'CSV' => ['CSV', 3],
            'FORM' => ['FORM', 10],
            'GITHUB' => ['GITHUB', 10],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('csvDisplaySourceCases')]
    public function test_detail_recommendation_limit_respects_source_type(
        string $sourceType,
        int $expectedCount
    ): void {
        [$project, $reporter] = $this->setupReporter();

        $source = $this->makeIssue(
            $project,
            $reporter,
            'Source issue for recommendation display',
            'COMPLETED'
        );

        $source->source_type = $sourceType;

        if ($sourceType === 'CSV') {
            $source->source_id = 'CSV-UI-SOURCE';
            $source->author_id = null;
        }

        if ($sourceType === 'GITHUB') {
            $source->author_id = null;
            $source->github_repo = 'example/test-repo';
            $source->github_number = 1;
            $source->source_url =
                'https://github.com/example/test-repo/issues/1';
        }

        $source->save();

        $candidateIds = [];
        $titles = [];

        // Simulate historical results containing more than
        // three candidates, including records created before
        // the CSV-specific limit was introduced.
        for ($i = 1; $i <= 12; $i++) {
            $title = sprintf(
                'Recommendation display candidate [%02d]',
                $i
            );

            $candidate = $this->makeIssue(
                $project,
                $reporter,
                $title,
                'COMPLETED'
            );

            DuplicateCandidate::query()->create([
                'issue_id' => $source->id,
                'candidate_issue_id' => $candidate->id,
                'similarity_score' => round(
                    0.99 - ($i / 100),
                    2
                ),
            ]);

            $candidateIds[] = (int) $candidate->id;
            $titles[] = $title;
        }

        $expectedIds = array_slice(
            $candidateIds,
            0,
            $expectedCount
        );

        $url = route('issues.show', [
            'project' => $project,
            'issue' => $source,
        ]);

        // Both authorized roles must see the same limit.
        foreach ([$reporter, $project->owner] as $viewer) {
            $response = $this->actingAs($viewer)
                ->get($url)
                ->assertOk()
                ->assertViewHas(
                    'recommendations',
                    fn ($rows) =>
                        $rows->count() === $expectedCount
                        && $rows->pluck('candidate_issue_id')
                            ->all() === $expectedIds
                );

            if ($sourceType === 'CSV') {
                $response->assertSee(
                    'Top 3 Rekomendasi Issue Serupa'
                );
            } else {
                $response->assertSee(
                    'Rekomendasi Issue Serupa'
                );

                $response->assertDontSee(
                    'Top 3 Rekomendasi Issue Serupa'
                );
            }

            foreach ($titles as $index => $title) {
                if ($index < $expectedCount) {
                    $response->assertSee($title);
                } else {
                    $response->assertDontSee($title);
                }
            }

            if ($viewer->role === 'MAINTAINER') {
                $response->assertSee(
                    'Bandingkan dan Validasi'
                );
            }
        }

        // A read-only display limit must never delete history.
        $this->assertSame(
            12,
            DuplicateCandidate::query()
                ->where('issue_id', $source->id)
                ->count()
        );

        $this->assertSame(
            'OPEN',
            $source->refresh()->status
        );

        $this->assertNull($source->duplicate_of);
    }

    public static function csvDetectionStatusCases(): array
    {
        return [
            'PENDING' => [
                'PENDING',
                'Deteksi kemiripan sedang diproses',
            ],
            'PROCESSING' => [
                'PROCESSING',
                'Deteksi kemiripan sedang diproses',
            ],
            'FAILED' => [
                'FAILED',
                'Deteksi kemiripan belum berhasil. Laporan issue tetap tersimpan.',
            ],
            'COMPLETED without candidates' => [
                'COMPLETED',
                'Belum ada kandidat yang memenuhi batas similarity',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('csvDetectionStatusCases')]
    public function test_csv_detail_displays_detection_status(
        string $status,
        string $notice
    ): void {
        [$project, $reporter] = $this->setupReporter();

        $issue = $this->makeIssue(
            $project,
            $reporter,
            'CSV imported issue processing state',
            $status
        );

        $issue->source_type = 'CSV';
        $issue->source_id = 'CSV-STATUS-TEST';
        $issue->author_id = null;
        $issue->save();

        $response = $this->actingAs($reporter)
            ->get(route('issues.show', [
                'project' => $project,
                'issue' => $issue,
            ]))
            ->assertOk()
            ->assertSee($notice);

        if ($status === 'COMPLETED') {
            $response->assertSee(
                'Top 3 Rekomendasi Issue Serupa'
            );
        } else {
            $response->assertDontSee(
                'Top 3 Rekomendasi Issue Serupa'
            );
        }

        $this->assertSame(
            $status,
            $issue->refresh()->detection_status
        );

        $this->assertSame('OPEN', $issue->status);
        $this->assertDatabaseCount('duplicate_candidates', 0);
    }

    public function test_csv_detail_equal_scores_use_deterministic_candidate_order(): void
    {
        [$project, $reporter] = $this->setupReporter();

        $source = $this->makeIssue(
            $project,
            $reporter,
            'CSV source with equal similarity scores',
            'COMPLETED'
        );

        $source->source_type = 'CSV';
        $source->source_id = 'CSV-TIE-DISPLAY';
        $source->author_id = null;
        $source->save();

        $ids = [];

        for ($i = 1; $i <= 5; $i++) {
            $candidate = $this->makeIssue(
                $project,
                $reporter,
                "Equal similarity candidate {$i}",
                'COMPLETED'
            );

            $ids[] = (int) $candidate->id;
        }

        sort($ids);

        // Insert in reverse ID order to test deterministic ranking.
        foreach (array_reverse($ids) as $id) {
            DuplicateCandidate::query()->create([
                'issue_id' => $source->id,
                'candidate_issue_id' => $id,
                'similarity_score' => 0.90,
            ]);
        }

        $this->actingAs($reporter)
            ->get(route('issues.show', [
                'project' => $project,
                'issue' => $source,
            ]))
            ->assertOk()
            ->assertViewHas(
                'recommendations',
                fn ($rows) =>
                    $rows->pluck('candidate_issue_id')->all()
                    === array_slice($ids, 0, 3)
            );

        $this->assertDatabaseCount('duplicate_candidates', 5);
    }

    public function test_completed_issue_without_candidates_shows_empty_state(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $issue = $this->makeIssue($project, $reporter, 'Issue tanpa kandidat', 'COMPLETED');

        $this->actingAs($reporter)
            ->get(route('issues.show', ['project' => $project, 'issue' => $issue]))
            ->assertOk()
            ->assertSee('Belum ada kandidat yang memenuhi batas similarity');
    }

    public function test_pending_issue_displays_processing_notice(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $issue = $this->makeIssue($project, $reporter, 'Issue menunggu NLP', 'PENDING');

        $this->actingAs($reporter)
            ->get(route('issues.show', ['project' => $project, 'issue' => $issue]))
            ->assertOk()
            ->assertSee('Deteksi kemiripan sedang diproses')
            ->assertDontSee('Rekomendasi Issue Serupa');
    }
}
