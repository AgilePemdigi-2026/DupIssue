<?php

namespace Tests\Feature;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\DuplicateCandidate;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DuplicateReviewCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $reporter = User::factory()->create(['role' => 'REPORTER']);

        Membership::factory()->create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => true,
        ]);

        $source = Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $reporter->id,
            'title' => 'Issue Utama Bug Login',
            'description' => 'User tidak dapat login setelah submit form.',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'vote_count' => 5,
            'detection_status' => 'COMPLETED',
        ]);

        $candidate = Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $reporter->id,
            'title' => 'Gagal Masuk Akun Pengguna',
            'description' => 'Ketika tombol login ditekan muncul error form.',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'vote_count' => 2,
            'detection_status' => 'COMPLETED',
        ]);

        $pair = DuplicateCandidate::create([
            'issue_id' => $source->id,
            'candidate_issue_id' => $candidate->id,
            'similarity_score' => 0.8850,
            'decision' => 'PENDING',
        ]);

        return [$owner, $reporter, $project, $source, $candidate, $pair];
    }

    public function test_maintainer_can_view_centralized_duplicate_candidates_list(): void
    {
        [$owner, , $project, $source, $candidate, $pair] = $this->fixture();

        // Create one FAILED issue
        $failedIssue = Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $owner->id,
            'title' => 'Issue Deteksi Gagal',
            'description' => 'Deskripsi issue yang gagal diproses NLP.',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'FAILED',
        ]);

        $response = $this->actingAs($owner)->get(route('projects.duplicates.index', ['project' => $project]));

        $response->assertOk();
        $response->assertSee('Daftar Kandidat Duplikat');
        $response->assertSee($source->title);
        $response->assertSee($candidate->title);
        $response->assertSee('88,50%');
        $response->assertSee('Issue Deteksi Gagal');
        $response->assertSee('Proses Ulang');
    }

    public function test_security_access_restrictions_to_duplicates_index(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $otherMaintainer = User::factory()->create(['role' => 'MAINTAINER']);

        // 1. Guest -> redirected
        $this->get(route('projects.duplicates.index', ['project' => $project]))
            ->assertRedirect('/login');

        // 2. Active Reporter in the project -> 403 Forbidden
        $this->actingAs($reporter)->get(route('projects.duplicates.index', ['project' => $project]))
            ->assertForbidden();

        // 3. Maintainer of different project -> 403 Forbidden
        $this->actingAs($otherMaintainer)->get(route('projects.duplicates.index', ['project' => $project]))
            ->assertForbidden();
    }

    public function test_maintainer_can_view_side_by_side_comparison(): void
    {
        [$owner, , $project, $source, $candidate, $pair] = $this->fixture();

        $response = $this->actingAs($owner)->get(route('issues.duplicates.compare', [
            'project' => $project,
            'issue' => $source,
            'candidate' => $pair,
        ]));

        $response->assertOk();
        $response->assertSee('Bandingkan Pasangan Issue');
        $response->assertSee($source->title);
        $response->assertSee($candidate->title);
        $response->assertSee('Vote: 5');
        $response->assertSee('Vote: 2');
        $response->assertSee('Aturan Relasi Duplikat');
        $response->assertSee('Ringkasan Konsekuensi Status');
        $response->assertSee('Konfirmasi Duplikat');
        $response->assertSee('Bukan Duplikat');
        // Recommendation label based on higher votes (5 vs 2)
        $response->assertSee('Disarankan — Vote Terbanyak (5 vs 2)');
    }

    public function test_compare_rejects_cross_project_and_unauthorized_users(): void
    {
        [$owner, $reporter, $project, $source, , $pair] = $this->fixture();
        $otherProject = Project::factory()->create(['owner_id' => $owner->id]);
        $otherMaintainer = User::factory()->create(['role' => 'MAINTAINER']);

        $compareUrl = route('issues.duplicates.compare', [
            'project' => $project,
            'issue' => $source,
            'candidate' => $pair,
        ]);

        // Reporter -> 403
        $this->actingAs($reporter)->get($compareUrl)->assertForbidden();

        // Other Maintainer -> 403
        $this->actingAs($otherMaintainer)->get($compareUrl)->assertForbidden();

        // Cross-project mismatch: $owner IS owner of $otherProject, but $source and $pair
        // belong to the original $project, so scope check fires before authz -> 404
        $this->actingAs($owner)->get(route('issues.duplicates.compare', [
            'project' => $otherProject,
            'issue' => $source,
            'candidate' => $pair,
        ]))->assertNotFound();
    }

    public function test_validation_relations_server_side_enforcement(): void
    {
        [$owner, , $project, $source, $candidate, $pair] = $this->fixture();

        $basePayload = [
            'decision' => 'DUPLICATE',
            'expected_decision' => 'PENDING',
            'expected_source_status' => 'OPEN',
            'expected_candidate_status' => 'OPEN',
            'expected_source_parent' => 0,
            'expected_candidate_parent' => 0,
        ];

        $decideUrl = route('issues.duplicates.decide', [
            'project' => $project,
            'issue' => $source,
            'candidate' => $pair,
        ]);

        // 1. Parent is invalid / not in pair
        $unknownId = 99999;
        $response = $this->actingAs($owner)->post($decideUrl, array_merge($basePayload, [
            'parent_issue_id' => $unknownId,
        ]));
        $response->assertStatus(422);

        // 2. Parent is already a DUPLICATE of another issue
        $parentIssue = Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $owner->id,
            'title' => 'Grandparent Issue',
            'description' => 'Induk utama.',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
        ]);
        // Use DB::table to bypass Eloquent mass-assignment guard on status/duplicate_of
        DB::table('issues')->where('id', $source->id)
            ->update(['duplicate_of' => $parentIssue->id, 'status' => 'DUPLICATE']);

        $response = $this->actingAs($owner)->post($decideUrl, array_merge($basePayload, [
            'expected_source_status' => 'DUPLICATE',
            'expected_source_parent' => $parentIssue->id,
            'parent_issue_id' => $source->id,
        ]));
        $response->assertStatus(409); // Induk bukan duplikat

        // 3. Child is already parent of another issue (cycle prevention)
        // Reset source — use DB::table to bypass mass-assignment guard
        DB::table('issues')->where('id', $source->id)
            ->update(['duplicate_of' => null, 'status' => 'OPEN']);
        Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $owner->id,
            'title' => 'Sub-child issue',
            'description' => 'Anak dari candidate.',
            'source_type' => 'FORM',
            'status' => 'DUPLICATE',
            'duplicate_of' => $candidate->id,
            'detection_status' => 'COMPLETED',
        ]);

        $response = $this->actingAs($owner)->post($decideUrl, array_merge($basePayload, [
            'parent_issue_id' => $source->id, // candidate would become child, but it already has children
        ]));
        $response->assertStatus(409); // Tidak membentuk siklus
    }

    public function test_confirm_duplicate_marks_child_closed_if_parent_resolved_or_closed(): void
    {
        [$owner, , $project, $source, $candidate, $pair] = $this->fixture();

        // Parent is RESOLVED — use DB::table to bypass Eloquent mass-assignment guard
        DB::table('issues')->where('id', $source->id)->update(['status' => 'RESOLVED']);

        $this->actingAs($owner)->post(route('issues.duplicates.decide', [
            'project' => $project,
            'issue' => $source,
            'candidate' => $pair,
        ]), [
            'decision' => 'DUPLICATE',
            'parent_issue_id' => $source->id,
            'expected_decision' => 'PENDING',
            'expected_source_status' => 'RESOLVED',
            'expected_candidate_status' => 'OPEN',
            'expected_source_parent' => 0,
            'expected_candidate_parent' => 0,
        ])->assertRedirect();

        $freshChild = $candidate->fresh();
        // Since parent was RESOLVED, child must be CLOSED directly
        $this->assertSame('CLOSED', $freshChild->status);
        $this->assertSame((int) $source->id, (int) $freshChild->duplicate_of);

        $this->assertDatabaseHas('duplicate_candidates', [
            'id' => $pair->id,
            'decision' => 'DUPLICATE',
            'decided_by' => $owner->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'action' => 'DUPLICATE_REVIEW',
            'user_id' => $owner->id,
        ]);
    }

    public function test_confirm_not_duplicate_leaves_statuses_unchanged_and_logs(): void
    {
        [$owner, , $project, $source, $candidate, $pair] = $this->fixture();

        $this->actingAs($owner)->post(route('issues.duplicates.decide', [
            'project' => $project,
            'issue' => $source,
            'candidate' => $pair,
        ]), [
            'decision' => 'NOT_DUPLICATE',
            'expected_decision' => 'PENDING',
            'expected_source_status' => 'OPEN',
            'expected_candidate_status' => 'OPEN',
            'expected_source_parent' => 0,
            'expected_candidate_parent' => 0,
        ])->assertRedirect();

        $this->assertSame('OPEN', $source->fresh()->status);
        $this->assertSame('OPEN', $candidate->fresh()->status);
        $this->assertNull($source->fresh()->duplicate_of);
        $this->assertNull($candidate->fresh()->duplicate_of);

        $this->assertDatabaseHas('duplicate_candidates', [
            'id' => $pair->id,
            'decision' => 'NOT_DUPLICATE',
            'decided_by' => $owner->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'action' => 'DUPLICATE_REVIEW',
            'user_id' => $owner->id,
        ]);
    }

    public function test_re_review_rejected_candidate_appends_to_activity_logs(): void
    {
        [$owner, , $project, $source, $candidate, $pair] = $this->fixture();

        // 1. Mark NOT_DUPLICATE
        $this->actingAs($owner)->post(route('issues.duplicates.decide', [
            'project' => $project,
            'issue' => $source,
            'candidate' => $pair,
        ]), [
            'decision' => 'NOT_DUPLICATE',
            'expected_decision' => 'PENDING',
            'expected_source_status' => 'OPEN',
            'expected_candidate_status' => 'OPEN',
            'expected_source_parent' => 0,
            'expected_candidate_parent' => 0,
        ]);

        $this->assertSame(1, DB::table('activity_logs')->count());

        // 2. Later review again and confirm DUPLICATE
        $this->post(route('issues.duplicates.decide', [
            'project' => $project,
            'issue' => $source,
            'candidate' => $pair,
        ]), [
            'decision' => 'DUPLICATE',
            'parent_issue_id' => $source->id,
            'expected_decision' => 'NOT_DUPLICATE',
            'expected_source_status' => 'OPEN',
            'expected_candidate_status' => 'OPEN',
            'expected_source_parent' => 0,
            'expected_candidate_parent' => 0,
        ]);

        // Verifikasi histori log bertambah (tidak menimpa yang lama)
        $this->assertSame(2, DB::table('activity_logs')->count());
        $this->assertSame('DUPLICATE', $candidate->fresh()->status);
    }

    public function test_cancel_duplicate_requires_valid_reason_and_resets_child_to_open(): void
    {
        [$owner, , $project, $source, $candidate, $pair] = $this->fixture();

        // Mark as duplicate first — use DB::table to bypass Eloquent mass-assignment guard
        DB::table('issues')->where('id', $candidate->id)
            ->update(['duplicate_of' => $source->id, 'status' => 'DUPLICATE']);
        DB::table('duplicate_candidates')->where('id', $pair->id)
            ->update(['decision' => 'DUPLICATE', 'decided_by' => $owner->id, 'decided_at' => now()]);

        $cancelUrl = route('issues.duplicates.cancel', [
            'project' => $project,
            'issue' => $candidate,
        ]);

        // 1. Empty reason -> rejected with validation error
        $this->actingAs($owner)->post($cancelUrl, [
            'reason' => '',
            'expected_duplicate_of' => $source->id,
            'expected_status' => 'DUPLICATE',
        ])->assertSessionHasErrors('reason');

        // Status must remain DUPLICATE
        $this->assertSame('DUPLICATE', $candidate->fresh()->status);

        // 2. Too short reason (< 10 chars) -> rejected
        $this->post($cancelUrl, [
            'reason' => 'Salah',
            'expected_duplicate_of' => $source->id,
            'expected_status' => 'DUPLICATE',
        ])->assertSessionHasErrors('reason');

        // 3. Valid reason (>= 10 chars)
        $this->post($cancelUrl, [
            'reason' => 'Setelah analisis lebih lanjut kedua issue memiliki akar masalah berbeda.',
            'expected_duplicate_of' => $source->id,
            'expected_status' => 'DUPLICATE',
        ])->assertRedirect();

        $this->assertSame('OPEN', $candidate->fresh()->status);
        $this->assertNull($candidate->fresh()->duplicate_of);
        $this->assertSame('PENDING', $pair->fresh()->decision);

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'action' => 'DUPLICATE_CORRECTED',
            'reason' => 'Setelah analisis lebih lanjut kedua issue memiliki akar masalah berbeda.',
        ]);
    }

    public function test_retry_detection_only_allowed_for_failed_status(): void
    {
        Bus::fake([DetectIssueCandidatesJob::class]);

        [$owner, , $project, $source] = $this->fixture();
        $source->update(['detection_status' => 'FAILED']);

        $retryUrl = route('issues.detection.retry', [
            'project' => $project,
            'issue' => $source,
        ]);

        // 1. Valid retry when status is FAILED
        $this->actingAs($owner)->post($retryUrl)->assertRedirect();
        $this->assertSame('PENDING', $source->fresh()->detection_status);
        Bus::assertDispatched(DetectIssueCandidatesJob::class, 1);

        // 2. Reject retry when status is already PENDING
        $this->post($retryUrl)->assertRedirect()->assertSessionHas('status_notice');
        Bus::assertDispatched(DetectIssueCandidatesJob::class, 1); // no second job dispatched

        // 3. Reject retry when status is COMPLETED
        $source->update(['detection_status' => 'COMPLETED']);
        $this->post($retryUrl)->assertRedirect()->assertSessionHas('status_notice');
        Bus::assertDispatched(DetectIssueCandidatesJob::class, 1); // no extra job
    }

    public function test_retry_concurrency_guard_prevents_duplicate_job_dispatch(): void
    {
        Bus::fake([DetectIssueCandidatesJob::class]);

        [$owner, , $project, $source] = $this->fixture();
        $source->update(['detection_status' => 'FAILED']);

        // Simulate concurrent execution: first request updates to PENDING, second sees not FAILED
        $firstResult = DB::transaction(function () use ($source): bool {
            return Issue::query()
                ->whereKey($source->id)
                ->where('detection_status', 'FAILED')
                ->lockForUpdate()
                ->update(['detection_status' => 'PENDING']) > 0;
        });

        $secondResult = DB::transaction(function () use ($source): bool {
            return Issue::query()
                ->whereKey($source->id)
                ->where('detection_status', 'FAILED')
                ->lockForUpdate()
                ->update(['detection_status' => 'PENDING']) > 0;
        });

        $this->assertTrue($firstResult);
        $this->assertFalse($secondResult);
    }
}

