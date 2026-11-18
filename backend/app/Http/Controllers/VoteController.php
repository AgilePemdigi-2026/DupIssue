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
     * when the requested issue has a duplicate_of link (including CLOSED children).
     */
    private function lockVotingIssue(
        Project $project,
        Issue $issue
    ): Issue {
        // Route binding resolved $issue before the transaction. Do not perform
        // a plain SELECT inside the transaction before acquiring row locks:
        // InnoDB REPEATABLE READ would establish a stale consistent-read snapshot.
        $issueIds = [(int) $issue->getKey()];
        if ($issue->duplicate_of !== null) {
            $issueIds[] = (int) $issue->duplicate_of;
        }

        $lockedIssues = Issue::query()
            ->whereIn('id', $issueIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $lockedIssue = $lockedIssues->get((int) $issue->getKey());
        abort_unless($lockedIssue !== null, 404);

        // Recheck the locked row against the route-bound copy. If a concurrent
        // status review changed its parent, do not proceed with an incomplete
        // lock set or accidentally vote for the old parent.
        abort_unless(
            ($lockedIssue->duplicate_of === null ? null : (int) $lockedIssue->duplicate_of)
                === ($issue->duplicate_of === null ? null : (int) $issue->duplicate_of),
            409,
            'Relasi parent issue berubah, silakan ulangi permintaan.'
        );

        abort_unless(
            (int) $lockedIssue->project_id === (int) $project->id,
            404
        );

        if ($lockedIssue->duplicate_of === null) {
            abort_if(
                $lockedIssue->status === 'DUPLICATE',
                409,
                'Issue duplikat tidak memiliki parent issue.'
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

    /** REQ-01.18: audit only committed, effective Vote/Unvote changes. */
    private function logVoteChange(
        Project $project,
        Issue $issue,
        int $userId,
        string $action,
        int $oldCount,
        int $newCount
    ): void {
        DB::table('activity_logs')->insert([
            'project_id' => $project->getKey(),
            'user_id' => $userId,
            'issue_id' => $issue->getKey(),
            'action' => $action,
            'old_values' => json_encode(['vote_count' => $oldCount], JSON_THROW_ON_ERROR),
            'new_values' => json_encode(['vote_count' => $newCount], JSON_THROW_ON_ERROR),
            'reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
                ->where('voter_id', $user->id)
                ->exists();

            if ($alreadyVoted) {
                return [
                    'issue' => $votingIssue,
                    'voted' => false,
                ];
            }

            $oldCount = (int) $votingIssue->vote_count;
            IssueVote::create([
                'issue_id' => $votingIssue->id,
                'voter_id' => $user->id,
            ]);

            $votingIssue->vote_count = $votingIssue->votes()->count();
            $votingIssue->save();
            $this->logVoteChange($project, $votingIssue, (int) $user->id,
                'VOTE_ADDED', $oldCount, (int) $votingIssue->vote_count);

            return [
                'issue' => $votingIssue,
                'voted' => true,
            ];
        }, 3);

        if (! $result['voted']) {
            $message = 'Vote sudah aktif';
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
                ->where('voter_id', $user->id)
                ->first();

            if (! $vote) {
                return [
                    'issue' => $votingIssue,
                    'unvoted' => false,
                ];
            }

            $oldCount = (int) $votingIssue->vote_count;
            $vote->delete();

            $votingIssue->vote_count = $votingIssue->votes()->count();
            $votingIssue->save();
            $this->logVoteChange($project, $votingIssue, (int) $user->id,
                'VOTE_REMOVED', $oldCount, (int) $votingIssue->vote_count);

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
