<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GithubAppConnection;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use DOMDocument;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GithubOutboundStatusSynchronizationTest extends TestCase
{
    private const OUTBOX = 'github_issue_status_outbox';

    private const INSTALLATION = 910001;

    private const REPOSITORY_ID = 920001;

    private const REPOSITORY = 'example/outbound-status-fixture';

    private Project $project;

    private GithubAppConnection $connection;

    public function createApplication()
    {
        $isolatedPath = getenv('DUPISSUE_TDD_ENV_PATH');

        if ($isolatedPath === false || $isolatedPath === '') {
            return parent::createApplication();
        }

        if (! is_dir($isolatedPath)) {
            throw new \RuntimeException(
                'Invalid isolated environment path.'
            );
        }

        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';

        $app->useEnvironmentPath($isolatedPath);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Reject unsafe database configuration before migrations.
        $this->assertTrue(app()->environment('testing'));

        $this->assertSame(
            'sqlite',
            config('database.default')
        );

        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database')
        );

        $this->assertEmpty(
            config('database.connections.sqlite.url')
        );

        $this->assertSame(
            ':memory:',
            DB::connection()->getDatabaseName()
        );

        Http::preventStrayRequests();

        // Outbound jobs must not execute synchronously.
        config(['queue.default' => 'database']);

        $this->artisan('migrate', ['--force' => true])
            ->assertExitCode(0);

        $this->project = Project::factory()->create();

        $this->connection = new GithubAppConnection;

        $this->connection->project_id = $this->project->id;
        $this->connection->installation_id = self::INSTALLATION;
        $this->connection->repository_id = self::REPOSITORY_ID;
        $this->connection->repo_full_name = self::REPOSITORY;
        $this->connection->verified_github_user_id = 930001;
        $this->connection->verified_at = now();

        $this->connection->save();
    }

    private function issue(
        string $status,
        array $overrides = []
    ): Issue {
        $data = array_replace([
            'project_id' => $this->project->id,
            'author_id' => null,
            'source_type' => 'GITHUB',
            'source_id' => null,
            'github_repo' => self::REPOSITORY,
            'github_repository_id' => self::REPOSITORY_ID,
            'github_number' => 42,
            'source_url' => 'https://github.com/'
                .self::REPOSITORY.'/issues/42',
            'title' => 'Synthetic outbound status test issue',
            'description' => 'Isolated fixture for outbound synchronization.',
            'status' => $status,
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
            'duplicate_of' => null,
        ], $overrides);

        if ($data['source_type'] !== 'GITHUB') {
            $data['github_repo'] = null;
            $data['github_repository_id'] = null;
            $data['github_number'] = null;
            $data['source_url'] = null;
        }

        return Issue::forceCreate($data);
    }

    private function url(
        string $action,
        Issue $issue
    ): string {
        return route('issues.status.'.$action, [
            'project' => $this->project,
            'issue' => $issue,
        ]);
    }

    private function preview(
        Issue $issue,
        string $target
    ): TestResponse {
        $issue->refresh();

        return $this->post(
            $this->url('preview', $issue),
            [
                'new_status' => $target,
                'expected_status' => $issue->status,
                'expected_updated_at' =>
                    $issue->getRawOriginal('updated_at') ?? '',
            ]
        );
    }

    /**
     * Use the real confirmation form contract rather than inventing
     * a confirmation token or bypassing the status controller.
     */
    private function confirmationFields(
        Issue $issue,
        TestResponse $response
    ): array {
        $response->assertOk();

        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                (string) $response->getContent(),
                LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $this->assertTrue($loaded);

        $expectedAction = $this->url('confirm', $issue);

        foreach ($document->getElementsByTagName('form') as $form) {
            if ($form->getAttribute('action') !== $expectedAction) {
                continue;
            }

            $fields = [];

            foreach ($form->getElementsByTagName('input') as $input) {
                if (strtolower($input->getAttribute('type')) !== 'hidden') {
                    continue;
                }

                $name = $input->getAttribute('name');

                if ($name !== '') {
                    $fields[$name] = $input->getAttribute('value');
                }
            }

            $this->assertNotEmpty($fields);

            $businessFields = array_diff_key(
                $fields,
                ['_token' => true, '_method' => true]
            );

            $this->assertNotEmpty(
                $businessFields,
                'Expected the existing status confirmation field.'
            );

            return $fields;
        }

        $this->fail('Existing confirmation form not found.');
    }

    private function confirmChange(
        Issue $issue,
        string $target
    ): void {
        $this->actingAs($this->project->owner);

        $preview = $this->preview($issue, $target);

        $fields = $this->confirmationFields(
            $issue,
            $preview
        );

        $this->post(
            $this->url('confirm', $issue),
            $fields
        )->assertRedirect(
            route('issues.show', [
                'project' => $this->project,
                'issue' => $issue,
            ])
        );

        $this->assertSame(
            $target,
            $issue->fresh()->status
        );

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $this->project->id,
            'issue_id' => $issue->id,
            'user_id' => $this->project->owner_id,
            'action' => 'ISSUE_STATUS_CHANGED',
        ]);

        // A normal status request must never contact GitHub directly.
        Http::assertNothingSent();
    }

    private function assertOutboundIntent(
        Issue $issue,
        string $desiredState
    ): void {
        $this->assertTrue(
            Schema::hasTable(self::OUTBOX),
            'Transactional GitHub status outbox must exist.'
        );

        $rows = DB::table(self::OUTBOX)
            ->where('issue_id', $issue->id)
            ->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame(
            (int) $issue->project_id,
            (int) $row->project_id
        );

        $this->assertSame(
            (int) $this->connection->id,
            (int) $row->connection_id
        );

        $this->assertSame(
            self::INSTALLATION,
            (int) $row->installation_id
        );

        $this->assertSame(
            self::REPOSITORY_ID,
            (int) $row->repository_id
        );

        $this->assertSame(
            (int) $issue->github_number,
            (int) $row->issue_number
        );

        $this->assertSame(
            $desiredState,
            $row->desired_state
        );

        $this->assertGreaterThan(
            0,
            (int) $row->revision
        );

        $this->assertContains(
            $row->status,
            ['PENDING', 'QUEUED']
        );
    }

    private function assertNoOutboundIntent(): void
    {
        // RED-phase negative controls remain valid when the outbox
        // has not been introduced yet. Once it exists, require zero rows.
        if (Schema::hasTable(self::OUTBOX)) {
            $this->assertDatabaseCount(self::OUTBOX, 0);
        }

        Http::assertNothingSent();
    }

    public static function outboundCases(): array
    {
        return [
            'O01_resolve_github' => [
                'IN_PROGRESS', 'RESOLVED', 'closed',
            ],
            'O02_reopen_resolved' => [
                'RESOLVED', 'IN_PROGRESS', 'open',
            ],
            'O03_reopen_closed' => [
                'CLOSED', 'OPEN', 'open',
            ],
        ];
    }

    #[DataProvider('outboundCases')]
    public function test_authorized_change_creates_durable_intent(
        string $before,
        string $target,
        string $remoteState
    ): void {
        $issue = $this->issue($before);

        $this->confirmChange($issue, $target);

        $this->assertOutboundIntent(
            $issue,
            $remoteState
        );

        $this->assertDatabaseCount(self::OUTBOX, 1);
    }

    public static function excludedCases(): array
    {
        return [
            'O04_same_projected_state' => [
                'OPEN',
                'IN_PROGRESS',
                [],
            ],
            'O05_form_issue' => [
                'IN_PROGRESS',
                'RESOLVED',
                ['source_type' => 'FORM'],
            ],
            'O06_csv_issue' => [
                'IN_PROGRESS',
                'RESOLVED',
                [
                    'source_type' => 'CSV',
                    'source_id' => 'synthetic-csv-001',
                ],
            ],
            'O07_historical_repository' => [
                'IN_PROGRESS',
                'RESOLVED',
                [
                    'github_repo' => 'example/historical-fixture',
                    'github_repository_id' => 920002,
                    'source_url' => 'https://github.com/example/'
                        .'historical-fixture/issues/42',
                ],
            ],
            'O08_missing_stable_identity' => [
                'IN_PROGRESS',
                'RESOLVED',
                ['github_repository_id' => null],
            ],
        ];
    }

    #[DataProvider('excludedCases')]
    public function test_ineligible_changes_remain_local(
        string $before,
        string $target,
        array $overrides
    ): void {
        $issue = $this->issue($before, $overrides);

        $this->confirmChange($issue, $target);

        $this->assertNoOutboundIntent();
    }

    public function test_O09_parent_resolution_creates_only_parent_intent(): void
    {
        $parent = $this->issue('IN_PROGRESS');

        $child = $this->issue('DUPLICATE', [
            'github_number' => 43,
            'source_url' => 'https://github.com/'
                .self::REPOSITORY.'/issues/43',
            'duplicate_of' => $parent->id,
        ]);

        $this->confirmChange($parent, 'RESOLVED');

        $this->assertDatabaseHas('issues', [
            'id' => $child->id,
            'status' => 'CLOSED',
            'duplicate_of' => $parent->id,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'issue_id' => $child->id,
            'action' => 'DUPLICATE_AUTO_CLOSED',
        ]);

        $this->assertOutboundIntent($parent, 'closed');

        $this->assertDatabaseCount(self::OUTBOX, 1);

        $this->assertDatabaseMissing(self::OUTBOX, [
            'issue_id' => $child->id,
        ]);
    }

    public function test_O10_reporter_cannot_create_outbound_intent(): void
    {
        $issue = $this->issue('IN_PROGRESS');

        $reporter = User::factory()->create([
            'role' => 'REPORTER',
        ]);

        $this->actingAs($reporter);

        $this->preview($issue, 'RESOLVED')
            ->assertForbidden();

        $this->assertSame(
            'IN_PROGRESS',
            $issue->fresh()->status
        );

        $this->assertDatabaseCount('activity_logs', 0);

        $this->assertNoOutboundIntent();
    }

    public function test_O11_linked_duplicate_cannot_create_outbound_intent(): void
    {
        $parent = $this->issue('RESOLVED', [
            'source_type' => 'FORM',
        ]);

        $child = $this->issue('CLOSED', [
            'duplicate_of' => $parent->id,
        ]);

        $this->actingAs($this->project->owner);

        $this->preview($child, 'OPEN')
            ->assertStatus(409);

        $this->assertDatabaseHas('issues', [
            'id' => $child->id,
            'status' => 'CLOSED',
            'duplicate_of' => $parent->id,
        ]);

        $this->assertDatabaseCount('activity_logs', 0);

        $this->assertNoOutboundIntent();
    }

    public function test_O12_transaction_failure_leaves_no_outbound_intent(): void
    {
        $issue = $this->issue('IN_PROGRESS');

        $this->actingAs($this->project->owner);

        $preview = $this->preview($issue, 'RESOLVED');

        $fields = $this->confirmationFields(
            $issue,
            $preview
        );

        // Synthetic failure on the isolated SQLite database only.
        DB::unprepared(
            "CREATE TRIGGER outbound_test_fail_activity
             BEFORE INSERT ON activity_logs
             BEGIN
                 SELECT RAISE(ABORT, 'SYNTHETIC_AUDIT_FAILURE');
             END"
        );

        try {
            $this->post(
                $this->url('confirm', $issue),
                $fields
            )->assertOk()
                ->assertSee('Gagal menyimpan');
        } finally {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS outbound_test_fail_activity'
            );
        }

        $this->assertSame(
            'IN_PROGRESS',
            $issue->fresh()->status
        );

        $this->assertDatabaseCount('activity_logs', 0);

        $this->assertNoOutboundIntent();
    }

    public function test_O13_outbox_failure_rolls_back_status_and_children(): void
    {
        $parent = $this->issue('IN_PROGRESS');

        $child = $this->issue('DUPLICATE', [
            'github_number' => 43,
            'source_url' => 'https://github.com/'
                .self::REPOSITORY.'/issues/43',
            'duplicate_of' => $parent->id,
        ]);

        $this->actingAs($this->project->owner);

        $fields = $this->confirmationFields(
            $parent,
            $this->preview($parent, 'RESOLVED')
        );

        // Deliberate failure on the isolated in-memory outbox only.
        DB::unprepared(
            "CREATE TRIGGER outbound_test_fail_outbox
             BEFORE INSERT ON github_issue_status_outbox
             BEGIN
                 SELECT RAISE(ABORT, 'SYNTHETIC_OUTBOX_FAILURE');
             END"
        );

        try {
            $this->post(
                $this->url('confirm', $parent),
                $fields
            )->assertOk()
                ->assertSee('Gagal menyimpan');
        } finally {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS outbound_test_fail_outbox'
            );
        }

        $this->assertDatabaseHas('issues', [
            'id' => $parent->id,
            'status' => 'IN_PROGRESS',
        ]);

        $this->assertDatabaseHas('issues', [
            'id' => $child->id,
            'status' => 'DUPLICATE',
            'duplicate_of' => $parent->id,
        ]);

        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount(self::OUTBOX, 0);

        Http::assertNothingSent();
    }

    public function test_O14_successive_intents_have_increasing_revisions(): void
    {
        $issue = $this->issue('IN_PROGRESS');

        $this->confirmChange($issue, 'RESOLVED');
        $this->confirmChange($issue, 'IN_PROGRESS');

        $rows = DB::table(self::OUTBOX)
            ->where('issue_id', $issue->id)
            ->orderBy('revision')
            ->get();

        $this->assertCount(2, $rows);

        $this->assertSame('closed', $rows[0]->desired_state);
        $this->assertSame('open', $rows[1]->desired_state);

        $this->assertGreaterThan(
            (int) $rows[0]->revision,
            (int) $rows[1]->revision
        );

        foreach ($rows as $row) {
            $this->assertDatabaseHas('activity_logs', [
                'id' => $row->revision,
                'project_id' => $issue->project_id,
                'issue_id' => $issue->id,
                'action' => 'ISSUE_STATUS_CHANGED',
            ]);
        }

        $this->assertSame(
            'IN_PROGRESS',
            $issue->fresh()->status
        );

        Http::assertNothingSent();
    }

    public function test_O15_missing_connection_keeps_change_local(): void
    {
        $issue = $this->issue('IN_PROGRESS');

        // Remove only the synthetic connection fixture from SQLite memory.
        $this->connection->delete();

        $this->confirmChange($issue, 'RESOLVED');

        $this->assertDatabaseCount(self::OUTBOX, 0);

        Http::assertNothingSent();
    }
}
