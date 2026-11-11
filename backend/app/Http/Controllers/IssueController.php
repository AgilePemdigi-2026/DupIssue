<?php

namespace App\Http\Controllers;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\DuplicateCandidate;
use App\Models\Issue;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
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

    public function index(Request $request, Project $project): View
    {
        $user = $request->user();
        abort_unless($user && $this->projects->canAccessProject($user, $project), 403, 'Akses tidak tersedia');
        if ($user->role === 'REPORTER') {
            $this->authorizeReporter($request, $project);
        }

        // GET-only read model for REQ-02. The project scope is mandatory.
        $allowedStatuses = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED', 'DUPLICATE'];
        $validated = $request->validate([
            'cari' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in($allowedStatuses)],
        ]);
        $cari = trim($validated['cari'] ?? '');
        $status = $validated['status'] ?? '';

        $issues = Issue::query()
            ->where('project_id', $project->getKey())
            ->with(['parentIssue' => static fn ($query) => $query
                ->where('project_id', $project->getKey())])
            ->withCount(['childDuplicates as duplicate_count' => static fn ($query) => $query
                ->where('project_id', $project->getKey())])
            ->when($cari !== '', static function ($query) use ($cari): void {
                $query->where(static function ($nested) use ($cari): void {
                    $nested->where('title', 'like', '%'.$cari.'%')
                        ->orWhere('description', 'like', '%'.$cari.'%');
                });
            })
            ->when($status !== '', static fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('issues.index', compact('project', 'issues', 'cari', 'status', 'allowedStatuses'));
    }

    public function show(Request $request, Project $project, Issue $issue): View|Response
    {
        $user = $request->user();
        abort_unless($user && $this->projects->canAccessProject($user, $project), 403, 'Akses tidak tersedia');
        if ($user->role === 'REPORTER') {
            $this->authorizeReporter($request, $project);
        }

        if ((int) $issue->project_id !== (int) $project->id) {
            return response()->view('issues.not-found', compact('project'), 404);
        }

        // Ensure a malformed or foreign-project parent link cannot be followed.
        $parentIssue = $issue->duplicate_of === null ? null : Issue::query()
            ->whereKey($issue->duplicate_of)
            ->where('project_id', $project->getKey())
            ->first();

        // Additive Issue Voting integration; keep main's project scoping and status history.
        $votingIssue = $issue->duplicate_of === null ? $issue : $parentIssue;
        $canVote = $user->role === 'REPORTER'
            && $project->memberships()
                ->where('user_id', $user->getKey())
                ->where('is_active', true)
                ->exists()
            && $votingIssue !== null
            && $votingIssue->duplicate_of === null
            && $votingIssue->status !== 'DUPLICATE';
        $hasVoted = $canVote
            ? $votingIssue->votes()->where('voter_id', $user->getKey())->exists()
            : false;

        // Only display recommendations for issues belonging to this project.
        // The show action above already enforces project access for the viewer.
        $recommendations = DuplicateCandidate::query()
            ->where('issue_id', $issue->getKey())
            ->whereHas('candidateIssue', fn ($query) => $query->where('project_id', $project->getKey()))
            ->with('candidateIssue')
            ->orderByDesc('similarity_score')
            ->limit(10)
            ->get();

        $reviewHistory = collect();
        if ($user->role === 'MAINTAINER' && (int) $project->owner_id === (int) $user->id) {
            $reviewHistory = DB::table('activity_logs')
                ->where('project_id', $project->id)->where('issue_id', $issue->id)
                ->whereIn('action', ['DUPLICATE_REVIEW', 'DUPLICATE_CORRECTED'])
                ->orderByDesc('id')->limit(20)->get();
        }

        $statusHistory = $this->statusHistory($project, $issue);

        return view('issues.show', compact(
            'project', 'issue', 'recommendations', 'reviewHistory', 'statusHistory',
            'parentIssue', 'votingIssue', 'canVote', 'hasVoted'
        ));
    }

    /** Read-only, project-scoped status transitions for HTML and JSON. */
    private function statusHistory(Project $project, Issue $issue): \Illuminate\Support\Collection
    {
        return DB::table('activity_logs')
            ->where('project_id', $project->getKey())
            ->where('issue_id', $issue->getKey())
            ->whereIn('action', ['ISSUE_STATUS_CHANGED', 'DUPLICATE_AUTO_CLOSED'])
            ->orderByDesc('id')
            ->limit(20)
            ->get(['action', 'created_at', 'reason', 'old_values', 'new_values'])
            ->map(static function (object $event): object {
                $before = json_decode((string) ($event->old_values ?? '{}'), true);
                $after = json_decode((string) ($event->new_values ?? '{}'), true);
                return (object) [
                    'action' => $event->action,
                    'created_at' => $event->created_at,
                    'reason' => $event->reason,
                    'from_status' => is_array($before) && is_string($before['status'] ?? null)
                        ? $before['status'] : null,
                    'to_status' => is_array($after) && is_string($after['status'] ?? null)
                        ? $after['status'] : null,
                ];
            });
    }

    /**
     * REQ-02.7–02.8: refresh current status without mutating the issue.
     * Called by the detail page through a same-origin GET request.
     */
    public function readStatus(Request $request, Project $project, Issue $issue): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->projects->canAccessProject($user, $project), 403, 'Akses tidak tersedia');
        if ($user->role === 'REPORTER') {
            $this->authorizeReporter($request, $project);
        }

        if ((int) $issue->project_id !== (int) $project->id) {
            return response()->json(['message' => 'Issue tidak tersedia'], 404);
        }

        $statusHistory = $this->statusHistory($project, $issue);

        // Never send the ID of a foreign-project parent through the refresh API.
        $parent = $issue->duplicate_of === null ? null : Issue::query()
            ->whereKey($issue->duplicate_of)
            ->where('project_id', $project->getKey())
            ->first();
        $parentRelation = $issue->duplicate_of === null
            ? 'none' : ($parent ? 'valid:'.$parent->getKey() : 'invalid');

        return response()->json([
            'issue_id' => (int) $issue->getKey(),
            'status' => $issue->status,
            'detection_status' => $issue->detection_status,
            'vote_count' => (int) $issue->vote_count,
            'updated_at' => $issue->updated_at?->toIso8601String(),
            'parent_relation' => $parentRelation,
            'history' => $statusHistory,
        ]);
    }
}
