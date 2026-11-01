<?php

namespace App\Http\Controllers;

use App\Models\DuplicateCandidate;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                && (int) $suggested->project_id === (int) $project->id, 404);

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

                abort_unless($parent->duplicate_of === null && $parent->status !== 'DUPLICATE',
                    409, 'Issue induk tidak boleh merupakan duplikat lain');
                abort_unless($child->duplicate_of === null
                    && in_array($child->status, ['OPEN', 'IN_PROGRESS'], true),
                    409, 'Issue duplikat harus OPEN/IN_PROGRESS dan belum mempunyai induk');
                abort_unless(! Issue::query()->where('duplicate_of', $child->id)->exists(),
                    409, 'Issue yang sudah menjadi induk duplikat lain tidak boleh dijadikan anak');

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
