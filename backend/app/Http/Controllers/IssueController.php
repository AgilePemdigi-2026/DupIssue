<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Vote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class IssueController extends Controller
{
    /**
     * Memeriksa peran Reporter dan keanggotaan aktif pada project (REQ-01.1 & REQ-07.3)
     */
    protected function ensureActiveReporter(int $projectId): void
    {
        $user = Auth::user();

        if (!$user) {
            abort(403, 'Akses tidak tersedia');
        }

        // Validasi role bersama kontrak Joshua (jika kolom role tersedia)
        if (isset($user->role) && strtoupper($user->role) !== 'REPORTER') {
            abort(403, 'Akses tidak tersedia');
        }

        // Validasi membership aktif bersama kontrak Reza (jika tabel memberships tersedia)
        if (Schema::hasTable('memberships')) {
            $hasRows = DB::table('memberships')->where('project_id', $projectId)->exists();
            if ($hasRows) {
                $isActive = DB::table('memberships')
                    ->where('project_id', $projectId)
                    ->where('user_id', $user->id)
                    ->where('is_active', 1)
                    ->exists();

                if (!$isActive) {
                    abort(403, 'Akses tidak tersedia');
                }
            }
        }
    }

    /**
     * Mencatat aktivitas ke tabel activity_logs jika sudah tersedia (REQ-01.18)
     */
    protected function recordActivity(int $projectId, string $action, string $targetType, int $targetId, ?array $oldVal = null, ?array $newVal = null): void
    {
        if (Schema::hasTable('activity_logs')) {
            DB::table('activity_logs')->insert([
                'project_id' => $projectId,
                'actor_id' => Auth::id(),
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'old_value' => $oldVal ? json_encode($oldVal) : null,
                'new_value' => $newVal ? json_encode($newVal) : null,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Menampilkan daftar issue ringkas pada project (untuk redirect saat Batal laporan - REQ-01.14)
     */
    public function index(int $project)
    {
        $this->ensureActiveReporter($project);

        $issues = Issue::where('project_id', $project)->latest()->get();

        return view('issues.index', [
            'projectId' => $project,
            'issues' => $issues,
        ]);
    }

    /**
     * Menampilkan antarmuka Submit Issue sesuai Mockup (GET /projects/{project}/submit)
     */
    public function create(int $project)
    {
        $this->ensureActiveReporter($project);

        $existingCount = Issue::where('project_id', $project)->count();

        return view('issues.create', [
            'projectId' => $project,
            'hasComparisonIssues' => $existingCount > 0,
        ]);
    }

    /**
     * Menyimpan issue baru, menjadwalkan deteksi dasar, dan menampilkan konfirmasi (POST /projects/{project}/issues)
     */
    public function store(Request $request, int $project): RedirectResponse
    {
        // REQ-01.1: Periksa kembali keanggotaan aktif sebelum menyimpan
        $this->ensureActiveReporter($project);

        // REQ-01.2 & REQ-01.10: Validasi panjang judul (5-200) dan deskripsi (10-10000)
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
        ], [
            'title.required' => 'Judul wajib 5-200 karakter',
            'title.min' => 'Judul wajib 5-200 karakter',
            'title.max' => 'Judul wajib 5-200 karakter',
            'description.required' => 'Deskripsi wajib 10-10000 karakter',
            'description.min' => 'Deskripsi wajib 10-10000 karakter',
            'description.max' => 'Deskripsi wajib 10-10000 karakter',
        ]);

        // Status deteksi default PENDING, atau FAILED jika disimulasikan gagal layanan NLP (REQ-01.9 & REQ-01.11)
        $initialDetectionStatus = $request->boolean('simulate_nlp_failure') ? 'FAILED' : 'PENDING';

        $issue = Issue::create([
            'project_id' => $project,
            'author_id' => Auth::id(),
            'title' => $validated['title'],
            'description' => $validated['description'],
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => $initialDetectionStatus,
            'vote_count' => 0,
        ]);

        // REQ-01.12: Jadwalkan deteksi duplikasi ke Laravel Database Queue
        try {
            if (class_exists(\App\Jobs\DetectDuplicateIssueJob::class)) {
                \App\Jobs\DetectDuplicateIssueJob::dispatch($issue->id, $project)->afterCommit();
            }
        } catch (\Throwable $e) {
            // Kegagalan antrean/NLP tidak boleh menghapus issue yang sudah tersimpan
            $issue->update(['detection_status' => 'FAILED']);
        }

        // REQ-01.18: Catat pembuatan issue ke activity_logs
        $this->recordActivity($project, 'CREATE_ISSUE', 'Issue', $issue->id, null, [
            'title' => $issue->title,
            'status' => $issue->status,
            'detection_status' => $issue->detection_status,
        ]);

        return redirect()
            ->route('issues.show', ['project' => $project, 'issue' => $issue->id])
            ->with('success', 'Issue berhasil dibuat');
    }

    /**
     * Endpoint AJAX Rekomendasi Pre-Submit (REQ-01.3 s.d. REQ-01.9)
     */
    public function recommend(Request $request, int $project): JsonResponse
    {
        $this->ensureActiveReporter($project);

        $title = trim((string) $request->input('title', ''));
        $description = trim((string) $request->input('description', ''));

        // Ambil issue pembanding hanya dari project yang sama (REQ-01.4)
        $candidatesPool = Issue::where('project_id', $project)
            ->select(['id', 'project_id', 'title', 'description', 'status', 'duplicate_of', 'vote_count'])
            ->latest()
            ->limit(100)
            ->get();

        // REQ-01.8: Bila belum ada issue pembanding di project
        if ($candidatesPool->isEmpty()) {
            return response()->json([
                'status' => 'empty',
                'message' => 'Belum ada issue pembanding',
                'recommendations' => [],
            ]);
        }

        $nlpUrl = config('services.nlp.url', env('NLP_SERVICE_URL', 'http://127.0.0.1:8001'));

        try {
            $response = Http::timeout(3)->post(rtrim($nlpUrl, '/') . '/candidates', [
                'query' => [
                    'id' => 0,
                    'project_id' => $project,
                    'title' => $title,
                    'description' => $description,
                ],
                'candidates' => $candidatesPool->map(fn ($item) => [
                    'id' => $item->id,
                    'project_id' => $item->project_id,
                    'title' => $item->title,
                    'description' => $item->description,
                ])->values()->all(),
                'top_k' => 3,
            ]);

            if (!$response->successful()) {
                throw new \RuntimeException('NLP Service error');
            }

            $results = $response->json('candidates') ?? $response->json('results') ?? [];
            $recommendations = [];

            foreach (array_slice($results, 0, 3) as $res) {
                $candIssue = $candidatesPool->firstWhere('id', $res['candidate_id'] ?? $res['id'] ?? null);
                if ($candIssue) {
                    $score = isset($res['score']) ? round(max(0, min(1, (float) $res['score'])) * 100) : 0;
                    // REQ-01.6: Jika berstatus DUPLICATE, arahkan target pembukaan/vote ke issue induknya
                    $targetId = ($candIssue->status === 'DUPLICATE' && $candIssue->duplicate_of)
                        ? $candIssue->duplicate_of
                        : $candIssue->id;

                    $recommendations[] = [
                        'id' => $candIssue->id,
                        'target_issue_id' => $targetId,
                        'title' => $candIssue->title,
                        'description' => $candIssue->description,
                        'status' => $candIssue->status,
                        'duplicate_of' => $candIssue->duplicate_of,
                        'similarity' => $score,
                        'detail_url' => route('issues.show', ['project' => $project, 'issue' => $targetId]),
                    ];
                }
            }

            return response()->json([
                'status' => count($recommendations) > 0 ? 'ok' : 'empty',
                'message' => count($recommendations) > 0 ? count($recommendations) . ' rekomendasi' : 'Belum ada issue pembanding',
                'recommendations' => $recommendations,
            ]);
        } catch (\Throwable $e) {
            // REQ-01.9: Bila layanan NLP gagal, tampilkan pesan tanpa menghalangi pengiriman form
            return response()->json([
                'status' => 'error',
                'message' => 'Rekomendasi belum dapat dimuat',
                'recommendations' => [],
            ], 200);
        }
    }

    /**
     * Menampilkan konfirmasi issue berhasil disimpan, status deteksi dasar, & tombol Vote (GET /projects/{project}/issues/{issue})
     */
    public function show(int $project, int $issue)
    {
        $this->ensureActiveReporter($project);

        $issueModel = Issue::find($issue);

        // REQ-01.17: Akses ke issue yang tidak tersedia atau di luar project Reporter ditolak
        if (!$issueModel || (int) $issueModel->project_id !== $project) {
            abort(403, 'Akses tidak tersedia');
        }

        $hasVoted = false;
        if (Schema::hasTable('votes')) {
            $hasVoted = Vote::where('issue_id', $issueModel->id)
                ->where('voter_id', Auth::id())
                ->exists();
        }

        return view('issues.show', [
            'projectId' => $project,
            'issue' => $issueModel,
            'hasVoted' => $hasVoted,
        ]);
    }

    /**
     * Memberikan Vote "+1" pada issue induk yang serupa (REQ-01.6, REQ-01.15, REQ-NF-14)
     */
    public function vote(int $project, int $issue): RedirectResponse
    {
        $this->ensureActiveReporter($project);

        $issueModel = Issue::find($issue);
        if (!$issueModel || (int) $issueModel->project_id !== $project) {
            abort(403, 'Akses tidak tersedia');
        }

        // REQ-01.6 & BR-07: Jika issue adalah DUPLICATE, arahkan ke issue induknya
        if ($issueModel->status === 'DUPLICATE' && $issueModel->duplicate_of) {
            $parent = Issue::where('project_id', $project)->find($issueModel->duplicate_of);
            if ($parent) {
                $issueModel = $parent;
            }
        }

        // REQ-01.15: Tolak vote berulang dengan pesan "Vote sudah aktif"
        $alreadyVoted = Vote::where('issue_id', $issueModel->id)
            ->where('voter_id', Auth::id())
            ->exists();

        if ($alreadyVoted) {
            return redirect()
                ->route('issues.show', ['project' => $project, 'issue' => $issueModel->id])
                ->with('vote_warning', 'Vote sudah aktif');
        }

        DB::transaction(function () use ($project, $issueModel) {
            Vote::create([
                'issue_id' => $issueModel->id,
                'voter_id' => Auth::id(),
                'created_at' => now(),
            ]);

            $totalVotes = Vote::where('issue_id', $issueModel->id)->count();
            $issueModel->update(['vote_count' => $totalVotes]);

            $this->recordActivity($project, 'VOTE_ISSUE', 'Issue', $issueModel->id, null, [
                'vote_count' => $totalVotes,
            ]);
        });

        return redirect()
            ->route('issues.show', ['project' => $project, 'issue' => $issueModel->id])
            ->with('vote_success', 'Vote berhasil');
    }

    /**
     * Membatalkan Vote pada issue (REQ-01.16 & REQ-NF-14)
     */
    public function unvote(int $project, int $issue): RedirectResponse
    {
        $this->ensureActiveReporter($project);

        $issueModel = Issue::find($issue);
        if (!$issueModel || (int) $issueModel->project_id !== $project) {
            abort(403, 'Akses tidak tersedia');
        }

        DB::transaction(function () use ($project, $issueModel) {
            Vote::where('issue_id', $issueModel->id)
                ->where('voter_id', Auth::id())
                ->delete();

            $totalVotes = Vote::where('issue_id', $issueModel->id)->count();
            $issueModel->update(['vote_count' => $totalVotes]);

            $this->recordActivity($project, 'UNVOTE_ISSUE', 'Issue', $issueModel->id, null, [
                'vote_count' => $totalVotes,
            ]);
        });

        return redirect()
            ->route('issues.show', ['project' => $project, 'issue' => $issueModel->id])
            ->with('vote_success', 'Vote dibatalkan');
    }
}