<?php

namespace App\Http\Controllers;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\DuplicateCandidate;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class DuplicateReviewController extends Controller
{
    private function authorizeOwner(Request $request, Project $project): void
    {
        $user = $request->user();
        abort_unless($user && $user->role === 'MAINTAINER'
            && (int) $project->owner_id === (int) $user->id,
            403, 'Hanya Maintainer pemilik project yang dapat memvalidasi issue');
    }

    /**
     * Menampilkan daftar kandidat duplikat terpusat untuk sebuah project (REQ-05.1, REQ-05.2).
     */
    public function index(Request $request, Project $project)
    {
        $this->authorizeOwner($request, $project);

        $candidates = DuplicateCandidate::query()
            ->whereHas('issue', function ($query) use ($project) {
                $query->where('project_id', $project->id);
            })
            ->with(['issue.author', 'candidateIssue.author', 'decidedBy'])
            ->orderByDesc('similarity_score')
            ->get();

        $failedIssues = Issue::query()
            ->where('project_id', $project->id)
            ->where('detection_status', 'FAILED')
            ->with('author')
            ->orderByDesc('id')
            ->get();

        return view('duplicates.index', compact('project', 'candidates', 'failedIssues'));
    }

    /**
     * Menampilkan perbandingan dua issue berdampingan sebelum validasi (REQ-05.3, REQ-05.6).
     */
    public function compare(Request $request, Project $project, Issue $issue, DuplicateCandidate $candidate)
    {
        // Cek kepemilikan resource (404) sebelum otorisasi (403) agar cross-project
        // request tidak bocorkan informasi eksistensi resource.
        abort_unless((int) $issue->project_id === (int) $project->id
            && (int) $candidate->issue_id === (int) $issue->id, 404);
        $this->authorizeOwner($request, $project);

        $candidate->load(['issue.author', 'candidateIssue.author', 'decidedBy']);
        $source = $candidate->issue;
        $suggested = $candidate->candidateIssue;

        abort_unless((int) $source->project_id === (int) $project->id
            && (int) $suggested->project_id === (int) $project->id, 404);

        // Rekomendasi issue induk berdasarkan vote terbanyak
        $suggestedParentId = ((int) $source->vote_count >= (int) $suggested->vote_count)
            ? (int) $source->id
            : (int) $suggested->id;

        $pairHistory = DB::table('activity_logs')
            ->where('project_id', $project->id)
            ->whereIn('issue_id', [$source->id, $suggested->id])
            ->whereIn('action', ['DUPLICATE_REVIEW', 'DUPLICATE_CORRECTED'])
            ->orderByDesc('created_at')
            ->get();

        return view('duplicates.compare', compact(
            'project', 'issue', 'candidate', 'source', 'suggested', 'suggestedParentId', 'pairHistory'
        ));
    }

    public function decide(
        Request $request,
        Project $project,
        Issue $issue,
        DuplicateCandidate $candidate
    ): RedirectResponse {
        $this->authorizeOwner($request, $project);
        abort_unless((int) $issue->project_id === (int) $project->id
            && (int) $candidate->issue_id === (int) $issue->id, 404);

        $input = $request->validate([
            'decision' => ['required', Rule::in(['DUPLICATE', 'NOT_DUPLICATE'])],
            'parent_issue_id' => ['required_if:decision,DUPLICATE', 'nullable', 'integer'],
            'expected_decision' => ['required', Rule::in(['PENDING', 'DUPLICATE', 'NOT_DUPLICATE'])],
            'expected_source_status' => ['required', 'string'],
            'expected_candidate_status' => ['required', 'string'],
            'expected_source_parent' => ['required', 'integer'],
            'expected_candidate_parent' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($request, $project, $issue, $candidate, $input): void {
            // Lock both issues in ID order to avoid reversing lock order across concurrent reviews.
            $ids = [(int) $issue->id, (int) $candidate->candidate_issue_id];
            $issues = Issue::query()->whereIn('id', $ids)->orderBy('id')
                ->lockForUpdate()->get()->keyBy('id');
            abort_unless($issues->count() === 2, 404);
            $source = $issues->get((int) $issue->id);
            $suggested = $issues->get((int) $candidate->candidate_issue_id);
            abort_unless((int) $source->project_id === (int) $project->id
                && (int) $suggested->project_id === (int) $project->id, 404, 'Harus satu project');

            $lockedCandidate = DuplicateCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            abort_unless((int) $lockedCandidate->issue_id === (int) $source->id
                && (int) $lockedCandidate->candidate_issue_id === (int) $suggested->id, 404);
            abort_unless($source->detection_status === 'COMPLETED', 409,
                'Tunggu deteksi NLP selesai sebelum memvalidasi pasangan');

            // Optimistic state check (in addition to row locks). Reject stale browser forms.
            abort_unless($lockedCandidate->decision === $input['expected_decision']
                && $source->status === $input['expected_source_status']
                && $suggested->status === $input['expected_candidate_status']
                && (int) ($source->duplicate_of ?? 0) === (int) $input['expected_source_parent']
                && (int) ($suggested->duplicate_of ?? 0) === (int) $input['expected_candidate_parent'],
                409, 'Data issue telah berubah. Muat ulang halaman sebelum memvalidasi');

            $before = $this->state($lockedCandidate, $source, $suggested);
            if ($input['decision'] === 'DUPLICATE') {
                abort_unless($lockedCandidate->decision !== 'DUPLICATE', 409,
                    'Pasangan sudah dikonfirmasi sebagai duplikat');
                $parentId = (int) $input['parent_issue_id'];
                abort_unless(in_array($parentId, $ids, true), 422, 'Induk harus salah satu issue dalam pasangan');
                $parent = $parentId === (int) $source->id ? $source : $suggested;
                $child = $parentId === (int) $source->id ? $suggested : $source;

                abort_if((int) $parent->id === (int) $child->id, 422, 'Induk harus berbeda');
                abort_unless((int) $parent->project_id === (int) $child->project_id, 422, 'Harus satu project');

                abort_unless($parent->duplicate_of === null && $parent->status !== 'DUPLICATE',
                    409, 'Induk bukan duplikat');
                abort_unless($child->duplicate_of === null
                    && in_array($child->status, ['OPEN', 'IN_PROGRESS'], true),
                    409, 'Issue duplikat harus OPEN/IN_PROGRESS dan belum mempunyai induk');
                abort_unless(! Issue::query()->where('duplicate_of', $child->id)->exists(),
                    409, 'Tidak membentuk siklus');

                $child->duplicate_of = $parent->id;
                $child->status = in_array($parent->status, ['RESOLVED', 'CLOSED'], true)
                    ? 'CLOSED' : 'DUPLICATE';
                $child->save();
            } else {
                // A confirmed relationship must be undone with a mandatory correction reason.
                abort_unless($lockedCandidate->decision !== 'DUPLICATE', 409,
                    'Batalkan penandaan duplikat dengan alasan koreksi terlebih dahulu');
            }

            $lockedCandidate->decision = $input['decision'];
            $lockedCandidate->decided_by = $request->user()->id;
            $lockedCandidate->decided_at = now();
            $lockedCandidate->save();
            $this->record($project, $request, $source, 'DUPLICATE_REVIEW',
                $before, $this->state($lockedCandidate, $source, $suggested));
        }, 3);

        return redirect()->route('issues.show', ['project' => $project, 'issue' => $issue])
            ->with('success', 'Keputusan validasi duplikat berhasil disimpan');
    }

    public function cancel(Request $request, Project $project, Issue $issue): RedirectResponse
    {
        $this->authorizeOwner($request, $project);
        abort_unless((int) $issue->project_id === (int) $project->id, 404);
        $input = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'expected_duplicate_of' => ['required', 'integer', 'min:1'],
            'expected_status' => ['required', 'string'],
        ], [
            'reason.required' => 'Alasan Koreksi wajib diisi',
            'reason.min' => 'Alasan Koreksi minimal 10 karakter',
            'reason.max' => 'Alasan Koreksi maksimal 500 karakter',
        ]);

        DB::transaction(function () use ($request, $project, $issue, $input): void {
            $locked = Issue::query()->lockForUpdate()->findOrFail($issue->id);
            abort_unless((int) $locked->project_id === (int) $project->id, 404);
            abort_unless($locked->duplicate_of !== null, 409,
                'Issue ini tidak memiliki penandaan duplikat aktif');
            abort_unless((int) $locked->duplicate_of === (int) $input['expected_duplicate_of']
                && $locked->status === $input['expected_status'], 409,
                'Penandaan sudah berubah. Muat ulang sebelum membatalkan');

            $priorParent = (int) $locked->duplicate_of;
            $before = ['status' => $locked->status, 'duplicate_of' => $priorParent];
            $locked->duplicate_of = null;
            $locked->status = 'OPEN';
            $locked->save();

            // Candidate pairs can be stored in either direction depending on the chosen parent.
            $pair = DuplicateCandidate::query()
                ->where(function ($query) use ($locked, $priorParent): void {
                    $query->where('issue_id', $locked->id)
                        ->where('candidate_issue_id', $priorParent);
                })->orWhere(function ($query) use ($locked, $priorParent): void {
                    $query->where('issue_id', $priorParent)
                        ->where('candidate_issue_id', $locked->id);
                })->lockForUpdate()->first();
            if ($pair && $pair->decision === 'DUPLICATE') {
                $pair->decision = 'PENDING';
                $pair->decided_by = $request->user()->id;
                $pair->decided_at = now();
                $pair->save();
            }
            $this->record($project, $request, $locked, 'DUPLICATE_CORRECTED',
                $before, ['status' => 'OPEN', 'duplicate_of' => null], $input['reason']);
        }, 3);

        return redirect()->route('issues.show', ['project' => $project, 'issue' => $issue])
            ->with('success', 'Penandaan duplikat dibatalkan dan alasannya dicatat');
    }

    /**
     * Memproses ulang deteksi NLP saat status deteksi FAILED (REQ-05.12, REQ-05.13).
     *
     * Kegagalan dispatch ditangani secara aman dengan klaim atomik bersyarat
     * dan pemulihan status bersyarat (Pendekatan B) agar tidak menggantung di PENDING
     * dan tidak menimpa status PROCESSING/COMPLETED yang ditulis worker lain.
     */
    public function retry(Request $request, Project $project, Issue $issue): RedirectResponse
    {
        $this->authorizeOwner($request, $project);
        abort_unless((int) $issue->project_id === (int) $project->id, 404);

        // 1. Klaim atomik bersyarat: update status hanya jika saat ini FAILED
        $affected = Issue::query()
            ->where('id', $issue->id)
            ->where('project_id', $project->id)
            ->where('detection_status', 'FAILED')
            ->update(['detection_status' => 'PENDING']);

        if ($affected === 0) {
            $currentStatus = Issue::query()
                ->where('id', $issue->id)
                ->where('project_id', $project->id)
                ->value('detection_status');

            if (in_array($currentStatus, ['PENDING', 'PROCESSING'], true)) {
                return redirect()->back()->with('status_notice', 'Pekerjaan sedang berjalan');
            }

            if ($currentStatus === 'COMPLETED') {
                return redirect()->back()->with('status_notice', 'Deteksi kemiripan untuk issue ini sudah selesai');
            }

            return redirect()->back()->with('status_notice', 'Pekerjaan sedang berjalan');
        }

        // 2. Dispatch aman dengan try/catch (Pendekatan B: pemulihan bersyarat)
        try {
            DetectIssueCandidatesJob::dispatch($issue->id);

            return redirect()->back()->with('success', 'Deteksi kemiripan issue sedang diproses ulang');
        } catch (\Throwable $e) {
            // Pemulihan bersyarat: hanya kembalikan ke FAILED jika status masih PENDING.
            // Wajib bersyarat agar tidak menimpa status PROCESSING/COMPLETED yang sudah ditulis worker lain.
            Issue::query()
                ->where('id', $issue->id)
                ->where('project_id', $project->id)
                ->where('detection_status', 'PENDING')
                ->update(['detection_status' => 'FAILED']);

            Log::error('Gagal menjadwalkan ulang deteksi NLP.', [
                'issue_id' => $issue->id,
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Gagal memproses ulang deteksi NLP. Silakan coba beberapa saat lagi.');
        }
    }

    private function state(DuplicateCandidate $pair, Issue $source, Issue $suggested): array
    {
        return [
            'candidate_id' => $pair->id,
            'issue_id' => $source->id,
            'candidate_issue_id' => $suggested->id,
            'decision' => $pair->decision,
            'source_status' => $source->status,
            'source_duplicate_of' => $source->duplicate_of,
            'candidate_status' => $suggested->status,
            'candidate_duplicate_of' => $suggested->duplicate_of,
        ];
    }

    private function record(
        Project $project, Request $request, Issue $issue,
        string $action, array $before, array $after, ?string $reason = null
    ): void {
        DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'issue_id' => $issue->id,
            'action' => $action,
            'old_values' => json_encode($before, JSON_THROW_ON_ERROR),
            'new_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
