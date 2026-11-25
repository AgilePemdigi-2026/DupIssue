<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use App\Services\VoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VoteController extends Controller
{
    public function __construct(private readonly VoteService $votes)
    {
    }

    /**
     * Store a vote for an issue or its valid parent.
     */
    public function store(
        Request $request,
        Project $project,
        Issue $issue
    ): RedirectResponse {
        $result = $this->votes->execute($request->user(), $project, $issue, true);

        if (! $result['changed']) {
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
        $result = $this->votes->execute($request->user(), $project, $issue, false);

        if (! $result['changed']) {
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
