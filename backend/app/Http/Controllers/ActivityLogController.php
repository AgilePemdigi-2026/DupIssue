<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Issue;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    /**
     * Authorize that the current authenticated user is the Maintainer project owner.
     * Pattern aligned with DuplicateReviewController and ProjectMemberController.
     */
    private function authorizeMaintainerOwner(Request $request, Project $project): void
    {
        $user = $request->user();
        abort_unless(
            $user && $user->role === 'MAINTAINER' && (int) $project->owner_id === (int) $user->id,
            403,
            'Hanya Maintainer pemilik project yang dapat mengakses riwayat aktivitas.'
        );
    }

    /**
     * Display the paginated and filtered activity log list.
     */
    public function index(Request $request, Project $project)
    {
        $this->authorizeMaintainerOwner($request, $project);

        $validator = Validator::make($request->all(), [
            'category' => ['nullable', 'string', Rule::in(array_keys(ActivityLog::categories()))],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'keyword' => ['nullable', 'string', 'max:100'],
        ], [
            'category.in' => 'Kategori yang dipilih tidak valid.',
            'date_from.date_format' => 'Format tanggal mulai harus YYYY-MM-DD.',
            'date_to.date_format' => 'Format tanggal selesai harus YYYY-MM-DD.',
            'date_to.after_or_equal' => 'Tanggal selesai harus sama dengan atau setelah tanggal mulai.',
            'keyword.max' => 'Kata kunci maksimal 100 karakter.',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('projects.activity.index', ['project' => $project])
                ->withErrors($validator)
                ->withInput();
        }

        $validated = $validator->validated();

        // Project-scoping MUST always be the first query condition
        $query = ActivityLog::query()
            ->where('project_id', $project->id)
            ->with(['user', 'issue']);

        if (!empty($validated['category'])) {
            $query->ofCategory($validated['category']);
        }

        // Half-open interval for inclusive WIB date bounds converted to database UTC
        if (!empty($validated['date_from'])) {
            $startUtc = Carbon::createFromFormat('Y-m-d', $validated['date_from'], 'Asia/Jakarta')
                ->startOfDay()
                ->setTimezone('UTC');
            $query->where('created_at', '>=', $startUtc);
        }

        if (!empty($validated['date_to'])) {
            $endUtcExclusive = Carbon::createFromFormat('Y-m-d', $validated['date_to'], 'Asia/Jakarta')
                ->addDay()
                ->startOfDay()
                ->setTimezone('UTC');
            $query->where('created_at', '<', $endUtcExclusive);
        }

        // Keyword filter limited strictly to existing columns (action and reason) with escaped wildcards
        if (!empty($validated['keyword'])) {
            $keyword = $validated['keyword'];
            $escaped = str_replace(['~', '%', '_'], ['~~', '~%', '~_'], $keyword);
            $query->where(function ($q) use ($escaped) {
                $q->whereRaw("action LIKE ? ESCAPE '~'", ["%{$escaped}%"])
                    ->orWhereRaw("reason LIKE ? ESCAPE '~'", ["%{$escaped}%"]);
            });
        }

        $logs = $query->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(20)
            ->withQueryString();

        // Summary 1: 7-day activity count in WIB
        $sevenDaysAgoUtc = Carbon::now('Asia/Jakarta')->subDays(6)->startOfDay()->setTimezone('UTC');
        $activity7d = ActivityLog::where('project_id', $project->id)
            ->where('created_at', '>=', $sevenDaysAgoUtc)
            ->count();

        // Summary 2: Reduced duplicates (unique issues in current project with active duplicate_of)
        $reducedDuplicates = Issue::where('project_id', $project->id)->whereNotNull('duplicate_of')->count();

        // Summary 3: Fallback last GitHub sync
        $lastSync = ActivityLog::where('project_id', $project->id)
            ->where('action', 'IMPORT_GITHUB')
            ->latest('created_at')
            ->first();

        $lastSyncFormatted = $lastSync
            ? $lastSync->created_at->setTimezone('Asia/Jakarta')->translatedFormat('d M Y H:i \W\I\B')
            : 'Belum pernah';

        return view('activity.index', [
            'project' => $project,
            'logs' => $logs,
            'categories' => ActivityLog::categories(),
            'filters' => $validated,
            'activity7d' => $activity7d,
            'reducedDuplicates' => $reducedDuplicates,
            'lastSync' => $lastSyncFormatted,
        ]);
    }

    /**
     * Export activity logs as a streaming CSV file with UTF-8 BOM and formula injection protection.
     */
    public function export(Request $request, Project $project): Response
    {
        $this->authorizeMaintainerOwner($request, $project);

        $validator = Validator::make($request->all(), [
            'category' => ['nullable', 'string', Rule::in(array_keys(ActivityLog::categories()))],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'keyword' => ['nullable', 'string', 'max:100'],
        ], [
            'category.in' => 'Kategori yang dipilih tidak valid.',
            'date_from.date_format' => 'Format tanggal mulai harus YYYY-MM-DD.',
            'date_to.date_format' => 'Format tanggal selesai harus YYYY-MM-DD.',
            'date_to.after_or_equal' => 'Tanggal selesai harus sama dengan atau setelah tanggal mulai.',
            'keyword.max' => 'Kata kunci maksimal 100 karakter.',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('projects.activity.index', ['project' => $project])
                ->withErrors($validator)
                ->withInput();
        }

        $validated = $validator->validated();

        $query = ActivityLog::query()
            ->where('project_id', $project->id)
            ->with(['user', 'issue']);

        if (!empty($validated['category'])) {
            $query->ofCategory($validated['category']);
        }

        if (!empty($validated['date_from'])) {
            $startUtc = Carbon::createFromFormat('Y-m-d', $validated['date_from'], 'Asia/Jakarta')
                ->startOfDay()
                ->setTimezone('UTC');
            $query->where('created_at', '>=', $startUtc);
        }

        if (!empty($validated['date_to'])) {
            $endUtcExclusive = Carbon::createFromFormat('Y-m-d', $validated['date_to'], 'Asia/Jakarta')
                ->addDay()
                ->startOfDay()
                ->setTimezone('UTC');
            $query->where('created_at', '<', $endUtcExclusive);
        }

        if (!empty($validated['keyword'])) {
            $keyword = $validated['keyword'];
            $escaped = str_replace(['~', '%', '_'], ['~~', '~%', '~_'], $keyword);
            $query->where(function ($q) use ($escaped) {
                $q->whereRaw("action LIKE ? ESCAPE '~'", ["%{$escaped}%"])
                    ->orWhereRaw("reason LIKE ? ESCAPE '~'", ["%{$escaped}%"]);
            });
        }

        $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        $filename = 'activity-log-' . $project->id . '-' . now('Asia/Jakarta')->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($query, $project) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Microsoft Excel compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            // CSV Header
            fputcsv($handle, [
                'Waktu (WIB)',
                'Pelaku',
                'Kategori',
                'Aksi',
                'Target Issue',
                'Detail Perubahan',
                'Alasan',
            ]);

            // Stream chunked to prevent memory spike
            $query->chunk(200, function ($logs) use ($handle, $project) {
                foreach ($logs as $log) {
                    $wibTime = $log->created_at
                        ? $log->created_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s') . ' WIB'
                        : '-';

                    $target = '-';
                    if ($log->issue) {
                        $target = ((int) $log->issue->project_id === (int) $project->id)
                            ? '#' . $log->issue->id . ' - ' . $log->issue->title
                            : '#' . $log->issue->id . ' (Terisolasi)';
                    }

                    $oldSanitized = $log->sanitized_old_values;
                    $newSanitized = $log->sanitized_new_values;

                    $details = [];
                    if (!empty($oldSanitized)) {
                        $details[] = 'Lama: ' . json_encode($oldSanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    if (!empty($newSanitized)) {
                        $details[] = 'Baru: ' . json_encode($newSanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $detailsStr = !empty($details) ? implode(' | ', $details) : '-';

                    fputcsv($handle, [
                        self::sanitizeCsvCell($wibTime),
                        self::sanitizeCsvCell($log->actor_name),
                        self::sanitizeCsvCell($log->ui_category),
                        self::sanitizeCsvCell($log->action),
                        self::sanitizeCsvCell($target),
                        self::sanitizeCsvCell($detailsStr),
                        self::sanitizeCsvCell($log->reason ?? '-'),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * Neutralize formula injection triggers (=, +, -, @, Tab, CR) by prefixing with a single quote.
     */
    public static function sanitizeCsvCell(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $trimmed = ltrim($value, " \t\v\0");
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
