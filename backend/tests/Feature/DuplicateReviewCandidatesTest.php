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
use Illuminate\Support\Facades\Log;
use Mockery;
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

    public function test_retry_when_failed_dispatches_exactly_one_job_and_sets_status_to_pending(): void
    {
        Bus::fake([DetectIssueCandidatesJob::class]);

        [$owner, , $project, $source] = $this->fixture();
        DB::table('issues')->where('id', $source->id)->update(['detection_status' => 'FAILED']);

        $retryUrl = route('issues.detection.retry', [
            'project' => $project,
            'issue' => $source,
        ]);

        $response = $this->actingAs($owner)->post($retryUrl);
        $response->assertRedirect();
        $response->assertSessionHas('success', 'Deteksi kemiripan issue sedang diproses ulang');

        $this->assertSame('PENDING', $source->fresh()->detection_status);
        Bus::assertDispatchedTimes(DetectIssueCandidatesJob::class, 1);
    }

    public function test_retry_when_already_pending_dispatches_zero_additional_jobs(): void
    {
        Bus::fake([DetectIssueCandidatesJob::class]);

        [$owner, , $project, $source] = $this->fixture();
        DB::table('issues')->where('id', $source->id)->update(['detection_status' => 'PENDING']);

        $retryUrl = route('issues.detection.retry', [
            'project' => $project,
            'issue' => $source,
        ]);

        $response = $this->actingAs($owner)->post($retryUrl);
        $response->assertRedirect();
        $response->assertSessionHas('status_notice', 'Pekerjaan sedang berjalan');

        $this->assertSame('PENDING', $source->fresh()->detection_status);
        Bus::assertNotDispatched(DetectIssueCandidatesJob::class);
    }

    public function test_retry_when_completed_dispatches_zero_jobs_and_preserves_status(): void
    {
        Bus::fake([DetectIssueCandidatesJob::class]);

        [$owner, , $project, $source] = $this->fixture();
        DB::table('issues')->where('id', $source->id)->update(['detection_status' => 'COMPLETED']);

        $retryUrl = route('issues.detection.retry', [
            'project' => $project,
            'issue' => $source,
        ]);

        $response = $this->actingAs($owner)->post($retryUrl);
        $response->assertRedirect();
        $response->assertSessionHas('status_notice', 'Deteksi kemiripan untuk issue ini sudah selesai');

        $this->assertSame('COMPLETED', $source->fresh()->detection_status);
        Bus::assertNotDispatched(DetectIssueCandidatesJob::class);
    }

    public function test_retry_when_processing_dispatches_zero_jobs_and_preserves_status(): void
    {
        Bus::fake([DetectIssueCandidatesJob::class]);

        [$owner, , $project, $source] = $this->fixture();
        DB::table('issues')->where('id', $source->id)->update(['detection_status' => 'PROCESSING']);

        $retryUrl = route('issues.detection.retry', [
            'project' => $project,
            'issue' => $source,
        ]);

        $response = $this->actingAs($owner)->post($retryUrl);
        $response->assertRedirect();
        $response->assertSessionHas('status_notice', 'Pekerjaan sedang berjalan');

        $this->assertSame('PROCESSING', $source->fresh()->detection_status);
        Bus::assertNotDispatched(DetectIssueCandidatesJob::class);
    }

    public function test_retry_dispatch_failure_recovers_to_failed_without_hanging_and_logs_error(): void
    {
        Log::spy();

        [$owner, , $project, $source] = $this->fixture();
        DB::table('issues')->where('id', $source->id)->update(['detection_status' => 'FAILED']);

        $dispatcherMock = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcherMock->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('Queue connection timeout'));
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcherMock);

        $retryUrl = route('issues.detection.retry', [
            'project' => $project,
            'issue' => $source,
        ]);

        $response = $this->actingAs($owner)->post($retryUrl);
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Gagal memproses ulang deteksi NLP. Silakan coba beberapa saat lagi.');

        // Status dipulihkan ke FAILED (tidak menggantung di PENDING)
        $this->assertSame('FAILED', $source->fresh()->detection_status);

        // Error dicatat dengan Log::error menyertakan issue_id, project_id, dan exception message
        Log::shouldHaveReceived('error')
            ->once()
            ->with('Gagal menjadwalkan ulang deteksi NLP.', Mockery::on(function ($context) use ($source, $project) {
                return $context['issue_id'] === $source->id
                    && $context['project_id'] === $project->id
                    && str_contains($context['error'], 'Queue connection timeout');
            }));
    }

    public function test_retry_competing_requests_and_stale_read_race_condition_simulation(): void
    {
        Bus::fake([DetectIssueCandidatesJob::class]);

        [$owner, , $project, $source] = $this->fixture();
        DB::table('issues')->where('id', $source->id)->update(['detection_status' => 'FAILED']);

        $retryUrl = route('issues.detection.retry', [
            'project' => $project,
            'issue' => $source,
        ]);

        // (i) Dua request berurutan ke endpoint yang sama: request 1 berhasil klaim & dispatch, request 2 ditolak
        $firstResponse = $this->actingAs($owner)->post($retryUrl);
        $firstResponse->assertRedirect()->assertSessionHas('success');

        $secondResponse = $this->post($retryUrl);
        $secondResponse->assertRedirect()->assertSessionHas('status_notice');

        // Total job yang dijadwalkan tepat 1
        Bus::assertDispatchedTimes(DetectIssueCandidatesJob::class, 1);

        // (ii) Skenario "stale read": status di DB sudah diubah ke PENDING oleh request lain tepat sebelum eksekusi klaim
        $competingIssue = Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $owner->id,
            'title' => 'Issue Stale Race',
            'description' => 'Deskripsi issue race.',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'FAILED',
        ]);

        // Simulasikan balapan di mana proses lain sudah mengubah status menjadi PENDING terlebih dahulu
        DB::table('issues')->where('id', $competingIssue->id)->update(['detection_status' => 'PENDING']);

        $competingUrl = route('issues.detection.retry', [
            'project' => $project,
            'issue' => $competingIssue,
        ]);

        $competingResponse = $this->post($competingUrl);
        $competingResponse->assertRedirect()->assertSessionHas('status_notice');

        // Job tetap 1 (tidak ada job kedua yang ter-dispatch)
        Bus::assertDispatchedTimes(DetectIssueCandidatesJob::class, 1);
    }

    public function test_retry_rejected_for_guest_reporter_and_non_owner_maintainer_with_zero_jobs(): void
    {
        Bus::fake([DetectIssueCandidatesJob::class]);

        [$owner, $reporter, $project, $source] = $this->fixture();
        DB::table('issues')->where('id', $source->id)->update(['detection_status' => 'FAILED']);
        $otherMaintainer = User::factory()->create(['role' => 'MAINTAINER']);

        $retryUrl = route('issues.detection.retry', [
            'project' => $project,
            'issue' => $source,
        ]);

        // 1. Guest -> redirect ke login
        $this->post($retryUrl)->assertRedirect('/login');
        $this->assertSame('FAILED', $source->fresh()->detection_status);
        Bus::assertNotDispatched(DetectIssueCandidatesJob::class);

        // 2. Reporter di project yang sama -> 403 Forbidden
        $this->actingAs($reporter)->post($retryUrl)->assertForbidden();
        $this->assertSame('FAILED', $source->fresh()->detection_status);
        Bus::assertNotDispatched(DetectIssueCandidatesJob::class);

        // 3. Maintainer dari project lain -> 403 Forbidden
        $this->actingAs($otherMaintainer)->post($retryUrl)->assertForbidden();
        $this->assertSame('FAILED', $source->fresh()->detection_status);
        Bus::assertNotDispatched(DetectIssueCandidatesJob::class);
    }

    public function test_retry_rejected_for_cross_project_issue_with_zero_jobs(): void
    {
        Bus::fake([DetectIssueCandidatesJob::class]);

        [$owner, , $project, $source] = $this->fixture();
        $otherProject = Project::factory()->create(['owner_id' => $owner->id]);
        DB::table('issues')->where('id', $source->id)->update(['detection_status' => 'FAILED']);

        // Issue milik $project tapi diakses via URL $otherProject -> 404 Not Found
        $crossProjectUrl = route('issues.detection.retry', [
            'project' => $otherProject,
            'issue' => $source,
        ]);

        $this->actingAs($owner)->post($crossProjectUrl)->assertNotFound();
        $this->assertSame('FAILED', $source->fresh()->detection_status);
        Bus::assertNotDispatched(DetectIssueCandidatesJob::class);
    }

    public function test_retry_failure_recovery_does_not_overwrite_worker_processing_or_completed_status(): void
    {
        Log::spy();

        [$owner, , $project, $source] = $this->fixture();
        DB::table('issues')->where('id', $source->id)->update(['detection_status' => 'FAILED']);

        // Skenario A: Dispatch melempar exception, namun worker telah mendahului mengubah status ke PROCESSING
        $dispatcherMock = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcherMock->shouldReceive('dispatch')
            ->once()
            ->andReturnUsing(function () use ($source) {
                DB::table('issues')->where('id', $source->id)->update(['detection_status' => 'PROCESSING']);
                throw new \RuntimeException('Simulated worker pickup race failure');
            });
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcherMock);

        $retryUrl = route('issues.detection.retry', [
            'project' => $project,
            'issue' => $source,
        ]);

        $response = $this->actingAs($owner)->post($retryUrl);
        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Status harus tetap PROCESSING, pemulihan bersyarat TIDAK menimpanya kembali ke FAILED
        $this->assertSame('PROCESSING', $source->fresh()->detection_status);

        // Skenario B: Dispatch melempar exception, namun worker telah mendahului mengubah status ke COMPLETED
        $dispatcherMockCompleted = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcherMockCompleted->shouldReceive('dispatch')
            ->once()
            ->andReturnUsing(function () use ($source) {
                DB::table('issues')->where('id', $source->id)->update(['detection_status' => 'COMPLETED']);
                throw new \RuntimeException('Simulated worker completion race failure');
            });
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcherMockCompleted);

        // Reset status ke FAILED untuk pengujian skenario COMPLETED
        DB::table('issues')->where('id', $source->id)->update(['detection_status' => 'FAILED']);
        $this->actingAs($owner)->post($retryUrl);

        // Status harus tetap COMPLETED, tidak tertimpa ke FAILED
        $this->assertSame('COMPLETED', $source->fresh()->detection_status);
    }

    public function test_project_and_issues_navigation_shows_review_link_for_owner_only(): void
    {
        [$owner, $reporter, $project] = $this->fixture();

        // 1. Maintainer pemilik melihat tombol Review Kandidat Duplikat di halaman Project dan Issues
        $this->actingAs($owner)->get(route('projects.show', ['id' => $project->id]))
            ->assertOk()
            ->assertSee(route('projects.duplicates.index', ['project' => $project]))
            ->assertSee('Review Kandidat Duplikat');

        $this->actingAs($owner)->get(route('issues.index', ['project' => $project]))
            ->assertOk()
            ->assertSee(route('projects.duplicates.index', ['project' => $project]))
            ->assertSee('Review Kandidat Duplikat');

        // 2. Reporter tidak melihat tombol Review Kandidat Duplikat
        $this->actingAs($reporter)->get(route('projects.show', ['id' => $project->id]))
            ->assertOk()
            ->assertDontSee('Review Kandidat Duplikat');

        $this->actingAs($reporter)->get(route('issues.index', ['project' => $project]))
            ->assertOk()
            ->assertDontSee('Review Kandidat Duplikat');
    }

    public function test_req05_13_retry_nlp_preserves_manual_decisions_and_filters_invalid_candidates(): void
    {
        [$owner, $reporter, $project, $source] = $this->fixture();

        $c1 = Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $reporter->id,
            'title' => 'Candidate 1',
            'description' => 'Candidate 1 description',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
        ]);
        $c2 = Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $reporter->id,
            'title' => 'Candidate 2',
            'description' => 'Candidate 2 description',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
        ]);
        $c3 = Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $reporter->id,
            'title' => 'Candidate 3',
            'description' => 'Candidate 3 description',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
        ]);

        $otherProject = Project::factory()->create(['owner_id' => $owner->id]);
        $foreignIssue = Issue::forceCreate([
            'project_id' => $otherProject->id,
            'author_id' => $reporter->id,
            'title' => 'Foreign Issue',
            'description' => 'Foreign description',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
        ]);

        // Existing manual decisions:
        DuplicateCandidate::forceCreate([
            'issue_id' => $source->id,
            'candidate_issue_id' => $c1->id,
            'similarity_score' => 0.85,
            'decision' => 'DUPLICATE',
            'decided_by' => $owner->id,
            'decided_at' => now(),
        ]);
        DuplicateCandidate::forceCreate([
            'issue_id' => $source->id,
            'candidate_issue_id' => $c2->id,
            'similarity_score' => 0.80,
            'decision' => 'NOT_DUPLICATE',
            'decided_by' => $owner->id,
            'decided_at' => now(),
        ]);
        DuplicateCandidate::forceCreate([
            'issue_id' => $source->id,
            'candidate_issue_id' => $c3->id,
            'similarity_score' => 0.76,
            'decision' => 'PENDING',
        ]);

        DB::table('issues')->where('id', $source->id)->update(['detection_status' => 'PENDING']);

        $nlpClientMock = Mockery::mock(\App\Services\NlpClient::class);
        $nlpClientMock->shouldReceive('candidates')
            ->once()
            ->andReturn([
                'candidates' => [
                    ['issue_id' => $c1->id, 'similarity' => 0.88],
                    ['issue_id' => $c2->id, 'similarity' => 0.82],
                    ['issue_id' => $c3->id, 'similarity' => 0.91],
                    ['issue_id' => $foreignIssue->id, 'similarity' => 0.98], // foreign project -> must be filtered
                    ['issue_id' => $source->id, 'similarity' => 1.0],       // self -> must be filtered
                    ['issue_id' => 99999, 'similarity' => 0.95],             // non-existent -> must be filtered
                    ['issue_id' => $c1->id, 'similarity' => 0.50],          // low score -> must be filtered
                ],
            ]);

        $job = new DetectIssueCandidatesJob($source->id);
        $job->handle($nlpClientMock);

        // REQ-05.13 Assertions:
        // 1. Manual decisions are PRESERVED
        $pair1 = DuplicateCandidate::where('issue_id', $source->id)->where('candidate_issue_id', $c1->id)->first();
        $this->assertNotNull($pair1);
        $this->assertSame('DUPLICATE', $pair1->decision);

        $pair2 = DuplicateCandidate::where('issue_id', $source->id)->where('candidate_issue_id', $c2->id)->first();
        $this->assertNotNull($pair2);
        $this->assertSame('NOT_DUPLICATE', $pair2->decision);

        // 2. Unreviewed candidate updated
        $pair3 = DuplicateCandidate::where('issue_id', $source->id)->where('candidate_issue_id', $c3->id)->first();
        $this->assertNotNull($pair3);
        $this->assertEqualsWithDelta(0.91, $pair3->similarity_score, 0.001);

        // 3. Invalid candidates (foreign project, self, non-existent) are FILTERED
        $this->assertFalse(DuplicateCandidate::where('issue_id', $source->id)->where('candidate_issue_id', $foreignIssue->id)->exists());
        $this->assertFalse(DuplicateCandidate::where('issue_id', $source->id)->where('candidate_issue_id', $source->id)->exists());
        $this->assertFalse(DuplicateCandidate::where('issue_id', $source->id)->where('candidate_issue_id', 99999)->exists());
    }

    public function test_req05_14_decision_transaction_failure_rolls_back_cleanly_and_shows_actionable_error(): void
    {
        [$owner, , $project, $source, $candidate, $pair] = $this->fixture();

        // Simulate database exception during transaction (e.g. failing during activity log recording)
        $tripped = false;
        DB::beforeExecuting(function ($query, $bindings) use (&$tripped) {
            if (! $tripped && str_contains(strtolower($query), 'insert into') && str_contains($query, 'activity_logs')) {
                $tripped = true;
                throw new \RuntimeException('Simulated disk/DB connection failure during transaction');
            }
        });

        $url = route('issues.duplicates.decide', [
            'project' => $project,
            'issue' => $source,
            'candidate' => $pair,
        ]);

        $response = $this->actingAs($owner)->from(route('projects.duplicates.index', ['project' => $project]))
            ->post($url, [
                'decision' => 'DUPLICATE',
                'parent_issue_id' => $source->id,
                'expected_decision' => 'PENDING',
                'expected_source_status' => 'OPEN',
                'expected_candidate_status' => 'OPEN',
                'expected_source_parent' => 0,
                'expected_candidate_parent' => 0,
            ]);

        // Actionable error message returned to user
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Terjadi kesalahan sistem saat menyimpan keputusan validasi. Silakan muat ulang halaman dan coba lagi.');

        // Assert atomic rollback: no changes persisted
        $this->assertSame('OPEN', $candidate->fresh()->status);
        $this->assertNull($candidate->fresh()->duplicate_of);
        $this->assertSame('PENDING', $pair->fresh()->decision);
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    public function test_compare_page_only_shows_history_for_the_specific_issue_pair(): void
    {
        [$owner, $reporter, $project, $source, $candidate, $pair] = $this->fixture();

        $thirdIssue = Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $reporter->id,
            'title' => 'Issue Ketiga Lainnya',
            'description' => 'Deskripsi issue ketiga',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'vote_count' => 1,
            'detection_status' => 'COMPLETED',
        ]);

        // Log 1: DUPLICATE_REVIEW strictly for pair ($source, $candidate)
        DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'issue_id' => $source->id,
            'action' => 'DUPLICATE_REVIEW',
            'old_values' => json_encode(['issue_id' => $source->id, 'candidate_issue_id' => $candidate->id, 'decision' => 'PENDING']),
            'new_values' => json_encode(['issue_id' => $source->id, 'candidate_issue_id' => $candidate->id, 'decision' => 'NOT_DUPLICATE']),
            'reason' => 'Keputusan valid untuk pasangan sumber-kandidat ini',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        // Log 2: DUPLICATE_REVIEW for a DIFFERENT pair ($source, $thirdIssue)
        DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'issue_id' => $source->id,
            'action' => 'DUPLICATE_REVIEW',
            'old_values' => json_encode(['issue_id' => $source->id, 'candidate_issue_id' => $thirdIssue->id, 'decision' => 'PENDING']),
            'new_values' => json_encode(['issue_id' => $source->id, 'candidate_issue_id' => $thirdIssue->id, 'decision' => 'NOT_DUPLICATE']),
            'reason' => 'Log untuk pasangan ketiga yang TIDAK boleh muncul di compare pasangan pertama',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $compareUrl = route('issues.duplicates.compare', [
            'project' => $project,
            'issue' => $source,
            'candidate' => $pair,
        ]);

        $response = $this->actingAs($owner)->get($compareUrl);
        $response->assertOk();
        $response->assertSee('Keputusan valid untuk pasangan sumber-kandidat ini');
        $response->assertDontSee('Log untuk pasangan ketiga yang TIDAK boleh muncul di compare pasangan pertama');
    }
}

