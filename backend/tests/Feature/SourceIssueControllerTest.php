<?php

namespace Tests\Feature;

use App\Http\Controllers\SourceIssueController;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SourceIssueControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Route testing untuk halaman Sumber Issue.
        Route::middleware('web')
            ->get(
                '/__test/projects/{project}/sources',
                [SourceIssueController::class, 'index']
            )
            ->name('test.sources.index');

        // Route testing untuk upload dan preview CSV.
        Route::middleware('web')
            ->post(
                '/__test/projects/{project}/sources/csv/preview',
                [SourceIssueController::class, 'preview']
            )
            ->name('test.sources.csv.preview');

        // Route testing untuk konfirmasi impor CSV.
        Route::middleware('web')
            ->post(
                '/__test/projects/{project}/sources/csv/import',
                [SourceIssueController::class, 'importCsv']
            )
            ->name('test.sources.csv.import');

        // Perbarui lookup named route.
        Route::getRoutes()->refreshNameLookups();
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER METHODS
    |--------------------------------------------------------------------------
    */

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function makeProject(User $owner): Project
    {
        $project = new Project([
            'name' => 'Source Test ' . uniqid(),
            'description' => 'Project pengujian Feature 11',
        ]);

        $project->owner_id = $owner->id;
        $project->save();

        return $project;
    }

    private function sourceUrl(Project $project): string
    {
        return route('test.sources.index', [
            'project' => $project->id,
        ]);
    }

    private function importUrl(Project $project): string
    {
        return route('test.sources.csv.import', [
            'project' => $project->id,
        ]);
    }

    private function createPendingCsvPreview(
        User $owner,
        Project $project,
        ?string $csv = null
    ): array {
        $csv ??= "source_id,title,description\n"
            . "BUG-001,Login gagal,Pengguna tidak bisa masuk aplikasi\n";

        $file = UploadedFile::fake()->createWithContent(
            'dataset.csv',
            $csv
        );

        $this->actingAs($owner)
            ->post(
                route('test.sources.csv.preview', [
                    'project' => $project->id,
                ]),
                ['file' => $file]
            )
            ->assertOk();

        return session('source_issue.csv_preview');
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 1–4: OTORISASI HALAMAN SUMBER ISSUE
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_open_source_issue_page(): void
    {
        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $this->actingAs($owner)
            ->get($this->sourceUrl($project))
            ->assertOk()
            ->assertViewIs('sources.index')
            ->assertViewHas('project')
            ->assertSee('Sumber Issue')
            ->assertSee('Upload Dataset')
            ->assertSee('GitHub')
            ->assertSee($project->name);
    }

    public function test_reporter_member_cannot_open_source_issue_page(): void
    {
        $owner = $this->makeUser('MAINTAINER');
        $reporter = $this->makeUser('REPORTER');
        $project = $this->makeProject($owner);

        // Reporter merupakan anggota aktif project.
        Membership::create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => true,
            'joined_at' => now(),
            'removed_at' => null,
        ]);

        $this->actingAs($reporter)
            ->get($this->sourceUrl($project))
            ->assertForbidden();
    }

    public function test_other_maintainer_cannot_open_source_issue_page(): void
    {
        $owner = $this->makeUser('MAINTAINER');
        $otherMaintainer = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $this->actingAs($otherMaintainer)
            ->get($this->sourceUrl($project))
            ->assertForbidden();
    }

    public function test_guest_cannot_open_source_issue_page(): void
    {
        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $this->get($this->sourceUrl($project))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 5–8: UPLOAD DAN PREVIEW CSV
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_preview_csv_without_importing(): void
    {
        Storage::fake('local');

        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $csv = "source_id,title,description\n"
            . "BUG-001,Login gagal,Pengguna tidak bisa masuk aplikasi\n";

        $file = UploadedFile::fake()->createWithContent(
            'dataset.csv',
            $csv
        );

        $response = $this->actingAs($owner)->post(
            route('test.sources.csv.preview', [
                'project' => $project->id,
            ]),
            ['file' => $file]
        );

        $response->assertOk();
        $response->assertViewIs('sources.index');

        $response->assertViewHas(
            'csvPreview',
            fn ($preview) =>
                $preview['valid_count'] === 1
                && $preview['invalid_count'] === 0
                && $preview['already_imported_count'] === 0
        );

        $response->assertSessionHas(
            'source_issue.csv_preview'
        );

        $sessionData = session('source_issue.csv_preview');

        $this->assertSame(
            (int) $project->id,
            $sessionData['project_id']
        );

        $this->assertSame(
            (int) $owner->id,
            $sessionData['actor_id']
        );

        $this->assertSame(
            hash('sha256', $csv),
            $sessionData['sha256']
        );

        $this->assertGreaterThan(
            now()->timestamp,
            $sessionData['expires_at']
        );

        Storage::disk('local')->assertExists(
            $sessionData['path']
        );

        // Preview tidak boleh menyimpan issue.
        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_invalid_file_is_rejected_without_storage(): void
    {
        Storage::fake('local');

        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $file = UploadedFile::fake()->createWithContent(
            'dataset.txt',
            "source_id,title,description\n"
            . "BUG-001,Login gagal,Pengguna tidak bisa masuk aplikasi\n"
        );

        $url = route('test.sources.csv.preview', [
            'project' => $project->id,
        ]);

        $this->actingAs($owner)
            ->from($this->sourceUrl($project))
            ->post($url, ['file' => $file])
            ->assertRedirect($this->sourceUrl($project))
            ->assertSessionHasErrors('file');

        $this->assertNull(
            session('source_issue.csv_preview')
        );

        $this->assertSame(
            [],
            Storage::disk('local')->allFiles(
                'source-issue-previews'
            )
        );

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_reporter_cannot_upload_csv_preview(): void
    {
        Storage::fake('local');

        $owner = $this->makeUser('MAINTAINER');
        $reporter = $this->makeUser('REPORTER');
        $project = $this->makeProject($owner);

        $file = UploadedFile::fake()->createWithContent(
            'dataset.csv',
            "source_id,title,description\n"
            . "BUG-001,Login gagal,Pengguna tidak bisa masuk aplikasi\n"
        );

        $this->actingAs($reporter)
            ->post(
                route('test.sources.csv.preview', [
                    'project' => $project->id,
                ]),
                ['file' => $file]
            )
            ->assertForbidden();

        $this->assertNull(
            session('source_issue.csv_preview')
        );

        $this->assertSame(
            [],
            Storage::disk('local')->allFiles(
                'source-issue-previews'
            )
        );

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_new_preview_replaces_previous_temporary_file(): void
    {
        Storage::fake('local');

        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $url = route('test.sources.csv.preview', [
            'project' => $project->id,
        ]);

        $firstFile = UploadedFile::fake()->createWithContent(
            'first.csv',
            "source_id,title,description\n"
            . "BUG-001,Login gagal,Pengguna tidak bisa masuk aplikasi\n"
        );

        $this->actingAs($owner)
            ->post($url, ['file' => $firstFile])
            ->assertOk();

        $firstPath = session(
            'source_issue.csv_preview.path'
        );

        Storage::disk('local')->assertExists($firstPath);

        $secondFile = UploadedFile::fake()->createWithContent(
            'second.csv',
            "source_id,title,description\n"
            . "BUG-002,Dashboard error,Dashboard tidak bisa dibuka\n"
        );

        $this->post(
            $url,
            ['file' => $secondFile]
        )->assertOk();

        $secondPath = session(
            'source_issue.csv_preview.path'
        );

        $this->assertNotSame($firstPath, $secondPath);

        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);

        $this->assertCount(
            1,
            Storage::disk('local')->allFiles(
                'source-issue-previews'
            )
        );

        $this->assertDatabaseCount('issues', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 9: INTEGRASI FORM DENGAN ROUTE PREVIEW
    |--------------------------------------------------------------------------
    */

    public function test_csv_upload_form_connects_to_preview_route(): void
    {
        Storage::fake('local');

        // Simulasikan route produksi tanpa mengubah routes/web.php.
        Route::middleware('web')
            ->post(
                '/__test/projects/{project}/sources/csv/form-preview',
                [SourceIssueController::class, 'preview']
            )
            ->name('sources.csv.preview');

        Route::getRoutes()->refreshNameLookups();

        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $previewUrl = route('sources.csv.preview', [
            'project' => $project->id,
        ]);

        // Periksa form yang ditampilkan kepada Maintainer.
        $response = $this->actingAs($owner)
            ->get($this->sourceUrl($project));

        $response->assertOk();

        $response->assertSee(
            'action="' . $previewUrl . '"',
            false
        );

        $response->assertSee(
            'enctype="multipart/form-data"',
            false
        );

        $response->assertSee(
            'name="file"',
            false
        );

        $response->assertDontSee(
            'Upload melalui browser belum diaktifkan.'
        );

        // Tombol submit tidak boleh disabled.
        $this->assertMatchesRegularExpression(
            '/<button\b(?=[^>]*type="submit")(?![^>]*disabled)[^>]*>/s',
            $response->getContent()
        );

        // Simulasikan upload melalui URL yang dipakai form.
        $csv = "source_id,title,description\n"
            . "BUG-FORM-001,Login gagal,Pengguna tidak bisa masuk aplikasi\n";

        $file = UploadedFile::fake()->createWithContent(
            'dataset.csv',
            $csv
        );

        $uploadResponse = $this->post(
            $previewUrl,
            ['file' => $file]
        );

        $uploadResponse->assertOk();
        $uploadResponse->assertViewIs('sources.index');

        $uploadResponse->assertViewHas(
            'csvPreview',
            fn ($preview) =>
                $preview['valid_count'] === 1
                && $preview['invalid_count'] === 0
        );

        $uploadResponse->assertSessionHas(
            'source_issue.csv_preview'
        );

        // Preview tidak boleh langsung menyimpan issue.
        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 10–16: KONFIRMASI IMPOR CSV
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_confirm_csv_import(): void
    {
        Storage::fake('local');

        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $csv = "source_id,title,description\n"
            . "BUG-001,Login gagal,Pengguna tidak bisa masuk aplikasi\n"
            . "BUG-002,Dashboard error,Dashboard tidak bisa dibuka\n"
            . "BUG-BAD,No,pendek\n";

        $pending = $this->createPendingCsvPreview(
            $owner,
            $project,
            $csv
        );

        $this->from($this->sourceUrl($project))
            ->post($this->importUrl($project))
            ->assertRedirect(
                route('sources.index', [
                    'project' => $project->id,
                ])
            )
            ->assertSessionHas('success')
            ->assertSessionHas(
                'csv_import_result.imported_count',
                2
            )
            ->assertSessionMissing(
                'source_issue.csv_preview'
            );

        $this->assertDatabaseCount('issues', 2);
        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseCount('activity_logs', 1);

        $this->assertDatabaseHas('import_batches', [
            'project_id' => $project->id,
            'actor_id' => $owner->id,
            'source_type' => 'CSV',
            'total_rows' => 3,
            'valid_rows' => 2,
            'skipped_rows' => 1,
            'status' => 'COMPLETED',
        ]);

        $this->assertDatabaseHas('issues', [
            'project_id' => $project->id,
            'source_type' => 'CSV',
            'source_id' => 'BUG-001',
            'status' => 'OPEN',
            'detection_status' => 'PENDING',
            'author_id' => null,
        ]);

        Storage::disk('local')->assertMissing(
            $pending['path']
        );
    }

    public function test_import_without_preview_is_rejected(): void
    {
        Storage::fake('local');

        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $this->actingAs($owner)
            ->from($this->sourceUrl($project))
            ->post($this->importUrl($project))
            ->assertRedirect(
                route('sources.index', [
                    'project' => $project->id,
                ])
            )
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_expired_preview_cannot_be_imported(): void
    {
        Storage::fake('local');

        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $pending = $this->createPendingCsvPreview(
            $owner,
            $project
        );

        $pending['expires_at'] = now()
            ->subMinute()
            ->timestamp;

        $this->withSession([
            'source_issue.csv_preview' => $pending,
        ])
            ->from($this->sourceUrl($project))
            ->post($this->importUrl($project))
            ->assertSessionHasErrors('file')
            ->assertSessionMissing(
                'source_issue.csv_preview'
            );

        Storage::disk('local')->assertMissing(
            $pending['path']
        );

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_modified_csv_cannot_be_imported(): void
    {
        Storage::fake('local');

        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $pending = $this->createPendingCsvPreview(
            $owner,
            $project
        );

        // Ubah file setelah preview untuk memicu hash mismatch.
        Storage::disk('local')->put(
            $pending['path'],
            "source_id,title,description\n"
            . "BUG-CHANGED,Issue berubah,Deskripsi sudah dimodifikasi\n"
        );

        $this->from($this->sourceUrl($project))
            ->post($this->importUrl($project))
            ->assertSessionHasErrors('file')
            ->assertSessionMissing(
                'source_issue.csv_preview'
            );

        Storage::disk('local')->assertMissing(
            $pending['path']
        );

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_second_confirmation_cannot_duplicate_import(): void
    {
        Storage::fake('local');

        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $this->createPendingCsvPreview($owner, $project);

        $this->from($this->sourceUrl($project))
            ->post($this->importUrl($project))
            ->assertSessionHas('success');

        // Session preview sudah dihapus setelah konfirmasi pertama.
        $this->from($this->sourceUrl($project))
            ->post($this->importUrl($project))
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_preview_from_other_project_cannot_be_imported(): void
    {
        Storage::fake('local');

        $owner = $this->makeUser('MAINTAINER');
        $projectA = $this->makeProject($owner);
        $projectB = $this->makeProject($owner);

        $pending = $this->createPendingCsvPreview(
            $owner,
            $projectA
        );

        $this->from($this->sourceUrl($projectB))
            ->post($this->importUrl($projectB))
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);

        Storage::disk('local')->assertExists(
            $pending['path']
        );
    }

    public function test_reporter_cannot_confirm_csv_import(): void
    {
        Storage::fake('local');

        $owner = $this->makeUser('MAINTAINER');
        $reporter = $this->makeUser('REPORTER');
        $project = $this->makeProject($owner);

        $pending = $this->createPendingCsvPreview(
            $owner,
            $project
        );

        $this->actingAs($reporter)
            ->post($this->importUrl($project))
            ->assertForbidden();

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);

        Storage::disk('local')->assertExists(
            $pending['path']
        );
    }


    public function test_csv_import_button_connects_to_confirmation_route(): void
    {
        Storage::fake('local');

        // Simulasikan route konfirmasi produksi.
        // Tidak mengubah routes/web.php.
        Route::middleware('web')
            ->post(
                '/__test/projects/{project}/sources/csv/form-import',
                [SourceIssueController::class, 'importCsv']
            )
            ->name('sources.csv.import');

        Route::getRoutes()->refreshNameLookups();

        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        // Upload CSV agar halaman menampilkan hasil preview.
        $file = UploadedFile::fake()->createWithContent(
            'dataset.csv',
            "source_id,title,description\n"
            . "BUG-FORM-002,Login gagal,Pengguna tidak bisa masuk aplikasi\n"
        );

        $previewResponse = $this->actingAs($owner)
            ->post(
                route('test.sources.csv.preview', [
                    'project' => $project->id,
                ]),
                ['file' => $file]
            );

        $previewResponse->assertOk();

        $previewResponse->assertViewHas(
            'csvPreview',
            fn ($preview) => $preview['valid_count'] === 1
        );

        $importUrl = route('sources.csv.import', [
            'project' => $project->id,
        ]);

        $html = $previewResponse->getContent();

        // Pastikan form impor menggunakan endpoint yang benar.
        $matched = preg_match(
            '~<form\b'
            . '(?=[^>]*action="' . preg_quote($importUrl, '~') . '")'
            . '(?=[^>]*method="POST")'
            . '[^>]*>(.*?)</form>~s',
            $html,
            $matches
        );

        $this->assertSame(1, $matched);

        // Form harus memiliki CSRF token.
        $this->assertStringContainsString(
            'name="_token"',
            $matches[1]
        );

        // Tombol konfirmasi harus aktif dan bertipe submit.
        $this->assertMatchesRegularExpression(
            '/<button\b(?=[^>]*type="submit")'
            . '(?![^>]*disabled)[^>]*>/',
            $matches[1]
        );

        // Preview belum menyimpan issue.
        $this->assertDatabaseCount('issues', 0);

        // Kirim konfirmasi ke URL yang digunakan form.
        $this->from($this->sourceUrl($project))
            ->post($importUrl)
            ->assertRedirect(
                route('sources.index', [
                    'project' => $project->id,
                ])
            )
            ->assertSessionHas('success')
            ->assertSessionHas(
                'csv_import_result.imported_count',
                1
            );

        // Issue baru tersimpan setelah konfirmasi.
        $this->assertDatabaseHas('issues', [
            'project_id' => $project->id,
            'source_type' => 'CSV',
            'source_id' => 'BUG-FORM-002',
            'status' => 'OPEN',
        ]);

        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseCount('activity_logs', 1);
    }


    public function test_failed_import_from_preview_redirects_to_source_page(): void
    {
        Storage::fake('local');

        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $sourceUrl = route('sources.index', [
            'project' => $project->id,
        ]);

        $previewUrl = route('sources.csv.preview', [
            'project' => $project->id,
        ]);

        // Simulasi pengguna berada pada halaman hasil POST preview,
        // tetapi session file sementara sudah tidak tersedia.
        $response = $this->actingAs($owner)
            ->from($previewUrl)
            ->post($this->importUrl($project));

        // Kegagalan harus diarahkan ke halaman GET yang valid.
        $response
            ->assertRedirect($sourceUrl)
            ->assertSessionHasErrors('file');

        // Halaman tujuan harus dapat dibuka.
        $this->get($sourceUrl)->assertOk();

        // Tidak boleh menyimpan issue ketika konfirmasi gagal.
        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);
    }


}
