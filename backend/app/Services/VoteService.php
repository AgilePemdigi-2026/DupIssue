<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\IssueVote;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

class VoteService
{
    /**
     * A null desired state reads the current vote without changing any records.
     * The optional access guard runs under the same transaction before and after
     * voting, so an extension authorization failure rolls back every change.
     *
     * @param  (Closure(): void)|null  $authorize
     * @return array{issue: Issue, voted: bool, changed: bool, vote_count: int}
     */
    public function execute(
        ?User $actor,
        Project $project,
        Issue $issue,
        ?bool $desiredVote,
        ?Closure $authorize = null
    ): array {
        abort_unless($actor && $actor->role === 'REPORTER', 403,
            'Hanya Reporter yang dapat melakukan voting.');

        return DB::transaction(function () use ($actor, $project, $issue, $desiredVote, $authorize): array {
            // Match repository replacement and membership removal: project first.
            // All authorization reads here are locking/current reads.
            $lockedProject = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $user = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($user && $user->role === 'REPORTER', 403,
                'Hanya Reporter yang dapat melakukan voting.');

            $membership = Membership::query()
                ->where('project_id', $lockedProject->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()->first();
            abort_unless($membership && $membership->is_active, 403,
                'Kamu tidak memiliki membership aktif pada project ini.');
            abort_unless((int) $issue->project_id === (int) $lockedProject->id, 404);

            $authorize?->__invoke();
            $votingIssue = $this->lockVotingIssue($lockedProject, $issue);

            // A locking read avoids an old InnoDB repeatable-read snapshot,
            // including when an extension guard performed a mapping query.
            $votes = $votingIssue->votes()->orderBy('id')->lockForUpdate()->get();
            $vote = $votes->firstWhere('voter_id', $user->id);
            $hasVoted = $vote !== null;
            $changed = $desiredVote !== null && $desiredVote !== $hasVoted;
            $count = $votes->count();

            if ($changed) {
                $oldCount = (int) $votingIssue->vote_count;
                if ($desiredVote) {
                    IssueVote::create([
                        'issue_id' => $votingIssue->id,
                        'voter_id' => $user->id,
                    ]);
                    $count++;
                } else {
                    $vote->delete();
                    $count--;
                }

                // The target issue lock serializes web and extension writers.
                // Reconcile the cached count from actual locked vote records.
                $votingIssue->vote_count = $count;
                $votingIssue->save();
                $this->logVoteChange($lockedProject, $votingIssue, (int) $user->id,
                    $desiredVote ? 'VOTE_ADDED' : 'VOTE_REMOVED', $oldCount, $count);
                $hasVoted = $desiredVote;
            }

            // In particular, a credential can expire while waiting on issue locks.
            $authorize?->__invoke();

            return [
                'issue' => $votingIssue,
                'voted' => $hasVoted,
                'changed' => $changed,
                'vote_count' => $count,
            ];
        }, 3);
    }

    /**
     * Lock the requested issue and resolve its valid parent
     * when the requested issue has a duplicate_of link (including CLOSED children).
     */
    private function lockVotingIssue(
        Project $project,
        Issue $issue
    ): Issue {
        // Web binding or the extension adapter resolved the issue before the
        // transaction. Use current locking reads for both the issue and votes;
        // ordinary reads could use an older InnoDB REPEATABLE READ snapshot.
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

}
