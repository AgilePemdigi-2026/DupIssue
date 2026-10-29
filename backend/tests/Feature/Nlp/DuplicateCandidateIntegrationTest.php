<?php

namespace Tests\Feature\Nlp;

use App\Models\DuplicateCandidate;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DuplicateCandidateIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function issue(?Project $project = null): Issue
    {
        $project ??= Project::factory()->create();

        return Issue::forceCreate([
            'project_id' => $project->id,
            'title' => 'Checkout issue',
            'description' => 'Checkout button does not respond.',
        ]);
    }

    public function test_migration_order_foreign_keys_and_model_relations(): void
    {
        $this->assertSame(['sqlite'], array_keys(config('database.connections')));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame(1, DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $migrations = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
        $issues = array_search('2026_10_21_200004_create_issues_table', $migrations, true);
        $candidates = array_search('2026_10_22_000000_create_duplicate_candidates_table', $migrations, true);
        $this->assertIsInt($issues);
        $this->assertIsInt($candidates);
        $this->assertGreaterThan($issues, $candidates);
        $keys = collect(DB::select('PRAGMA foreign_key_list(duplicate_candidates)'))->keyBy('from');
        foreach (['issue_id', 'candidate_issue_id'] as $column) {
            $this->assertSame('issues', $keys[$column]->table);
            $this->assertSame('id', $keys[$column]->to);
            $this->assertSame('CASCADE', $keys[$column]->on_delete);
        }

        $issue = $this->issue();
        $other = $this->issue($issue->project);
        $candidate = DuplicateCandidate::create([
            'issue_id' => $issue->id, 'candidate_issue_id' => $other->id, 'similarity_score' => 0.75,
        ])->fresh();
        $this->assertTrue($candidate->issue->is($issue));
        $this->assertTrue($candidate->candidateIssue->is($other));
        $this->assertTrue($candidate->issue->project->is($other->project));
        $this->assertSame(0.75, $candidate->similarity_score);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    #[DataProvider('foreignKeyColumns')]
    public function test_both_issue_foreign_keys_reject_orphans(string $column): void
    {
        $issue = $this->issue();
        $other = $this->issue($issue->project);
        $row = ['issue_id' => $issue->id, 'candidate_issue_id' => $other->id, 'similarity_score' => 0.9];
        $row[$column] = 999999;
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');
        DB::table('duplicate_candidates')->insert($row);
    }

    #[DataProvider('foreignKeyColumns')]
    public function test_deleting_either_issue_cascades_candidates(string $column): void
    {
        $issue = $this->issue();
        $other = $this->issue($issue->project);
        $candidate = DuplicateCandidate::create([
            'issue_id' => $issue->id, 'candidate_issue_id' => $other->id, 'similarity_score' => 0.9,
        ]);
        Issue::findOrFail($candidate->$column)->delete();
        $this->assertDatabaseCount('duplicate_candidates', 0);
        $this->assertDatabaseCount('issues', 1);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public static function foreignKeyColumns(): array
    {
        return [['issue_id'], ['candidate_issue_id']];
    }

    public function test_database_rejects_duplicate_pairs(): void
    {
        $issue = $this->issue();
        $other = $this->issue($issue->project);
        $row = ['issue_id' => $issue->id, 'candidate_issue_id' => $other->id, 'similarity_score' => 0.9];
        DuplicateCandidate::create($row);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('UNIQUE constraint failed');
        DB::table('duplicate_candidates')->insert($row);
    }

    #[DataProvider('invalidCandidates')]
    public function test_model_rejects_invalid_candidates(string $kind, float $score): void
    {
        $issue = $this->issue();
        $other = match ($kind) {
            'self' => $issue,
            'cross-project' => $this->issue(),
            default => $this->issue($issue->project),
        };
        $this->expectException(InvalidArgumentException::class);
        DuplicateCandidate::create([
            'issue_id' => $issue->id, 'candidate_issue_id' => $other->id, 'similarity_score' => $score,
        ]);
    }

    public static function invalidCandidates(): array
    {
        return [['self', 0.9], ['cross-project', 0.9], ['negative', -0.1], ['above-one', 1.1]];
    }

    #[DataProvider('validScores')]
    public function test_raw_numeric_scores_are_accepted_by_update_or_create(mixed $score, float $expected): void
    {
        $issue = $this->issue();
        $other = $this->issue($issue->project);
        $pair = ['issue_id' => $issue->id, 'candidate_issue_id' => $other->id];
        $saved = DuplicateCandidate::updateOrCreate($pair, ['similarity_score' => $score]);
        $this->assertSame($expected, $saved->fresh()->similarity_score);
        $updated = DuplicateCandidate::updateOrCreate($pair, ['similarity_score' => 0.85]);
        $this->assertSame($saved->id, $updated->id);
        $this->assertSame(0.85, $updated->fresh()->similarity_score);
        $this->assertDatabaseCount('duplicate_candidates', 1);
    }

    public static function validScores(): array
    {
        return [
            'zero float' => [0.0, 0.0], 'threshold' => [0.75, 0.75], 'one float' => [1.0, 1.0],
            'zero integer' => [0, 0.0], 'one integer' => [1, 1.0],
            'decimal string' => ['0.7500', 0.75], 'scientific numeric string' => ['7.5e-1', 0.75],
        ];
    }

    #[DataProvider('invalidRawScores')]
    public function test_invalid_raw_scores_are_rejected_before_cast(mixed $score): void
    {
        $issue = $this->issue();
        $other = $this->issue($issue->project);
        $this->expectException(InvalidArgumentException::class);
        try {
            DuplicateCandidate::create([
                'issue_id' => $issue->id, 'candidate_issue_id' => $other->id, 'similarity_score' => $score,
            ]);
        } finally {
            $this->assertDatabaseCount('duplicate_candidates', 0);
        }
    }

    public static function invalidRawScores(): array
    {
        return [
            'nonnumeric' => ['not-a-number'], 'nan' => [NAN],
            'infinity' => [INF], 'negative infinity' => [-INF],
            'negative' => [-0.1], 'above one' => [1.1],
            'overflow string' => ['1e309'], 'nan string' => ['NaN'], 'infinity string' => ['INF'],
            'empty string' => [''], 'null' => [null], 'boolean' => [true], 'array' => [[]],
        ];
    }

    public function test_invalid_update_uses_current_raw_value_and_preserves_persisted_score(): void
    {
        $issue = $this->issue();
        $other = $this->issue($issue->project);
        $pair = ['issue_id' => $issue->id, 'candidate_issue_id' => $other->id];
        $saved = DuplicateCandidate::create($pair + ['similarity_score' => 0.75])->fresh()->getRawOriginal();
        $this->expectException(InvalidArgumentException::class);
        try {
            DuplicateCandidate::updateOrCreate($pair, ['similarity_score' => 'not-a-number']);
        } finally {
            $this->assertSame($saved, DuplicateCandidate::query()->sole()->getRawOriginal());
        }
    }
}
