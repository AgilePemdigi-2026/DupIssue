<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Project;
use App\Services\GithubIssuePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GithubIssuePreviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private function githubIssue(int $number): array
    {
        return [
            'number' => $number,
            'title' => 'Tombol pembayaran tidak berfungsi',
            'body' => 'Tombol pembayaran tidak merespons saat diklik.',
        ];
    }

    private function createImportedIssue(
        Project $project,
        string $repo,
        int $number
    ): Issue {
        return Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => null,
            'title' => 'Issue GitHub sebelumnya',
            'description' => 'Issue yang telah diimpor sebelumnya.',
            'status' => 'OPEN',
            'detection_status' => 'PENDING',
            'source_type' => 'GITHUB',
            'github_repo' => $repo,
            'github_number' => $number,
            'source_url' => "https://github.com/{$repo}/issues/{$number}",
        ]);
    }

    public function test_new_github_issue_is_valid(): void
    {
        $project = Project::factory()->create();

        $result = (new GithubIssuePreviewService())->preview(
            $project,
            'demo/bugs',
            [$this->githubIssue(10)]
        );

        $this->assertSame(1, $result['total_rows']);
        $this->assertSame(1, $result['valid_count']);
        $this->assertSame(0, $result['invalid_count']);
        $this->assertSame(0, $result['already_imported_count']);
        $this->assertSame('VALID', $result['rows'][0]['status']);

        $this->assertSame(
            'https://github.com/demo/bugs/issues/10',
            $result['rows'][0]['data']['source_url']
        );

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_invalid_github_issue_is_rejected(): void
    {
        $project = Project::factory()->create();

        $issues = [
            [
                'number' => 11,
                'title' => 'Bug',
                'body' => 'Deskripsi cukup panjang.',
            ],
            [
                'number' => 12,
                'title' => 'Issue tanpa deskripsi',
                'body' => null,
            ],
            [
                'number' => -1,
                'title' => 'Nomor issue tidak valid',
                'body' => 'Deskripsi issue yang valid.',
            ],
        ];

        $result = (new GithubIssuePreviewService())->preview(
            $project,
            'demo/bugs',
            $issues
        );

        $this->assertSame(3, $result['total_rows']);
        $this->assertSame(0, $result['valid_count']);
        $this->assertSame(3, $result['invalid_count']);

        foreach ($result['rows'] as $row) {
            $this->assertSame('INVALID', $row['status']);
            $this->assertNotEmpty($row['reason']);
        }

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_already_imported_issue_is_detected(): void
    {
        $project = Project::factory()->create();

        $this->createImportedIssue(
            $project,
            'demo/bugs',
            20
        );

        $result = (new GithubIssuePreviewService())->preview(
            $project,
            'demo/bugs',
            [$this->githubIssue(20)]
        );

        $this->assertSame(0, $result['valid_count']);
        $this->assertSame(1, $result['already_imported_count']);
        $this->assertSame(
            'ALREADY_IMPORTED',
            $result['rows'][0]['status']
        );

        $this->assertDatabaseCount('issues', 1);
    }

    public function test_other_project_does_not_block_import(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        $this->createImportedIssue(
            $projectA,
            'demo/bugs',
            30
        );

        $result = (new GithubIssuePreviewService())->preview(
            $projectB,
            'demo/bugs',
            [$this->githubIssue(30)]
        );

        $this->assertSame(1, $result['valid_count']);
        $this->assertSame('VALID', $result['rows'][0]['status']);

        $this->assertDatabaseCount('issues', 1);
    }

    public function test_other_repository_does_not_block_import(): void
    {
        $project = Project::factory()->create();

        $this->createImportedIssue(
            $project,
            'demo/other-repo',
            40
        );

        $result = (new GithubIssuePreviewService())->preview(
            $project,
            'demo/bugs',
            [$this->githubIssue(40)]
        );

        $this->assertSame(1, $result['valid_count']);
        $this->assertSame('VALID', $result['rows'][0]['status']);

        $this->assertDatabaseCount('issues', 1);
    }

    public function test_pull_requests_are_excluded(): void
    {
        $project = Project::factory()->create();

        $pullRequest = $this->githubIssue(50);
        $pullRequest['pull_request'] = [
            'url' => 'https://api.github.com/repos/demo/bugs/pulls/50',
        ];

        $result = (new GithubIssuePreviewService())->preview(
            $project,
            'demo/bugs',
            [
                $pullRequest,
                $this->githubIssue(51),
            ]
        );

        $this->assertSame(1, $result['total_rows']);
        $this->assertSame(1, $result['valid_count']);
        $this->assertSame(
            51,
            $result['rows'][0]['data']['github_number']
        );

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_duplicate_numbers_in_preview_are_rejected(): void
    {
        $project = Project::factory()->create();

        $result = (new GithubIssuePreviewService())->preview(
            $project,
            'demo/bugs',
            [
                $this->githubIssue(60),
                $this->githubIssue(60),
            ]
        );

        $this->assertSame(2, $result['total_rows']);
        $this->assertSame(1, $result['valid_count']);
        $this->assertSame(1, $result['invalid_count']);

        $this->assertSame('VALID', $result['rows'][0]['status']);
        $this->assertSame('INVALID', $result['rows'][1]['status']);

        $this->assertDatabaseCount('issues', 0);
    }
}
