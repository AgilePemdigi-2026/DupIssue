<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IssueStatusReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Project, User} */
    private function setupReporter(): array
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

    private function makeIssue(Project $project, string $title, array $overrides = []): Issue
    {
        return Issue::forceCreate(array_merge([
            'project_id' => $project->id,
            'author_id' => $project->owner_id,
            'title' => $title,
            'description' => 'Detail pengujian pencarian dan status issue untuk Reporter.',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
            'duplicate_of' => null,
        ], $overrides));
    }

    private function indexUrl(Project $project, array $parameters = []): string
    {
        return route('issues.index', ['project' => $project] + $parameters);
    }

    private function detailUrl(Project $project, Issue|int $issue): string
    {
        return route('issues.show', ['project' => $project, 'issue' => $issue]);
    }

    public function test_issue_list_shows_status_source_vote_and_only_same_project(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $this->makeIssue($project, 'Pembayaran Kartu Gagal', [
            'vote_count' => 4,
            'source_type' => 'FORM',
            'status' => 'IN_PROGRESS',
        ]);
        $this->makeIssue($project, 'Imported issue', ['source_type' => 'GITHUB']);
        $other = Project::factory()->create();
        $this->makeIssue($other, 'Rahasia project lain');

        $this->actingAs($reporter)->get($this->indexUrl($project))
            ->assertOk()
            ->assertSee('Pembayaran Kartu Gagal')
            ->assertSee('IN_PROGRESS')
            ->assertSee('Manual')
            ->assertSee('GitHub')
            ->assertSee('>4<', false)
            ->assertDontSee('Rahasia project lain');
    }

    public function test_searches_title_and_description_and_filters_status(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $this->makeIssue($project, 'Bayar gagal', ['status' => 'OPEN']);
        $this->makeIssue($project, 'Checkout rusak', [
            'description' => 'Tombol bayar tidak berjalan ketika checkout.',
            'status' => 'RESOLVED',
        ]);
        $this->makeIssue($project, 'Bayar lama', ['status' => 'CLOSED']);

        $this->actingAs($reporter)
            ->get($this->indexUrl($project, ['cari' => 'bayar', 'status' => 'RESOLVED']))
            ->assertOk()
            ->assertSee('Checkout rusak')
            ->assertDontSee('Bayar gagal')
            ->assertDontSee('Bayar lama');
    }

    public function test_search_with_no_results_shows_empty_state_and_reset(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $this->makeIssue($project, 'Issue pertama');

        $this->actingAs($reporter)
            ->get($this->indexUrl($project, ['cari' => 'tidak-ada-satupun']))
            ->assertOk()
            ->assertSee('Issue tidak tersedia')
            ->assertSee('Reset Filter')
            ->assertDontSee('Issue pertama');
        $this->get($this->indexUrl($project))
            ->assertOk()->assertSee('Issue pertama');
    }

    public function test_pagination_preserves_keyword_and_status(): void
    {
        [$project, $reporter] = $this->setupReporter();
        for ($i = 0; $i < 18; $i++) {
            $this->makeIssue($project, "Bayar #{$i}", ['status' => 'OPEN']);
        }
        $this->actingAs($reporter)
            ->get($this->indexUrl($project, ['cari' => 'Bayar', 'status' => 'OPEN']))
            ->assertOk()
            ->assertSee('page=2', false)
            ->assertSee('cari=Bayar', false)
            ->assertSee('status=OPEN', false);
    }

    public function test_detail_shows_vote_source_history_and_reload_controls(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $issue = $this->makeIssue($project, 'Konfirmasi pembayaran', [
            'vote_count' => 7,
            'status' => 'IN_PROGRESS',
        ]);
        DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'user_id' => $project->owner_id,
            'issue_id' => $issue->id,
            'action' => 'ISSUE_STATUS_CHANGED',
            'old_values' => json_encode(['status' => 'OPEN']),
            'new_values' => json_encode(['status' => 'IN_PROGRESS']),
            'reason' => 'Sedang ditinjau',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($reporter)->get($this->detailUrl($project, $issue))
            ->assertOk()->assertSee('IN_PROGRESS')->assertSee('Jumlah vote:')
            ->assertSee('>7<', false)->assertSee('Manual')
            ->assertSee('Riwayat perubahan status')
            ->assertSee('ISSUE_STATUS_CHANGED')->assertSee('Sedang ditinjau')
            ->assertSee('Muat Ulang')->assertSee('Coba Lagi');
    }

    public function test_duplicate_links_only_to_parent_in_same_project(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $parent = $this->makeIssue($project, 'Original issue');
        $child = $this->makeIssue($project, 'Duplicate issue', [
            'duplicate_of' => $parent->id, 'status' => 'DUPLICATE',
        ]);
        $this->actingAs($reporter)->get($this->detailUrl($project, $child))
            ->assertOk()->assertSee('Issue Induk')->assertSee('#'.$parent->id)
            ->assertSee($this->detailUrl($project, $parent));

        $other = Project::factory()->create();
        $foreign = $this->makeIssue($other, 'Foreign parent issue');
        $child->duplicate_of = $foreign->id;
        $child->save();
        $this->get($this->detailUrl($project, $child))
            ->assertOk()->assertSee('Issue induk tidak tersedia.')
            ->assertDontSee($this->detailUrl($project, $foreign));
    }

    public function test_read_only_refresh_returns_latest_data_without_any_mutations(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $issue = $this->makeIssue($project, 'Status terbaru', ['vote_count' => 2]);
        DB::table('issues')->where('id', $issue->id)
            ->update(['status' => 'RESOLVED', 'vote_count' => 8]);
        $before = DB::table('issues')->where('id', $issue->id)->first();
        $logsBefore = DB::table('activity_logs')->count();

        $this->actingAs($reporter)
            ->getJson(route('issues.status.read', ['project' => $project, 'issue' => $issue]))
            ->assertOk()
            ->assertJsonPath('status', 'RESOLVED')
            ->assertJsonPath('vote_count', 8)
            ->assertJsonPath('detection_status', 'COMPLETED')
            ->assertJsonCount(0, 'history');

        $after = DB::table('issues')->where('id', $issue->id)->first();
        $this->assertEquals($before, $after);
        $this->assertSame($logsBefore, DB::table('activity_logs')->count());
    }

    public function test_unauthorized_access_is_rejected_for_list_detail_and_refresh(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $issue = $this->makeIssue($project, 'Restricted issue');
        $url = route('issues.status.read', ['project' => $project, 'issue' => $issue]);

        $this->get($this->indexUrl($project))->assertRedirect(route('login'));
        $this->get($this->detailUrl($project, $issue))->assertRedirect(route('login'));
        $this->getJson($url)->assertUnauthorized();

        $nonMember = User::factory()->create(['role' => 'REPORTER']);
        $this->actingAs($nonMember)->get($this->indexUrl($project))->assertForbidden();
        $this->get($this->detailUrl($project, $issue))->assertForbidden();
        $this->getJson($url)->assertForbidden();

        Membership::where('project_id', $project->id)
            ->where('user_id', $reporter->id)->update(['is_active' => false]);
        $this->actingAs($reporter)->get($this->indexUrl($project))->assertForbidden();
        $this->get($this->detailUrl($project, $issue))->assertForbidden();
        $this->getJson($url)->assertForbidden();
    }

    public function test_missing_and_foreign_issue_returns_safe_not_found_page(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $foreignProject = Project::factory()->create();
        $foreign = $this->makeIssue($foreignProject, 'Foreign secret details');
        $this->actingAs($reporter)->get($this->detailUrl($project, 999999))
            ->assertNotFound()->assertSee('Issue tidak tersedia')
            ->assertSee('Kembali ke Daftar');
        $this->get($this->detailUrl($project, $foreign))
            ->assertNotFound()->assertSee('Issue tidak tersedia')
            ->assertSee('Kembali ke Daftar')
            ->assertDontSee('Foreign secret details');
        $this->getJson(route('issues.status.read', [
            'project' => $project, 'issue' => $foreign,
        ]))->assertNotFound();
    }

    public function test_refresh_endpoint_only_allows_read_requests(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $issue = $this->makeIssue($project, 'Read-only issue');
        $url = route('issues.status.read', ['project' => $project, 'issue' => $issue]);
        $before = Issue::findOrFail($issue->id)->toArray();
        $this->actingAs($reporter)->postJson($url, ['status' => 'CLOSED'])
            ->assertStatus(405);
        $this->assertSame($before, Issue::findOrFail($issue->id)->toArray());
    }
}
