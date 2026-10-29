<?php

namespace App\Http\Controllers;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\Issue;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class IssueController extends Controller
{
    public function __construct(private readonly ProjectService $projects)
    {
    }

    private function authorizeReporter(Request $request, Project $project): void
    {
        $user = $request->user();
        if (! $user || $user->role !== 'REPORTER' || ! $this->projects->canAccessProject($user, $project)) {
            abort(403, 'Akses tidak tersedia');
        }

        // canAccessProject juga mengizinkan owner; submit tetap memerlukan membership aktif.
        abort_unless($project->memberships()
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->exists(), 403, 'Akses tidak tersedia');
    }

    public function create(Request $request, Project $project): View
    {
        $this->authorizeReporter($request, $project);

        return view('issues.create', compact('project'));
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeReporter($request, $project);

        $validated = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
        ]);

        $issue = DB::transaction(function () use ($request, $project, $validated): Issue {
            $issue = new Issue($validated);
            // Field terkait keamanan dan status selalu ditentukan oleh server.
            $issue->project()->associate($project);
            $issue->author()->associate($request->user());
            $issue->source_type = 'FORM';
            $issue->status = 'OPEN';
            $issue->detection_status = 'PENDING';
            $issue->vote_count = 0;
            $issue->duplicate_of = null;
            $issue->save();

            // Callback dijalankan setelah COMMIT; kegagalan antrean tidak menghapus issue.
            DB::afterCommit(function () use ($issue): void {
                try {
                    // Job asli Valentino wajib queued; jangan pernah menjalankan NLP inline.
                    if (! is_subclass_of(DetectIssueCandidatesJob::class, ShouldQueue::class)) {
                        throw new LogicException('DetectIssueCandidatesJob must be integrated and implement ShouldQueue.');
                    }
                    if (config('queue.connections.database.driver') !== 'database') {
                        throw new LogicException('NLP detection requires the database queue driver.');
                    }

                    $job = (new DetectIssueCandidatesJob($issue->getKey()))
                        ->onConnection('database')
                        ->onQueue('nlp');
                    Bus::dispatch($job);
                } catch (Throwable $exception) {
                    $statusException = null;
                    try {
                        Issue::whereKey($issue->id)->where('detection_status', 'PENDING')
                            ->update(['detection_status' => 'FAILED']);
                    } catch (Throwable $failure) {
                        // The issue is already committed. Do not turn a secondary
                        // outage into a failed submit response that invites resubmission.
                        $statusException = $failure;
                    }
                    Log::error('Cannot enqueue issue NLP detection', [
                        'issue_id' => $issue->id,
                        'exception_type' => get_class($exception),
                        'status_exception_type' => $statusException ? get_class($statusException) : null,
                    ]);
                }
            });

            return $issue;
        });

        return redirect()->route('issues.show', ['project' => $project, 'issue' => $issue])
            ->with('success', 'Issue berhasil dibuat');
    }

    public function show(Request $request, Project $project, Issue $issue): View
    {
        $user = $request->user();
        abort_unless($user && $this->projects->canAccessProject($user, $project), 403, 'Akses tidak tersedia');
        if ($user->role === 'REPORTER') {
            $this->authorizeReporter($request, $project);
        }

        if ((int) $issue->project_id !== (int) $project->id) {
            abort(404);
        }

        return view('issues.show', compact('project', 'issue'));
    }
}
