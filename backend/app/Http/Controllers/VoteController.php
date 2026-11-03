<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\IssueVote;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoteController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects
    ) {
    }

    /**
     * Check whether the authenticated user can access the issue.
     */
    private function authorizeIssue(
        Request $request,
        Project $project,
        Issue $issue
    ): void {
        $user = $request->user();

        abort_unless(
            $user && $this->projects->canAccessProject($user, $project),
            403,
            'Akses tidak tersedia'
        );

        // Keep the same Reporter access rule as IssueController.
        if ($user->role === 'REPORTER') {
            abort_unless(
                $project->memberships()
                    ->where('user_id', $user->getKey())
                    ->where('is_active', true)
                    ->exists(),
                403,
                'Akses tidak tersedia'
            );
        }

        abort_unless(
            (int) $issue->project_id === (int) $project->id,
            404
        );
    }

    /**
     * Store a vote for an issue.
     */
    public function store(
        Request $request,
        Project $project,
        Issue $issue
    ): RedirectResponse {
        $this->authorizeIssue($request, $project, $issue);

        $user = $request->user();

        $voted = DB::transaction(function () use ($issue, $user): bool {
            // Lock the issue row to serialize vote changes for this issue.
            $lockedIssue = Issue::query()
                ->whereKey($issue->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // A user may only vote once for the same issue.
            $alreadyVoted = IssueVote::query()
                ->where('issue_id', $lockedIssue->id)
                ->where('user_id', $user->id)
                ->exists();

            if ($alreadyVoted) {
                return false;
            }

            IssueVote::create([
                'issue_id' => $lockedIssue->id,
                'user_id' => $user->id,
            ]);

            $lockedIssue->increment('vote_count');

            return true;
        });

        if (! $voted) {
            return back()->with('info', 'Kamu sudah memberikan vote untuk issue ini.');
        }

        return back()->with('success', 'Vote berhasil diberikan.');
    }

    /**
     * Remove the authenticated user's vote.
     */
    public function destroy(
        Request $request,
        Project $project,
        Issue $issue
    ): RedirectResponse {
        $this->authorizeIssue($request, $project, $issue);

        $user = $request->user();

        $unvoted = DB::transaction(function () use ($issue, $user): bool {
            // Lock the issue row before changing its vote count.
            $lockedIssue = Issue::query()
                ->whereKey($issue->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $vote = IssueVote::query()
                ->where('issue_id', $lockedIssue->id)
                ->where('user_id', $user->id)
                ->first();

            if (! $vote) {
                return false;
            }

            $vote->delete();

            $lockedIssue->decrement('vote_count');

            return true;
        });

        if (! $unvoted) {
            return back()->with('info', 'Kamu belum memberikan vote untuk issue ini.');
        }

        return back()->with('success', 'Vote berhasil dibatalkan.');
    }
}
