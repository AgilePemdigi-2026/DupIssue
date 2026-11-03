<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class IssueStatusController extends Controller
{
    /** Strict SRS v1.0 Table 4.38 transition matrix (TBD-02 remains a team decision). */
    public static function allowedTransitions(string $status): array
    {
        return match ($status) {
            'OPEN' => ['IN_PROGRESS'],
            'IN_PROGRESS' => ['RESOLVED'],
            'RESOLVED' => ['IN_PROGRESS'],
            'CLOSED' => ['OPEN'],
            default => [], // DUPLICATE cannot be assigned or changed here.
        };
    }

    private function authorizeOwner(Request $request, Project $project, Issue $issue): void
    {
        $user = $request->user();
        abort_unless($user && $user->role === 'MAINTAINER'
            && (int) $project->owner_id === (int) $user->id,
            403, 'Hanya Maintainer pemilik project yang dapat mengubah status issue');
        abort_unless((int) $issue->project_id === (int) $project->id, 404);
    }

    private function sessionKey(Project $project, Issue $issue): string
    {
        return "issue-status-confirm.{$project->id}.{$issue->id}";
    }

    private function version(Issue $issue): string
    {
        return (string) ($issue->getRawOriginal('updated_at') ?? '');
    }

    private function rejectInvalid(string $status): never
    {
        $options = self::allowedTransitions($status);
        abort(409, 'Transisi tidak diizinkan. Pilihan yang sah: '
            .($options ? implode(', ', $options) : 'tidak ada')
            .'. DUPLICATE hanya ditetapkan melalui validasi duplikat.');
    }

    public function preview(Request $request, Project $project, Issue $issue): View|RedirectResponse
    {
        $this->authorizeOwner($request, $project, $issue);
        abort_unless($issue->duplicate_of === null && $issue->status !== 'DUPLICATE',
            409, 'Issue duplikat harus dikelola melalui Batalkan Penandaan Duplikat');

        $target = $request->input('new_status');
        if (! is_string($target) || ! in_array($target, [
            'OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED', 'DUPLICATE',
        ], true)) {
            $this->rejectInvalid($issue->status);
        }

        // Reject browser forms that were filled before another status update.
        abort_unless($request->input('expected_status') === $issue->status
            && $request->input('expected_updated_at') === $this->version($issue),
            409, 'Data issue telah berubah. Muat ulang halaman sebelum mengubah status');

        if ($target === $issue->status) {
            $request->session()->forget($this->sessionKey($project, $issue));
            return redirect()->route('issues.show', compact('project', 'issue'))
                ->with('status_notice', 'Tidak ada perubahan');
        }
        if (! in_array($target, self::allowedTransitions($issue->status), true)) {
            $this->rejectInvalid($issue->status);
        }

        $autoCloseCount = $target === 'RESOLVED'
            ? Issue::query()->where('project_id', $project->id)
                ->where('duplicate_of', $issue->id)->where('status', '<>', 'CLOSED')->count()
            : 0;

        $token = Str::random(48);
        $request->session()->put($this->sessionKey($project, $issue), [
            'token' => $token,
            'current_status' => $issue->status,
            'current_version' => $this->version($issue),
            'target_status' => $target,
            'auto_close_count' => $autoCloseCount,
        ]);

        return view('issues.status-confirm', compact('project', 'issue', 'token', 'target', 'autoCloseCount'));
    }

    public function confirm(Request $request, Project $project, Issue $issue): View|RedirectResponse
    {
        $this->authorizeOwner($request, $project, $issue);
        $key = $this->sessionKey($project, $issue);
        $pending = $request->session()->get($key);
        $token = $request->input('token');
        abort_unless(is_array($pending) && is_string($token)
            && isset($pending['token']) && hash_equals($pending['token'], $token),
            409, 'Konfirmasi tidak valid atau kedaluwarsa. Mulai kembali dari Detail Issue');

        $target = $pending['target_status'];
        $autoCloseCount = $pending['auto_close_count'];
        try {
            DB::transaction(function () use ($request, $project, $issue, $pending, $target): void {
                $locked = Issue::query()->lockForUpdate()->findOrFail($issue->id);
                abort_unless((int) $locked->project_id === (int) $project->id, 404);
                abort_unless($locked->duplicate_of === null
                    && $locked->status === $pending['current_status']
                    && $this->version($locked) === $pending['current_version'],
                    409, 'Data issue telah berubah. Muat ulang halaman sebelum mengubah status');
                if (! in_array($target, self::allowedTransitions($locked->status), true)) {
                    $this->rejectInvalid($locked->status);
                }

                $children = collect();
                if ($target === 'RESOLVED') {
                    // Lock all linked duplicates before updating. One transaction protects
                    // status, auto-close, and audit history from partial writes.
                    $children = Issue::query()->where('project_id', $project->id)
                        ->where('duplicate_of', $locked->id)
                        ->orderBy('id')->lockForUpdate()->get();
                    $due = $children->where('status', '!=', 'CLOSED')->count();
                    abort_unless($due === (int) $pending['auto_close_count'],
                        409, 'Data duplikat berubah. Muat ulang dan konfirmasi jumlah terbaru');
                }

                $old = $locked->status;
                $locked->status = $target;
                $locked->save();
                $reopen = in_array($old, ['RESOLVED', 'CLOSED'], true);
                $this->record($request, $project, $locked, 'ISSUE_STATUS_CHANGED',
                    ['status' => $old], ['status' => $target],
                    $reopen ? 'Issue induk dibuka kembali; duplikat lama tetap CLOSED' : null);

                foreach ($children as $child) {
                    if ($child->status === 'CLOSED') {
                        continue; // No repeat auto-close or repeat audit.
                    }
                    $priorStatus = $child->status;
                    $child->status = 'CLOSED';
                    $child->save();
                    $this->record($request, $project, $child, 'DUPLICATE_AUTO_CLOSED',
                        ['status' => $priorStatus, 'duplicate_of' => $locked->id],
                        ['status' => 'CLOSED', 'duplicate_of' => $locked->id],
                        "Auto-close karena Issue Induk #{$locked->id} berubah menjadi RESOLVED");
                }
            }, 3);
        } catch (HttpExceptionInterface $error) {
            throw $error; // Reject conflicts and forbidden updates; do not retry them.
        } catch (Throwable $error) {
            Log::error('Failed saving issue status transaction', [
                'project_id' => $project->id,
                'issue_id' => $issue->id,
                'exception_type' => $error::class,
            ]);
            return view('issues.status-confirm', [
                'project' => $project, 'issue' => $issue, 'token' => $token,
                'target' => $target, 'autoCloseCount' => $autoCloseCount,
                'saveError' => 'Gagal menyimpan. Data sebelumnya tetap tersimpan. Coba Lagi.',
            ]);
        }

        $request->session()->forget($key);
        return redirect()->route('issues.show', compact('project', 'issue'))
            ->with('success', 'Status issue berhasil diperbarui');
    }

    private function record(Request $request, Project $project, Issue $issue,
        string $action, array $old, array $new, ?string $reason = null): void
    {
        DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'issue_id' => $issue->id,
            'action' => $action,
            'old_values' => json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => json_encode($new, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
