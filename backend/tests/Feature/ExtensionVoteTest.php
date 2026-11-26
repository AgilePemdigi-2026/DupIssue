<?php

namespace Tests\Feature;

use App\Models\GithubAppConnection;
use App\Models\Issue;
use App\Models\IssueVote;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Services\ExtensionAuthService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExtensionVoteTest extends TestCase
{
    use DatabaseMigrations;

    private User $reporter;
    private Project $project;
    private Issue $issue;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->reporter = User::factory()->create(['role' => 'REPORTER'])->refresh();
        $this->project = Project::factory()->create();
        Membership::factory()->create([
            'project_id' => $this->project->id,
            'user_id' => $this->reporter->id,
            'is_active' => true,
        ]);
        (new GithubAppConnection())->forceFill([
            'project_id' => $this->project->id,
            'installation_id' => 12345,
            'repository_id' => 67890,
            'repo_full_name' => 'example/mobile-app',
            'verified_github_user_id' => 99999,
            'verified_at' => now(),
        ])->save();
        $this->issue = $this->makeIssue();
        $this->token = $this->tokenFor($this->reporter);
    }

    protected function tearDown(): void
    {
        // All endpoint paths, including denied requests, must avoid NLP and jobs.
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        parent::tearDown();
    }

    private function tokenFor(User $user): string
    {
        $auth = app(ExtensionAuthService::class);

        return $auth->exchange($auth->createPairingCode($user))['access_token'];
    }

    private function makeIssue(array $attributes = []): Issue
    {
        $issue = new Issue();
        $issue->forceFill(array_merge([
            'project_id' => $this->project->id,
            'author_id' => $this->project->owner_id,
            'title' => 'Login button does not respond',
            'description' => 'The login button does not respond to clicks.',
            'status' => 'OPEN',
            'vote_count' => 0,
        ], $attributes))->save();

        return $issue;
    }

    private function voteRequest(string $method = 'POST', array $overrides = [], ?string $token = null): TestResponse
    {
        $data = array_merge([
            'repository' => 'EXAMPLE/MOBILE-APP',
            'project_id' => $this->project->id,
            'issue_id' => $this->issue->id,
        ], $overrides);
        $headers = ['Authorization' => 'Bearer '.($token ?? $this->token)];
        $url = route('extension.api.votes');

        return $method === 'GET'
            ? $this->getJson($url.'?'.http_build_query($data), $headers)
            : $this->json($method, $url, $data, $headers);
    }

    private function assertState(TestResponse $response, bool $voted, int $count, bool $changed, ?Issue $requested = null): void
    {
        $response->assertOk()->assertExactJson([
            'issue_id' => ($requested ?? $this->issue)->id,
            'target_issue_id' => $this->issue->id,
            'voted' => $voted,
            'vote_count' => $count,
            'changed' => $changed,
        ]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($count, (int) $this->issue->fresh()->vote_count);
        $this->assertSame($count, IssueVote::where('issue_id', $this->issue->id)->count());
    }

    private function assertNoVoteChanges(): void
    {
        $this->assertDatabaseCount('votes', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('duplicate_candidates', 0);
        $this->assertSame(0, (int) Issue::sum('vote_count'));
    }

    public function test_vote_unvote_are_idempotent_and_log_only_effective_changes(): void
    {
        $this->assertState($this->voteRequest('GET'), false, 0, false);
        $this->assertState($this->voteRequest(), true, 1, true);
        $this->assertState($this->voteRequest(), true, 1, false);
        $this->assertState($this->voteRequest('GET'), true, 1, false);
        $this->assertState($this->voteRequest('DELETE'), false, 0, true);
        $this->assertState($this->voteRequest('DELETE'), false, 0, false);
        $this->assertState($this->voteRequest('GET'), false, 0, false);

        $logs = DB::table('activity_logs')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        foreach ([['VOTE_ADDED', 0, 1], ['VOTE_REMOVED', 1, 0]] as $index => [$action, $old, $new]) {
            $log = $logs[$index];
            $this->assertSame($action, $log->action);
            $this->assertSame($this->reporter->id, $log->user_id);
            $this->assertSame($this->project->id, $log->project_id);
            $this->assertSame($this->issue->id, $log->issue_id);
            $this->assertSame(['vote_count' => $old], json_decode($log->old_values, true));
            $this->assertSame(['vote_count' => $new], json_decode($log->new_values, true));
        }
        $this->assertDatabaseCount('issues', 1);
    }

    public function test_extension_vote_is_visible_and_removable_on_website_with_original_messages(): void
    {
        $this->assertState($this->voteRequest(), true, 1, true);
        $detail = route('issues.show', [$this->project, $this->issue]);
        $this->actingAs($this->reporter)->get($detail)->assertOk()->assertSee('Batalkan Vote');
        $this->from($detail)->post(route('issues.vote', [$this->project, $this->issue]))
            ->assertRedirect($detail)->assertSessionHas('info', 'Vote sudah aktif');
        $this->from($detail)->delete(route('issues.unvote', [$this->project, $this->issue]))
            ->assertRedirect($detail)->assertSessionHas('success', 'Vote berhasil dibatalkan.');
        $this->from($detail)->delete(route('issues.unvote', [$this->project, $this->issue]))
            ->assertRedirect($detail)->assertSessionHas('info', 'Kamu belum memberikan vote untuk issue ini.');
        $this->assertState($this->voteRequest('GET'), false, 0, false);
        $this->assertDatabaseCount('activity_logs', 2);
    }

    public function test_web_vote_is_visible_and_removable_from_extension(): void
    {
        $detail = route('issues.show', [$this->project, $this->issue]);
        $this->actingAs($this->reporter)->from($detail)
            ->post(route('issues.vote', [$this->project, $this->issue]))
            ->assertRedirect($detail)->assertSessionHas('success', 'Vote berhasil diberikan.');
        $this->assertState($this->voteRequest('GET'), true, 1, false);
        $this->assertState($this->voteRequest(), true, 1, false);
        $this->assertState($this->voteRequest('DELETE'), false, 0, true);
        $this->get($detail)->assertOk()->assertDontSee('Batalkan Vote');
        $this->assertDatabaseCount('activity_logs', 2);
    }

    public function test_multiple_reporters_share_count_but_cannot_remove_each_others_votes(): void
    {
        $other = User::factory()->create(['role' => 'REPORTER'])->refresh();
        Membership::factory()->create(['project_id' => $this->project->id, 'user_id' => $other->id]);
        $otherToken = $this->tokenFor($other);
        $this->assertState($this->voteRequest(), true, 1, true);
        $this->assertState($this->voteRequest('GET', token: $otherToken), false, 1, false);
        $this->assertState($this->voteRequest('DELETE', ['voter_id' => $this->reporter->id], $otherToken), false, 1, false);
        $this->assertState($this->voteRequest('POST', token: $otherToken), true, 2, true);
        $this->assertState($this->voteRequest('DELETE'), false, 1, true);
        $this->assertState($this->voteRequest('DELETE'), false, 1, false);
        $this->assertDatabaseHas('votes', ['issue_id' => $this->issue->id, 'voter_id' => $other->id]);
        $this->assertDatabaseCount('activity_logs', 3);
    }

    public function test_read_status_does_not_mutate_any_vote_or_audit_record(): void
    {
        $this->voteRequest()->assertOk();
        $before = [];
        foreach (['issues', 'votes', 'activity_logs', 'extension_sessions'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->assertState($this->voteRequest('GET'), true, 1, false);
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson());
        }
    }

    public function test_duplicate_and_closed_children_share_valid_root_vote_and_status(): void
    {
        foreach (['DUPLICATE', 'CLOSED'] as $status) {
            $child = $this->makeIssue(['status' => $status, 'duplicate_of' => $this->issue->id]);
            $data = ['issue_id' => $child->id, 'target_issue_id' => 999999, 'vote_count' => 999];
            $this->assertState($this->voteRequest('POST', $data), true, 1, true, $child);
            $this->assertState($this->voteRequest('GET', $data), true, 1, false, $child);
            $this->assertState($this->voteRequest(), true, 1, false);
            $this->assertState($this->voteRequest('DELETE', $data), false, 0, true, $child);
            $this->assertSame(0, (int) $child->fresh()->vote_count);
            $this->assertDatabaseMissing('votes', ['issue_id' => $child->id]);
            $this->assertDatabaseMissing('activity_logs', ['issue_id' => $child->id]);
        }
    }

    public static function invalidParents(): array
    {
        return array_map(static fn ($type) => [$type], ['orphan', 'self', 'nested', 'duplicate_parent', 'foreign']);
    }

    #[DataProvider('invalidParents')]
    public function test_invalid_parents_are_rejected_for_all_operations(string $type): void
    {
        $parent = match ($type) {
            'nested' => $this->makeIssue(['duplicate_of' => $this->issue->id]),
            'duplicate_parent' => $this->makeIssue(['status' => 'DUPLICATE']),
            'foreign' => $this->makeIssue(['project_id' => Project::factory()->create()->id]),
            default => null,
        };
        $child = $this->makeIssue(['status' => 'DUPLICATE', 'duplicate_of' => $parent?->id]);
        if ($type === 'self') {
            $child->forceFill(['duplicate_of' => $child->id])->save();
        }
        foreach (['GET', 'POST', 'DELETE'] as $method) {
            $response = $this->voteRequest($method, ['issue_id' => $child->id])
                ->assertStatus(409)->assertJsonMissingPath('target_issue_id');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertNoVoteChanges();
    }

    public function test_foreign_and_nonexistent_issue_ids_are_rejected(): void
    {
        $foreign = $this->makeIssue(['project_id' => Project::factory()->create()->id]);
        foreach ([$foreign->id, 999999] as $id) {
            foreach (['GET', 'POST', 'DELETE'] as $method) {
                $this->voteRequest($method, ['issue_id' => $id])->assertNotFound();
            }
        }
        $this->assertNoVoteChanges();
    }

    public function test_forged_projects_and_repository_mismatches_are_rejected(): void
    {
        foreach ([['project_id' => Project::factory()->create()->id], ['project_id' => 999999],
            ['repository' => 'example/another-repository']] as $input) {
            foreach (['GET', 'POST', 'DELETE'] as $method) {
                $this->voteRequest($method, $input)->assertForbidden();
            }
        }
        $this->assertNoVoteChanges();
    }

    public function test_invalid_input_returns_no_store_json_validation_errors(): void
    {
        foreach (['project_id' => [0, -1, 'bad'], 'issue_id' => [0, -1, 'bad'],
            'repository' => ['', 'example/..', 'https://github.com/example/mobile-app']] as $field => $values) {
            foreach ($values as $value) {
                foreach (['GET', 'POST', 'DELETE'] as $method) {
                    $response = $this->voteRequest($method, [$field => $value])
                        ->assertUnprocessable()->assertJsonValidationErrors($field);
                    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                }
            }
        }
        $this->assertNoVoteChanges();
    }

    public static function invalidCredentials(): array
    {
        return array_map(static fn ($type) => [$type], ['missing', 'invalid', 'expired', 'revoked', 'auth_version', 'role']);
    }

    #[DataProvider('invalidCredentials')]
    public function test_invalid_credentials_cannot_use_any_vote_operation_even_with_web_session(string $type): void
    {
        $this->actingAs($this->reporter);
        match ($type) {
            'expired' => DB::table('extension_sessions')->update(['access_expires_at' => now()]),
            'revoked' => app(ExtensionAuthService::class)->revoke($this->token),
            'auth_version' => User::whereKey($this->reporter->id)->increment('auth_version'),
            'role' => User::whereKey($this->reporter->id)->update(['role' => 'MAINTAINER']),
            default => null,
        };
        $token = match ($type) {'missing' => '', 'invalid' => 'invalid', default => $this->token};
        foreach (['GET', 'POST', 'DELETE'] as $method) {
            $response = $this->voteRequest($method, token: $token)
                ->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertNoVoteChanges();
    }

    public static function unavailableAccess(): array
    {
        return array_map(static fn ($type) => [$type], ['inactive', 'removed', 'replacement', 'disconnected', 'installation']);
    }

    #[DataProvider('unavailableAccess')]
    public function test_membership_and_repository_access_are_checked_on_every_operation(string $type): void
    {
        $this->assertState($this->voteRequest(), true, 1, true);
        match ($type) {
            'inactive' => Membership::where('project_id', $this->project->id)->update(['is_active' => false]),
            'removed' => Membership::where('project_id', $this->project->id)->delete(),
            'replacement' => DB::table('github_app_connections')->update(['repo_full_name' => 'example/replacement', 'repository_id' => 98765]),
            'disconnected' => DB::table('github_app_connections')->delete(),
            'installation' => DB::table('github_app_connections')->update(['installation_id' => 0]),
        };
        foreach (['GET', 'POST', 'DELETE'] as $method) {
            $this->voteRequest($method)->assertForbidden()->assertJsonMissingPath('voted');
        }
        $this->assertDatabaseCount('votes', 1);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertSame(1, (int) $this->issue->fresh()->vote_count);
    }

    public static function accessRaces(): array
    {
        return [
            ['membership', 403], ['revocation', 401], ['auth_version', 401],
            ['replacement', 403], ['same_name_replacement', 403], ['role', 403],
        ];
    }

    #[DataProvider('accessRaces')]
    public function test_access_is_rechecked_inside_transaction_after_preflight(string $change, int $status): void
    {
        // Deterministic interleaving simulation on SQLite, not a parallel MySQL test.
        Event::listen(TransactionBeginning::class, function () use ($change): void {
            match ($change) {
                'membership' => Membership::where('project_id', $this->project->id)->update(['is_active' => false]),
                'revocation' => app(ExtensionAuthService::class)->revoke($this->token),
                'auth_version' => User::whereKey($this->reporter->id)->increment('auth_version'),
                'replacement' => DB::table('github_app_connections')->update(['repo_full_name' => 'example/replacement']),
                'same_name_replacement' => DB::table('github_app_connections')->update(['repository_id' => 98765]),
                'role' => User::whereKey($this->reporter->id)->update(['role' => 'MAINTAINER']),
            };
        });
        foreach (['GET', 'POST', 'DELETE'] as $method) {
            $this->voteRequest($method)->assertStatus($status)->assertJsonMissingPath('voted');
        }
        $this->assertNoVoteChanges();
    }

    public static function targetRaces(): array
    {
        return [['new_parent', 409], ['parent_deleted', 409], ['nested_parent', 409], ['issue_deleted', 404], ['issue_moved', 404]];
    }

    #[DataProvider('targetRaces')]
    public function test_changed_issue_or_parent_is_rejected_under_lock(string $change, int $status): void
    {
        $child = $this->makeIssue(['status' => 'DUPLICATE', 'duplicate_of' => $this->issue->id]);
        $other = $this->makeIssue();
        $otherProject = Project::factory()->create();
        Event::listen(TransactionBeginning::class, function () use ($change, $child, $other, $otherProject): void {
            match ($change) {
                'new_parent' => Issue::whereKey($child->id)->update(['duplicate_of' => $other->id]),
                'parent_deleted' => Issue::whereKey($this->issue->id)->delete(),
                'nested_parent' => Issue::whereKey($this->issue->id)->update(['duplicate_of' => $other->id]),
                'issue_deleted' => Issue::whereKey($child->id)->delete(),
                'issue_moved' => Issue::whereKey($child->id)->update(['project_id' => $otherProject->id]),
            };
        });
        foreach (['GET', 'POST', 'DELETE'] as $method) {
            $this->voteRequest($method, ['issue_id' => $child->id])->assertStatus($status);
        }
        $this->assertNoVoteChanges();
    }

    public static function mutations(): array
    {
        return [['POST'], ['DELETE']];
    }

    #[DataProvider('mutations')]
    public function test_audit_failure_rolls_back_vote_and_count_atomically(string $method): void
    {
        if ($method === 'DELETE') {
            $this->voteRequest()->assertOk();
        }
        $count = $method === 'DELETE' ? 1 : 0;
        // An actual SQLite failure after the vote and count writes tests rollback.
        DB::unprepared("CREATE TRIGGER reject_vote_audit BEFORE INSERT ON activity_logs
            WHEN NEW.action IN ('VOTE_ADDED', 'VOTE_REMOVED')
            BEGIN SELECT RAISE(ABORT, 'Simulated private audit failure'); END");
        $response = $this->voteRequest($method)->assertStatus(503)
            ->assertExactJson(['code' => 'VOTING_UNAVAILABLE', 'message' => 'Voting belum dapat diproses.']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('votes', $count);
        $this->assertDatabaseCount('activity_logs', $count);
        $this->assertSame($count, (int) $this->issue->fresh()->vote_count);
        DB::unprepared('DROP TRIGGER reject_vote_audit');
        $this->voteRequest($method)->assertOk()->assertJsonPath('changed', true);
    }

    #[DataProvider('mutations')]
    public function test_expiry_after_mutation_rolls_back_vote_count_and_log(string $method): void
    {
        if ($method === 'DELETE') {
            $this->voteRequest()->assertOk();
        }
        $count = $method === 'DELETE' ? 1 : 0;
        $event = $method === 'DELETE' ? 'deleted' : 'created';
        Event::listen('eloquent.'.$event.': '.IssueVote::class, function (): void {
            $this->assertGreaterThan(0, DB::transactionLevel());
            // Simulate expiration during a slow transaction without changing the token row.
            $this->travel(9)->hours();
        });
        $this->voteRequest($method)->assertUnauthorized()->assertJsonMissingPath('voted');
        $this->assertDatabaseCount('votes', $count);
        $this->assertDatabaseCount('activity_logs', $count);
        $this->assertSame($count, (int) $this->issue->fresh()->vote_count);
        $this->travelBack();
    }

    public function test_database_unique_constraint_still_prevents_duplicate_ballots(): void
    {
        $this->voteRequest()->assertOk();
        try {
            DB::transaction(fn () => IssueVote::create(['issue_id' => $this->issue->id, 'voter_id' => $this->reporter->id]));
            $this->fail('The existing unique constraint must reject duplicate votes.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
        }
        $this->assertState($this->voteRequest('GET'), true, 1, false);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public static function finalGuardFailures(): array
    {
        return [
            ['POST', 'revocation', 401], ['DELETE', 'revocation', 401],
            ['POST', 'membership', 403], ['DELETE', 'membership', 403],
            ['POST', 'replacement', 403], ['DELETE', 'replacement', 403],
        ];
    }

    #[DataProvider('finalGuardFailures')]
    public function test_final_access_guard_failure_rolls_back_every_vote_write(string $method, string $change, int $status): void
    {
        if ($method === 'DELETE') {
            $this->voteRequest()->assertOk();
        }
        $count = $method === 'DELETE' ? 1 : 0;
        $event = $method === 'DELETE' ? 'deleted' : 'created';
        Event::listen('eloquent.'.$event.': '.IssueVote::class, function () use ($change): void {
            $this->assertGreaterThan(0, DB::transactionLevel());
            match ($change) {
                'revocation' => app(ExtensionAuthService::class)->revoke($this->token),
                'membership' => Membership::where('project_id', $this->project->id)->update(['is_active' => false]),
                'replacement' => DB::table('github_app_connections')->update(['repo_full_name' => 'example/replacement']),
            };
        });
        $this->voteRequest($method)->assertStatus($status)->assertJsonMissingPath('voted');
        $this->assertDatabaseCount('votes', $count);
        $this->assertDatabaseCount('activity_logs', $count);
        $this->assertSame($count, (int) $this->issue->fresh()->vote_count);
    }

    public static function deadlockFailures(): array
    {
        return [['recovered', 2, 200], ['exhausted', 3, 503]];
    }

    #[DataProvider('deadlockFailures')]
    public function test_deadlock_retry_is_bounded_and_does_not_duplicate_votes_or_logs(string $scenario, int $failures, int $status): void
    {
        $attempts = 0;
        DB::connection()->beforeExecuting(function ($query, $bindings, $connection) use (&$attempts, $failures): void {
            if (! str_starts_with($query, 'insert into "activity_logs"')) {
                return;
            }
            $attempts++;
            if ($attempts <= $failures) {
                // Fail after the ballot/count writes, exercising transaction retry.
                throw new QueryException($connection->getName(), $query, [],
                    new \PDOException('Simulated serialization failure', 40001));
            }
        });
        $response = $this->voteRequest()->assertStatus($status);
        $this->assertSame(3, $attempts);
        if ($scenario === 'recovered') {
            $this->assertState($response, true, 1, true);
            $this->assertDatabaseCount('activity_logs', 1);
        } else {
            $response->assertJsonPath('code', 'VOTING_UNAVAILABLE');
            $this->assertNoVoteChanges();
        }
    }

    public function test_completed_other_vote_is_counted_after_preflight_before_our_transaction_work(): void
    {
        $other = User::factory()->create(['role' => 'REPORTER']);
        Event::listen(TransactionBeginning::class, function () use ($other): void {
            // Simulate another writer's completed result before the locked reads.
            DB::table('votes')->insert(['issue_id' => $this->issue->id, 'voter_id' => $other->id]);
            DB::table('issues')->where('id', $this->issue->id)->update(['vote_count' => 1]);
        });
        $this->assertState($this->voteRequest(), true, 2, true);
        $log = DB::table('activity_logs')->first();
        $this->assertSame(['vote_count' => 1], json_decode($log->old_values, true));
        $this->assertSame(['vote_count' => 2], json_decode($log->new_values, true));
    }

    public function test_web_also_rechecks_membership_within_shared_transaction(): void
    {
        Event::listen(TransactionBeginning::class, function (): void {
            Membership::where('project_id', $this->project->id)->update(['is_active' => false]);
        });
        $this->actingAs($this->reporter)->post(route('issues.vote', [$this->project, $this->issue]))->assertForbidden();
        $this->assertNoVoteChanges();
    }

    public function test_rate_limit_prevents_an_additional_vote(): void
    {
        $this->freezeTime();
        for ($request = 0; $request < 60; $request++) {
            $this->voteRequest('GET')->assertOk();
        }
        $this->voteRequest()->assertStatus(429)->assertHeader('Retry-After');
        $this->assertNoVoteChanges();
    }
}
