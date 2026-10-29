<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubmitIssueDatabaseIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeIssue(Project $project, User $author): Issue
    {
        $issue = new Issue(['title' => 'Valid title', 'description' => 'Valid issue description']);
        $issue->project()->associate($project);
        $issue->author()->associate($author);
        $issue->save();

        return $issue;
    }

    public function test_migrations_and_foreign_keys_use_only_sqlite_memory(): void
    {
        $this->assertSame(['sqlite'], array_keys(config('database.connections')));
        $this->assertSame('sqlite', DB::getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('', DB::selectOne('PRAGMA database_list')->file);
        $this->assertSame(1, DB::selectOne('PRAGMA foreign_keys')->foreign_keys);

        $migrations = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
        $users = array_search('0001_01_01_000000_create_users_table', $migrations, true);
        $projects = array_search('2026_10_21_200001_create_projects_table', $migrations, true);
        $memberships = array_search('2026_10_21_200002_create_memberships_table', $migrations, true);
        $issues = array_search('2026_10_21_200004_create_issues_table', $migrations, true);
        foreach ([$users, $projects, $memberships, $issues] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertTrue($users < $projects && $projects < $memberships && $memberships < $issues);
        $keys = collect(DB::select('PRAGMA foreign_key_list(issues)'))->keyBy('from');
        foreach (['project_id' => ['projects', 'RESTRICT'], 'author_id' => ['users', 'SET NULL'], 'duplicate_of' => ['issues', 'SET NULL']] as $column => [$table, $onDelete]) {
            $this->assertSame($table, $keys[$column]->table);
            $this->assertSame('id', $keys[$column]->to);
            $this->assertSame($onDelete, $keys[$column]->on_delete);
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_issue_with_unknown_project_is_rejected_by_foreign_key(): void
    {
        $author = User::factory()->create();
        $issue = new Issue(['title' => 'Valid title', 'description' => 'Valid issue description']);
        $issue->author()->associate($author);
        $issue->project_id = 999999;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');
        $issue->save();
    }

    public function test_deleting_author_preserves_issue_with_null_author(): void
    {
        $project = Project::factory()->create();
        $author = User::factory()->create();
        $issue = $this->makeIssue($project, $author);
        $author->delete();

        $this->assertDatabaseHas('issues', ['id' => $issue->id, 'author_id' => null]);
        $this->assertSame($project->id, $issue->fresh()->project->id);
    }

    public function test_project_with_issue_cannot_be_deleted(): void
    {
        $project = Project::factory()->create();
        $this->makeIssue($project, User::factory()->create());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');
        $project->delete();
    }
}
