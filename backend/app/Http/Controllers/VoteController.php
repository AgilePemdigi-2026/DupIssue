<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\IssueVote;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoteController extends Controller
{
    /**
     * Only REPORTER with active membership can Vote and Unvote.
     */
    private function authorizeIssue(
        Request $request,
        Project $project,
        Issue $issue
    ): void {
        $user = $request->user();

        abort_unless(
            $user && $user->role === 'REPORTER',
            403,
            'Hanya Reporter yang dapat melakukan voting.'
        );

        abort_unless(
            $project->memberships()
                ->where('user_id', $user->getKey())
                ->where('is_active', true)
                ->exists(),
            403,
            'Kamu tidak memiliki membership aktif pada project ini.'
        );

        abort_unless(
            (int) $issue->project_id === (int) $project->id,
            404
        );
    }

    /**
     * Lock the requested issue and resolve its valid parent
     * when the requested issue is marked as DUPLICATE.
     */
    private function lockVotingIssue(
        Project $project,
        Issue $issue
    ): Issue {
        $snapshot = Issue::query()
            ->whereKey($issue->getKey())
            ->firstOrFail();

        $issueIds = [$snapshot->getKey()];
        if ($snapshot->status === 'DUPLICATE' && $snapshot->duplicate_of !== null) {
            $issueIds[] = (int) $snapshot->duplicate_of;
        }

        $lockedIssues = Issue::query()
            ->whereIn('id', $issueIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $lockedIssue = $lockedIssues->get($snapshot->getKey());
        abort_unless($lockedIssue !== null, 404);

        abort_unless(
            (int) $lockedIssue->project_id === (int) $project->id,
            404
        );

        if ($lockedIssue->status !== 'DUPLICATE') {
            abort_unless(
                $lockedIssue->duplicate_of === null,
                409,
                'Status issue dan relasi duplikat tidak valid.'
            );

            return $lockedIssue;
        }

        abort_unless(
            $lockedIssue->duplicate_of !== null,
            409,
            'Issue duplikat tidak memiliki parent issue.'
        );

        $parentIssue = $lockedIssues->get((int) $lockedIssue->duplicate_of);

        abort_unless(
            $parentIssue !== null,
            409,
            'Relasi parent issue berubah atau parent tidak ditemukan.'
        );

        abort_unless(
            (int) $parentIssue->project_id === (int) $project->id,
            409,
            'Parent issue tidak ditemukan dalam project ini.'
        );

        abort_unless(
            $parentIssue->duplicate_of === null
                && $parentIssue->status !== 'DUPLICATE',
            409,
            'Parent issue tidak valid atau merupakan duplikat lain.'
        );

        return $parentIssue;
    }

    /**
     * Store a vote for an issue or its valid parent.
     */
    public function store(
        Request $request,
        Project $project,
        Issue $issue
    ): RedirectResponse {
        $this->authorizeIssue($request, $project, $issue);

        $user = $request->user();

        $result = DB::transaction(function () use (
            $project,
            $issue,
            $user
        ): array {
            $votingIssue = $this->lockVotingIssue($project, $issue);

            $alreadyVoted = IssueVote::query()
                ->where('issue_id', $votingIssue->id)
                ->where('user_id', $user->id)
                ->exists();

            if ($alreadyVoted) {
                return [
                    'issue' => $votingIssue,
                    'voted' => false,
                ];
            }

            IssueVote::create([
                'issue_id' => $votingIssue->id,
                'user_id' => $user->id,
            ]);

            $votingIssue->vote_count = $votingIssue->votes()->count();
            $votingIssue->save();

            return [
                'issue' => $votingIssue,
                'voted' => true,
            ];
        }, 3);

        if (! $result['voted']) {
            $message = 'Kamu sudah memberikan vote untuk issue ini.';
            $status = 'info';
        } else {
            $message = 'Vote berhasil diberikan.';
            $status = 'success';
        }

        if ((int) $result['issue']->getKey() !== (int) $issue->getKey()) {
            return redirect()
                ->route('issues.show', [
                    'project' => $project,
                    'issue' => $result['issue'],
                ])
                ->with($status, $message);
        }

        return back()->with($status, $message);
    }

    /**
     * Remove the authenticated user's vote from an issue
     * or its valid parent.
     */
    public function destroy(
        Request $request,
        Project $project,
        Issue $issue
    ): RedirectResponse {
        $this->authorizeIssue($request, $project, $issue);

        $user = $request->user();

        $result = DB::transaction(function () use (
            $project,
            $issue,
            $user
        ): array {
            $votingIssue = $this->lockVotingIssue($project, $issue);

            $vote = IssueVote::query()
                ->where('issue_id', $votingIssue->id)
                ->where('user_id', $user->id)
                ->first();

            if (! $vote) {
                return [
                    'issue' => $votingIssue,
                    'unvoted' => false,
                ];
            }

            $vote->delete();

            $votingIssue->vote_count = $votingIssue->votes()->count();
            $votingIssue->save();

            return [
                'issue' => $votingIssue,
                'unvoted' => true,
            ];
        }, 3);

        if (! $result['unvoted']) {
            $message = 'Kamu belum memberikan vote untuk issue ini.';
            $status = 'info';
        } else {
            $message = 'Vote berhasil dibatalkan.';
            $status = 'success';
        }

        if ((int) $result['issue']->getKey() !== (int) $issue->getKey()) {
            return redirect()
                ->route('issues.show', [
                    'project' => $project,
                    'issue' => $result['issue'],
                ])
                ->with($status, $message);
        }

        return back()->with($status, $message);
    }
}
