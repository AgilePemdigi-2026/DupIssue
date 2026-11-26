<?php

namespace Tests\Feature;

use App\Models\GithubAppConnection;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtensionWorkflowTest extends TestCase
{
    use DatabaseMigrations;

    public function test_login_pair_resolve_recommend_vote_web_visibility_unvote_disconnect(): void
    {
        Http::preventStrayRequests();
        config(['services.nlp.base_url' => 'http://nlp.test']);
        $reporter = User::factory()->create(['role' => 'REPORTER'])->refresh();
        $project = Project::factory()->create();
        Membership::factory()->create(['project_id' => $project->id, 'user_id' => $reporter->id, 'is_active' => true]);
        $connection = new GithubAppConnection;
        $connection->forceFill(['project_id' => $project->id, 'installation_id' => 12, 'repository_id' => 34,
            'repo_full_name' => 'example/repo', 'verified_github_user_id' => 56, 'verified_at' => now()])->save();
        $issue = Issue::forceCreate(['project_id' => $project->id, 'source_type' => 'GITHUB', 'github_repo' => 'example/repo',
            'github_repository_id' => 34, 'github_number' => 7, 'title' => 'Login issue imported from GitHub', 'description' => 'Cannot log into the application', 'detection_status' => 'COMPLETED']);
        Http::fake(['http://nlp.test/candidates' => Http::response(['candidates' => [['issue_id' => $issue->id, 'similarity' => .91]]])]);
        $this->post('/login', ['email' => $reporter->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($reporter);
        $code = $this->postJson('/extension/pairing')->assertCreated()->json('pairing_code');
        $token = $this->postJson('/api/extension/v1/pair', ['pairing_code' => $code])->assertOk()->json('access_token');
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/extension/v1/me', $headers)->assertOk()->assertJsonPath('user.id', $reporter->id);
        $this->postJson('/api/extension/v1/repositories/resolve', ['repository' => 'example/repo'], $headers)
            ->assertOk()->assertJsonPath('projects.0.id', $project->id);
        $input = ['repository' => 'example/repo', 'project_id' => $project->id, 'title' => 'Login fails again', 'description' => 'Cannot log into the application'];
        $result = $this->postJson('/api/extension/v1/recommendations', $input, $headers)->assertOk();
        $web = $this->postJson(route('issues.recommendations', $project), $input)->assertOk();
        $this->assertSame($web->json(), $result->json());
        $vote = ['repository' => 'example/repo', 'project_id' => $project->id, 'issue_id' => $issue->id];
        $this->postJson('/api/extension/v1/votes', $vote, $headers)->assertOk()->assertJsonPath('vote_count', 1);
        $this->get(route('issues.show', [$project, $issue]))->assertOk()->assertSee($issue->title);
        $this->assertDatabaseHas('votes', ['issue_id' => $issue->id, 'voter_id' => $reporter->id]);
        $this->deleteJson('/api/extension/v1/votes', $vote, $headers)->assertOk()->assertJsonPath('vote_count', 0);
        $this->deleteJson('/api/extension/v1/session', [], $headers)->assertOk()->assertJsonPath('disconnected', true);
        $this->getJson('/api/extension/v1/me', $headers)->assertUnauthorized();
        $this->assertDatabaseCount('votes', 0);
        $this->assertSame(0, $issue->fresh()->vote_count);
        $this->assertSame(['VOTE_ADDED', 'VOTE_REMOVED'], DB::table('activity_logs')->orderBy('id')->pluck('action')->all());
    }
}
