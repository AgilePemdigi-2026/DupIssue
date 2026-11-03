<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IssueStatusManagementTest extends TestCase
{
    use DatabaseMigrations;

    private function setupProject(): array
    {
        $project = Project::factory()->create();
        $reporter = User::factory()->create(['role' => 'REPORTER']);
        Membership::factory()->create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => true,
        ]);
        return [$project, $reporter];
    }

    private function issue(Project $project, User $author, string $status = 'OPEN', ?Issue $parent = null): Issue
    {
        return Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $author->id,
            'title' => 'Issue simulasi status',
            'description' => 'Deskripsi sintetis untuk pengujian perubahan status.',
            'source_type' => 'FORM',
            'status' => $status,
            'detection_status' => 'COMPLETED',
            'duplicate_of' => $parent?->id,
        ]);
    }

    private function url(string $action, Project $project, Issue $issue): string
    {
        return route("issues.status.{$action}", ['project' => $project, 'issue' => $issue]);
    }

    private function form(Issue $issue, string $target): array
    {
        $issue->refresh();
        return [
            'new_status' => $target,
            'expected_status' => $issue->status,
            'expected_updated_at' => $issue->getRawOriginal('updated_at') ?? '',
        ];
    }

    public function test_maintainer_has_status_dropdown_and_only_legal_targets(): void
    {
        [$project, $reporter] = $this->setupProject();
        $issue = $this->issue($project, $reporter);
        $this->actingAs($project->owner)->get(route('issues.show', ['project' => $project, 'issue' => $issue]))
            ->assertOk()->assertSee('Ubah Status')->assertSee('IN_PROGRESS');
        $this->post($this->url('preview', $project, $issue), $this->form($issue, 'CLOSED'))
            ->assertStatus(409);
        $this->post($this->url('preview', $project, $issue), $this->form($issue, 'DUPLICATE'))
            ->assertStatus(409);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_change_status_with_confirmation_and_audit(): void
    {
        [$p, $r] = $this->setupProject();
        $issue = $this->issue($p, $r);
        $this->actingAs($p->owner);
        $response = $this->post($this->url('preview', $p, $issue), $this->form($issue, 'IN_PROGRESS'));
        $response->assertOk()->assertSee('Konfirmasi Perubahan Status');
        $key = "issue-status-confirm.{$p->id}.{$issue->id}";
        $entry = app('session.store')->get($key);
        $this->assertIsArray($entry);
        $this->post($this->url('confirm', $p, $issue), ['token' => $entry['token']])
            ->assertRedirect(route('issues.show', ['project' => $p, 'issue' => $issue]));
        $this->assertDatabaseHas('issues', ['id' => $issue->id, 'status' => 'IN_PROGRESS']);
        $this->assertDatabaseHas('activity_logs', [
            'issue_id' => $issue->id, 'action' => 'ISSUE_STATUS_CHANGED', 'user_id' => $p->owner_id,
        ]);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->actingAs($r)->get(route('issues.show', ['project' => $p, 'issue' => $issue]))
            ->assertOk()->assertSee('Riwayat Status')->assertDontSee('Simpan Status');
    }

    public function test_same_status_is_no_op_with_no_log(): void
    {
        [$p, $r] = $this->setupProject();
        $issue = $this->issue($p, $r);
        $this->actingAs($p->owner)->post($this->url('preview', $p, $issue),
            $this->form($issue, 'OPEN'))
            ->assertRedirect(route('issues.show', ['project' => $p, 'issue' => $issue]))
            ->assertSessionHas('status_notice', 'Tidak ada perubahan');
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_auto_close_only_open_linked_duplicates_and_record_audit(): void
    {
        [$p, $r] = $this->setupProject();
        $parent = $this->issue($p, $r, 'IN_PROGRESS');
        $child = $this->issue($p, $r, 'DUPLICATE', $parent);
        $alreadyClosed = $this->issue($p, $r, 'CLOSED', $parent);
        $this->actingAs($p->owner);
        $pre = $this->post($this->url('preview', $p, $parent), $this->form($parent, 'RESOLVED'));
        $pre->assertOk()->assertSee('Jumlah duplikat tertaut yang akan ditutup: <strong>1</strong>', false);
        $entry = app('session.store')->get("issue-status-confirm.{$p->id}.{$parent->id}");
        $this->post($this->url('confirm', $p, $parent), ['token' => $entry['token']])->assertRedirect();
        $this->assertDatabaseHas('issues', ['id' => $parent->id, 'status' => 'RESOLVED']);
        $this->assertDatabaseHas('issues', ['id' => $child->id, 'status' => 'CLOSED', 'duplicate_of' => $parent->id]);
        $this->assertDatabaseHas('issues', ['id' => $alreadyClosed->id, 'status' => 'CLOSED']);
        $this->assertDatabaseHas('activity_logs', ['issue_id' => $child->id, 'action' => 'DUPLICATE_AUTO_CLOSED']);
        $this->assertDatabaseCount('activity_logs', 2);
    }

    public function test_reopening_parent_does_not_reopen_closed_duplicates(): void
    {
        [$p, $r] = $this->setupProject();
        $parent = $this->issue($p, $r, 'RESOLVED');
        $child = $this->issue($p, $r, 'CLOSED', $parent);
        $this->actingAs($p->owner);
        $pre = $this->post($this->url('preview', $p, $parent), $this->form($parent, 'IN_PROGRESS'));
        $pre->assertOk()->assertSee('Duplikat lama tetap CLOSED');
        $entry = app('session.store')->get("issue-status-confirm.{$p->id}.{$parent->id}");
        $this->post($this->url('confirm', $p, $parent), ['token' => $entry['token']])->assertRedirect();
        $this->assertDatabaseHas('issues', ['id' => $parent->id, 'status' => 'IN_PROGRESS']);
        $this->assertDatabaseHas('issues', ['id' => $child->id, 'status' => 'CLOSED']);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_closed_parent_may_reopen_to_open(): void
    {
        [$p, $r] = $this->setupProject();
        $issue = $this->issue($p, $r, 'CLOSED');
        $this->actingAs($p->owner);
        $this->post($this->url('preview', $p, $issue), $this->form($issue, 'OPEN'))->assertOk();
        $entry = app('session.store')->get("issue-status-confirm.{$p->id}.{$issue->id}");
        $this->post($this->url('confirm', $p, $issue), ['token' => $entry['token']])->assertRedirect();
        $this->assertDatabaseHas('issues', ['id' => $issue->id, 'status' => 'OPEN']);
    }

    public function test_role_and_project_scope_restrictions(): void
    {
        [$p, $reporter] = $this->setupProject();
        $issue = $this->issue($p, $reporter);
        $payload = $this->form($issue, 'IN_PROGRESS');
        $this->actingAs($reporter)->get(route('issues.show', ['project' => $p, 'issue' => $issue]))
            ->assertOk()->assertDontSee('Simpan Status');
        $this->post($this->url('preview', $p, $issue), $payload)->assertForbidden();
        $outsider = User::factory()->create(['role' => 'MAINTAINER']);
        $this->actingAs($outsider)->post($this->url('preview', $p, $issue), $payload)->assertForbidden();
        $another = Project::factory()->create();
        $this->actingAs($p->owner)->post($this->url('preview', $another, $issue), $payload)->assertForbidden();
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_duplicate_cannot_be_manually_restatused(): void
    {
        [$p, $r] = $this->setupProject();
        $parent = $this->issue($p, $r);
        $child = $this->issue($p, $r, 'DUPLICATE', $parent);
        $this->actingAs($p->owner)->get(route('issues.show', ['project' => $p, 'issue' => $child]))
            ->assertOk()->assertSee('Batalkan Penandaan Duplikat')->assertDontSee('Simpan Status');
        $this->post($this->url('preview', $p, $child), $this->form($child, 'IN_PROGRESS'))
            ->assertStatus(409);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_stale_status_and_confirmation_token_are_rejected(): void
    {
        [$p, $r] = $this->setupProject();
        $issue = $this->issue($p, $r);
        $this->actingAs($p->owner)->post($this->url('confirm', $p, $issue), ['token' => 'bad'])
            ->assertStatus(409);
        $this->post($this->url('preview', $p, $issue), $this->form($issue, 'IN_PROGRESS'))
            ->assertOk();
        $entry = app('session.store')->get("issue-status-confirm.{$p->id}.{$issue->id}");
        DB::table('issues')->where('id', $issue->id)->update(['status' => 'RESOLVED']);
        $this->post($this->url('confirm', $p, $issue), ['token' => $entry['token']])->assertStatus(409);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_failed_auto_close_rolls_back_and_retry_writes_logs_once(): void
    {
        [$p, $r] = $this->setupProject();
        $parent = $this->issue($p, $r, 'IN_PROGRESS');
        $child = $this->issue($p, $r, 'DUPLICATE', $parent);
        $this->actingAs($p->owner)->post($this->url('preview', $p, $parent),
            $this->form($parent, 'RESOLVED'))->assertOk();
        $entry = app('session.store')->get("issue-status-confirm.{$p->id}.{$parent->id}");
        Issue::saving(function (Issue $current) use ($child): void {
            if ((int) $current->id === (int) $child->id && $current->status === 'CLOSED') {
                throw new \RuntimeException('Simulated auto-close failure');
            }
        });
        try {
            $this->post($this->url('confirm', $p, $parent), ['token' => $entry['token']])
                ->assertOk()->assertSee('Gagal menyimpan')->assertSee('Coba Lagi');
        } finally {
            Issue::flushEventListeners();
        }
        $this->assertDatabaseHas('issues', ['id' => $parent->id, 'status' => 'IN_PROGRESS']);
        $this->assertDatabaseHas('issues', ['id' => $child->id, 'status' => 'DUPLICATE']);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->post($this->url('confirm', $p, $parent), ['token' => $entry['token']])
            ->assertRedirect();
        $this->assertDatabaseHas('issues', ['id' => $parent->id, 'status' => 'RESOLVED']);
        $this->assertDatabaseHas('issues', ['id' => $child->id, 'status' => 'CLOSED']);
        $this->assertDatabaseCount('activity_logs', 2);
    }

    public function test_changed_duplicate_count_rejects_stale_preview(): void
    {
        [$p, $r] = $this->setupProject();
        $parent = $this->issue($p, $r, 'IN_PROGRESS');
        $this->actingAs($p->owner)->post($this->url('preview', $p, $parent),
            $this->form($parent, 'RESOLVED'))->assertOk();
        $entry = app('session.store')->get("issue-status-confirm.{$p->id}.{$parent->id}");
        $this->issue($p, $r, 'DUPLICATE', $parent);
        $this->post($this->url('confirm', $p, $parent), ['token' => $entry['token']])->assertStatus(409);
        $this->assertDatabaseHas('issues', ['id' => $parent->id, 'status' => 'IN_PROGRESS']);
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
