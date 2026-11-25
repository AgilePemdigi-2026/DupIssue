<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\CsvIssuePreviewService;
use App\Services\CsvIssueReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CsvIssuePreviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(): Project
    {
        $owner = User::factory()->create();
        $owner->role = 'MAINTAINER';
        $owner->save();

        $project = new Project([
            'name' => 'CSV Preview Test '.uniqid(),
            'description' => 'Project pengujian Feature 11',
        ]);

        $project->owner_id = $owner->id;
        $project->save();

        return $project;
    }

    private function makeCsvIssue(
        Project $project,
        string $sourceId
    ): Issue {
        $issue = new Issue([
            'title' => 'Login gagal',
            'description' => 'Pengguna tidak bisa login ke aplikasi.',
        ]);

        $issue->project()->associate($project);
        $issue->source_type = 'CSV';
        $issue->source_id = $sourceId;
        $issue->status = 'OPEN';
        $issue->detection_status = 'PENDING';
        $issue->save();

        return $issue;
    }

    private function preview(Project $project, string $csv): array
    {
        $file = UploadedFile::fake()->createWithContent(
            'dataset.csv',
            $csv
        );

        $parsed = app(CsvIssueReader::class)->read($file);

        return app(CsvIssuePreviewService::class)
            ->preview($project, $parsed);
    }

    public function test_preview_classifies_rows_correctly(): void
    {
        $project = $this->makeProject();
        $this->makeCsvIssue($project, 'BUG-001');

        $csv = "source_id,title,description\n".
            "BUG-001,Login gagal,Pengguna gagal masuk aplikasi\n".
            "BUG-002,Dashboard error,Dashboard tidak bisa dibuka\n".
            "BUG-002,Dashboard error,Dashboard tidak bisa dibuka\n".
            "BUG-003,Error modul,pendek\n".
            "BUG-004,Halaman kosong,Konten halaman tidak muncul\n";

        $result = $this->preview($project, $csv);

        $this->assertSame(5, $result['total_rows']);
        $this->assertSame(2, $result['valid_count']);
        $this->assertSame(2, $result['invalid_count']);
        $this->assertSame(1, $result['already_imported_count']);

        $this->assertSame(
            [
                'ALREADY_IMPORTED',
                'VALID',
                'INVALID',
                'INVALID',
                'VALID',
            ],
            array_column($result['rows'], 'status')
        );

        // Preview tidak boleh membuat issue ataupun batch.
        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_same_source_id_in_different_project_is_valid(): void
    {
        $projectA = $this->makeProject();
        $projectB = $this->makeProject();

        $this->makeCsvIssue($projectA, 'BUG-100');

        $result = $this->preview(
            $projectB,
            "source_id,title,description\n".
            "BUG-100,Login gagal,Pengguna gagal masuk aplikasi\n"
        );

        $this->assertSame(1, $result['valid_count']);
        $this->assertSame(0, $result['already_imported_count']);
        $this->assertSame('VALID', $result['rows'][0]['status']);

        $this->assertDatabaseCount('issues', 1);
    }

    public function test_duplicate_source_id_inside_csv_is_invalid(): void
    {
        $project = $this->makeProject();

        $result = $this->preview(
            $project,
            "source_id,title,description\n".
            "BUG-200,Login gagal,Pengguna gagal masuk aplikasi\n".
            "bug-200,Login gagal,Pengguna gagal masuk aplikasi\n"
        );

        $this->assertSame(1, $result['valid_count']);
        $this->assertSame(1, $result['invalid_count']);

        $this->assertSame('VALID', $result['rows'][0]['status']);
        $this->assertSame('INVALID', $result['rows'][1]['status']);

        $this->assertStringContainsString(
            'berulang',
            $result['rows'][1]['reason']
        );
    }

    public function test_malformed_and_empty_fields_are_invalid(): void
    {
        $project = $this->makeProject();

        $result = $this->preview(
            $project,
            "source_id,title,description\n".
            "BUG-301,Login gagal\n".
            "BUG-302,,Deskripsi yang cukup panjang\n".
            "BUG-303,Judul valid,Deskripsi yang cukup panjang\n"
        );

        $this->assertSame(3, $result['total_rows']);
        $this->assertSame(1, $result['valid_count']);
        $this->assertSame(2, $result['invalid_count']);

        $this->assertSame('INVALID', $result['rows'][0]['status']);
        $this->assertSame('INVALID', $result['rows'][1]['status']);
        $this->assertSame('VALID', $result['rows'][2]['status']);

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_existing_source_id_is_case_insensitive(): void
    {
        $project = $this->makeProject();

        $this->makeCsvIssue($project, 'BUG-500');

        $result = $this->preview(
            $project,
            "source_id,title,description\n".
            "bug-500,Login gagal,Pengguna gagal masuk aplikasi\n"
        );

        $this->assertSame(0, $result['valid_count']);
        $this->assertSame(1, $result['already_imported_count']);

        $this->assertSame(
            'ALREADY_IMPORTED',
            $result['rows'][0]['status']
        );

        $this->assertDatabaseCount('issues', 1);
    }
}