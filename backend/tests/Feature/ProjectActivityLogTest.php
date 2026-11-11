<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProjectActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $reporter = User::factory()->create(['role' => 'REPORTER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $reporter, $project];
    }

    public function test_create_project_writes_activity_log(): void
    {
        $maintainer = User::factory()->create(['role' => 'MAINTAINER']);
        $service = app(ProjectService::class);

        $project = $service->createProject($maintainer, [
            'name' => 'Project Alpha',
            'description' => 'Deskripsi Alpha',
        ]);

        $this->assertDatabaseCount('activity_logs', 1);
        $log = DB::table('activity_logs')->first();

        $this->assertSame((int) $project->id, (int) $log->project_id);
        $this->assertSame((int) $maintainer->id, (int) $log->user_id);
        $this->assertNull($log->issue_id);
        $this->assertSame('CREATE_PROJECT', $log->action);
        $this->assertNull($log->old_values);
        $this->assertNotNull($log->new_values);
        $this->assertNotNull($log->created_at);
        $this->assertNotNull($log->updated_at);

        $newValues = json_decode($log->new_values, true);
        $this->assertSame('Project Alpha', $newValues['name']);
        $this->assertSame('Deskripsi Alpha', $newValues['description']);
        $this->assertSame((int) $maintainer->id, (int) $project->owner_id);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Project Alpha',
            'owner_id' => $maintainer->id,
        ]);
    }

    public function test_update_project_writes_activity_log(): void
    {
        [$owner, , $project] = $this->fixture();
        $service = app(ProjectService::class);
        $oldName = $project->name;
        $oldDesc = $project->description;

        $service->updateProject($owner, $project, [
            'name' => 'Project Renamed',
            'description' => 'Deskripsi Baru',
        ]);

        $this->assertDatabaseCount('activity_logs', 1);
        $log = DB::table('activity_logs')->first();

        $this->assertSame((int) $project->id, (int) $log->project_id);
        $this->assertSame((int) $owner->id, (int) $log->user_id);
        $this->assertNull($log->issue_id);
        $this->assertSame('UPDATE_PROJECT', $log->action);

        $oldValues = json_decode($log->old_values, true);
        $newValues = json_decode($log->new_values, true);

        $this->assertSame($oldName, $oldValues['name']);
        $this->assertSame($oldDesc, $oldValues['description']);
        $this->assertSame('Project Renamed', $newValues['name']);
        $this->assertSame('Deskripsi Baru', $newValues['description']);
    }

    public function test_invite_reporter_writes_activity_log(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $service = app(ProjectService::class);

        $invitation = $service->inviteReporter($owner, $project, $reporter->email);

        $this->assertDatabaseCount('activity_logs', 1);
        $log = DB::table('activity_logs')->first();

        $this->assertSame((int) $project->id, (int) $log->project_id);
        $this->assertSame((int) $owner->id, (int) $log->user_id);
        $this->assertNull($log->issue_id);
        $this->assertSame('INVITE_REPORTER', $log->action);
        $this->assertNull($log->old_values);

        $newValues = json_decode($log->new_values, true);
        $this->assertSame((int) $invitation->id, (int) $newValues['invitation_id']);
        $this->assertSame((int) $reporter->id, (int) $newValues['reporter_id']);
        $this->assertSame($reporter->email, $newValues['email']);
        $this->assertSame('PENDING', $newValues['status']);
    }

    public function test_cancel_invitation_writes_activity_log(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $invitation = Invitation::factory()->create([
            'project_id' => $project->id,
            'invited_by' => $owner->id,
            'reporter_id' => $reporter->id,
            'status' => 'PENDING',
        ]);
        $service = app(ProjectService::class);

        $service->cancelInvitation($owner, $invitation);

        $this->assertDatabaseCount('activity_logs', 1);
        $log = DB::table('activity_logs')->first();

        $this->assertSame((int) $project->id, (int) $log->project_id);
        $this->assertSame((int) $owner->id, (int) $log->user_id);
        $this->assertNull($log->issue_id);
        $this->assertSame('CANCEL_INVITATION', $log->action);

        $oldValues = json_decode($log->old_values, true);
        $newValues = json_decode($log->new_values, true);

        $this->assertSame((int) $invitation->id, (int) $oldValues['invitation_id']);
        $this->assertSame('PENDING', $oldValues['status']);
        $this->assertSame((int) $invitation->id, (int) $newValues['invitation_id']);
        $this->assertSame('CANCELED', $newValues['status']);
    }

    public function test_remove_member_writes_activity_log(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $membership = Membership::factory()->create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => true,
        ]);
        $service = app(ProjectService::class);

        $service->removeMember($owner, $project, $reporter->id);

        $this->assertDatabaseCount('activity_logs', 1);
        $log = DB::table('activity_logs')->first();

        $this->assertSame((int) $project->id, (int) $log->project_id);
        $this->assertSame((int) $owner->id, (int) $log->user_id);
        $this->assertNull($log->issue_id);
        $this->assertSame('REMOVE_MEMBER', $log->action);

        $oldValues = json_decode($log->old_values, true);
        $newValues = json_decode($log->new_values, true);

        $this->assertSame((int) $membership->id, (int) $oldValues['membership_id']);
        $this->assertTrue($oldValues['is_active']);
        $this->assertSame((int) $membership->id, (int) $newValues['membership_id']);
        $this->assertFalse($newValues['is_active']);
    }

    public function test_accept_invitation_writes_activity_log(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $invitation = Invitation::factory()->create([
            'project_id' => $project->id,
            'invited_by' => $owner->id,
            'reporter_id' => $reporter->id,
            'status' => 'PENDING',
        ]);
        $service = app(ProjectService::class);

        $service->respondInvitation($reporter, $invitation, 'accept');

        $this->assertDatabaseCount('activity_logs', 1);
        $log = DB::table('activity_logs')->first();

        $this->assertSame((int) $project->id, (int) $log->project_id);
        $this->assertSame((int) $reporter->id, (int) $log->user_id);
        $this->assertNull($log->issue_id);
        $this->assertSame('ACCEPT_INVITATION', $log->action);

        $oldValues = json_decode($log->old_values, true);
        $newValues = json_decode($log->new_values, true);

        $this->assertSame('PENDING', $oldValues['status']);
        $this->assertSame('ACCEPTED', $newValues['status']);
        $this->assertTrue($newValues['is_active']);
        $this->assertNotNull($newValues['membership_id']);
    }

    public function test_reject_invitation_writes_activity_log(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $invitation = Invitation::factory()->create([
            'project_id' => $project->id,
            'invited_by' => $owner->id,
            'reporter_id' => $reporter->id,
            'status' => 'PENDING',
        ]);
        $service = app(ProjectService::class);

        $service->respondInvitation($reporter, $invitation, 'reject');

        $this->assertDatabaseCount('activity_logs', 1);
        $log = DB::table('activity_logs')->first();

        $this->assertSame((int) $project->id, (int) $log->project_id);
        $this->assertSame((int) $reporter->id, (int) $log->user_id);
        $this->assertNull($log->issue_id);
        $this->assertSame('REJECT_INVITATION', $log->action);

        $oldValues = json_decode($log->old_values, true);
        $newValues = json_decode($log->new_values, true);

        $this->assertSame('PENDING', $oldValues['status']);
        $this->assertSame('REJECTED', $newValues['status']);
    }

    public function test_rejected_actions_do_not_write_activity_log(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $otherMaintainer = User::factory()->create(['role' => 'MAINTAINER']);
        $service = app(ProjectService::class);

        // 1. Validation failure on project creation (name too short)
        try {
            $service->createProject($owner, ['name' => 'ab']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
            // expected
        }
        $this->assertDatabaseCount('activity_logs', 0);

        // 2. Authorization failure on project creation (reporter role)
        try {
            $service->createProject($reporter, ['name' => 'Valid Name']);
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException) {
            // expected
        }
        $this->assertDatabaseCount('activity_logs', 0);

        // 3. Authorization failure on project update (non-owner)
        try {
            $service->updateProject($otherMaintainer, $project, ['name' => 'New Name']);
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException) {
            // expected
        }
        $this->assertDatabaseCount('activity_logs', 0);

        // 4. Authorization failure on invite reporter (non-owner)
        try {
            $service->inviteReporter($otherMaintainer, $project, $reporter->email);
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException) {
            // expected
        }
        $this->assertDatabaseCount('activity_logs', 0);

        // 5. Validation failure on invite (non-reporter user)
        $anotherMaintainer = User::factory()->create(['role' => 'MAINTAINER']);
        try {
            $service->inviteReporter($owner, $project, $anotherMaintainer->email);
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
            // expected
        }
        $this->assertDatabaseCount('activity_logs', 0);

        // 6. Authorization failure on cancel invitation (non-owner)
        $invitation = Invitation::factory()->create([
            'project_id' => $project->id,
            'invited_by' => $owner->id,
            'reporter_id' => $reporter->id,
            'status' => 'PENDING',
        ]);
        try {
            $service->cancelInvitation($otherMaintainer, $invitation);
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException) {
            // expected
        }
        $this->assertDatabaseCount('activity_logs', 0);

        // 7. Authorization failure on remove member (non-owner)
        Membership::factory()->create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => true,
        ]);
        try {
            $service->removeMember($otherMaintainer, $project, $reporter->id);
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException) {
            // expected
        }
        $this->assertDatabaseCount('activity_logs', 0);

        // 8. Authorization failure on respond invitation (wrong user)
        $anotherReporter = User::factory()->create(['role' => 'REPORTER']);
        try {
            $service->respondInvitation($anotherReporter, $invitation, 'accept');
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException) {
            // expected
        }
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_transaction_rollback_leaves_no_activity_log(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $service = app(ProjectService::class);

        try {
            DB::transaction(function () use ($service, $owner, $project, $reporter): void {
                $service->inviteReporter($owner, $project, $reporter->email);
                // Verify log was written inside the transaction
                $this->assertDatabaseCount('activity_logs', 1);
                // Force an unexpected exception to trigger a rollback
                throw new Exception('Simulated unexpected failure during business transaction');
            });
            $this->fail('Expected Exception');
        } catch (Exception $e) {
            $this->assertSame('Simulated unexpected failure during business transaction', $e->getMessage());
        }

        // After rollback, the activity_logs table must be empty
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_reactivate_membership_writes_activity_log_with_same_membership_id(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $service = app(ProjectService::class);

        // 1. Reporter initial invitation and acceptance
        $invitation1 = $service->inviteReporter($owner, $project, $reporter->email);
        $service->respondInvitation($reporter, $invitation1, 'accept');
        $initialMembership = Membership::where('project_id', $project->id)
            ->where('user_id', $reporter->id)
            ->firstOrFail();
        $this->assertTrue($initialMembership->is_active);

        // 2. Member is removed
        $service->removeMember($owner, $project, $reporter->id);
        $this->assertFalse($initialMembership->fresh()->is_active);

        // 3. Clear logs to isolate the reactivation event
        DB::table('activity_logs')->delete();

        // 4. Member is invited again and accepts
        $invitation2 = $service->inviteReporter($owner, $project, $reporter->email);
        DB::table('activity_logs')->delete(); // delete invite log to verify only accept log

        $service->respondInvitation($reporter, $invitation2, 'accept');

        $this->assertDatabaseCount('activity_logs', 1);
        $log = DB::table('activity_logs')->first();

        $this->assertSame((int) $project->id, (int) $log->project_id);
        $this->assertSame((int) $reporter->id, (int) $log->user_id);
        $this->assertNull($log->issue_id);
        $this->assertSame('ACCEPT_INVITATION', $log->action);

        $oldValues = json_decode($log->old_values, true);
        $newValues = json_decode($log->new_values, true);

        // Membership ID must be identical across reactivation
        $this->assertSame((int) $initialMembership->id, (int) $oldValues['membership_id']);
        $this->assertSame((int) $initialMembership->id, (int) $newValues['membership_id']);
        $this->assertFalse($oldValues['is_active']);
        $this->assertTrue($newValues['is_active']);
        $this->assertSame('PENDING', $oldValues['status']);
        $this->assertSame('ACCEPTED', $newValues['status']);
    }

    public function test_create_project_rolls_back_when_log_insert_fails(): void
    {
        $maintainer = User::factory()->create(['role' => 'MAINTAINER']);
        $service = app(ProjectService::class);

        $failed = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$failed): void {
            if (! $failed && str_contains($sql, 'activity_logs')) {
                $failed = true;
                throw new Exception('Simulated failure during activity_logs insertion');
            }
        });

        try {
            $service->createProject($maintainer, [
                'name' => 'Atomic Rollback Project',
                'description' => 'Should not persist on log failure',
            ]);
            $this->fail('Expected Exception');
        } catch (Exception $e) {
            $this->assertSame('Simulated failure during activity_logs insertion', $e->getMessage());
        }

        $this->assertTrue($failed);
        $this->assertDatabaseMissing('projects', ['name' => 'Atomic Rollback Project']);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_update_project_rolls_back_when_log_insert_fails(): void
    {
        [$owner, , $project] = $this->fixture();
        $service = app(ProjectService::class);
        $originalName = $project->name;
        $originalDesc = $project->description;

        $failed = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$failed): void {
            if (! $failed && str_contains($sql, 'activity_logs')) {
                $failed = true;
                throw new Exception('Simulated failure during activity_logs update insertion');
            }
        });

        try {
            $service->updateProject($owner, $project, [
                'name' => 'Atomic Update Rollback',
                'description' => 'Should revert to original',
            ]);
            $this->fail('Expected Exception');
        } catch (Exception $e) {
            $this->assertSame('Simulated failure during activity_logs update insertion', $e->getMessage());
        }

        $this->assertTrue($failed);
        $this->assertSame($originalName, $project->fresh()->name);
        $this->assertSame($originalDesc, $project->fresh()->description);
        $this->assertDatabaseMissing('projects', ['name' => 'Atomic Update Rollback']);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_create_project_validation_failure_leaves_no_project_or_activity_log(): void
    {
        $maintainer = User::factory()->create(['role' => 'MAINTAINER']);
        $service = app(ProjectService::class);
        $initialTxLevel = DB::transactionLevel();

        // Pre-existing project to test duplicate validation
        $existing = Project::factory()->create(['name' => 'Existing Unique Project']);
        $initialProjectsCount = Project::count();

        // 1. Empty name
        try {
            $service->createProject($maintainer, ['name' => '']);
            $this->fail('Expected ValidationException for empty name');
        } catch (ValidationException) {
            // expected
        }

        // 2. Name too short (< 3 chars)
        try {
            $service->createProject($maintainer, ['name' => 'ab']);
            $this->fail('Expected ValidationException for short name');
        } catch (ValidationException) {
            // expected
        }

        // 3. Duplicate name (case-insensitive)
        try {
            $service->createProject($maintainer, ['name' => 'existing unique project']);
            $this->fail('Expected ValidationException for duplicate name');
        } catch (ValidationException) {
            // expected
        }

        // Verify no new projects and no activity logs were created, and no open transactions
        $this->assertSame($initialTxLevel, DB::transactionLevel(), 'Transaction must not be left open on validation failure');
        $this->assertSame($initialProjectsCount, Project::count());
        $this->assertDatabaseMissing('projects', ['name' => 'ab']);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_update_project_validation_failure_leaves_project_and_activity_log_unchanged(): void
    {
        [$owner, , $project] = $this->fixture();
        $otherProject = Project::factory()->create(['name' => 'Another Project']);
        $service = app(ProjectService::class);
        $initialTxLevel = DB::transactionLevel();

        $originalName = $project->name;
        $originalDesc = $project->description;

        // 1. Empty name
        try {
            $service->updateProject($owner, $project, ['name' => '']);
            $this->fail('Expected ValidationException for empty name');
        } catch (ValidationException) {
            // expected
        }

        // 2. Name too short (< 3 chars)
        try {
            $service->updateProject($owner, $project, ['name' => 'xy']);
            $this->fail('Expected ValidationException for short name');
        } catch (ValidationException) {
            // expected
        }

        // 3. Duplicate name belonging to another project
        try {
            $service->updateProject($owner, $project, ['name' => 'another project']);
            $this->fail('Expected ValidationException for duplicate name');
        } catch (ValidationException) {
            // expected
        }

        // Verify project data was NOT modified and NO activity logs were created, and no open transactions
        $this->assertSame($initialTxLevel, DB::transactionLevel(), 'Transaction must not be left open on validation failure');
        $fresh = $project->fresh();
        $this->assertSame($originalName, $fresh->name);
        $this->assertSame($originalDesc, $fresh->description);
        $this->assertDatabaseMissing('projects', ['name' => 'xy']);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    /**
     * Regression test P1: Memastikan penanganan ValidationException di dalam transaksi
     * pada createProject() TIDAK melakukan insert project liar setelah DB::rollBack().
     */
    public function test_create_project_duplicate_name_inside_transaction_rolls_back_without_orphan_insert(): void
    {
        $maintainer = User::factory()->create(['role' => 'MAINTAINER']);
        $service = app(ProjectService::class);

        // Buat project awal
        $existing = Project::factory()->create([
            'name' => 'Sistem Informasi DupIssue',
            'owner_id' => $maintainer->id,
        ]);
        $initialProjectsCount = Project::count();
        $initialTxLevel = DB::transactionLevel();

        // Coba buat project dengan nama sama persis atau beda kapitalisasi (case-insensitive)
        try {
            $service->createProject($maintainer, [
                'name' => 'sistem informasi dupissue',
                'description' => 'Deskripsi yang seharusnya tidak tersimpan',
            ]);
            $this->fail('Expected ValidationException for duplicate project name inside transaction');
        } catch (ValidationException $e) {
            $this->assertTrue($e->validator->errors()->has('name'));
            $this->assertSame('Nama sudah digunakan', $e->validator->errors()->first('name'));
        }

        // Verifikasi kondisi database pasca-exception:
        // 1. Transaction level harus kembali utuh (tidak ada transaksi menggantung)
        $this->assertSame($initialTxLevel, DB::transactionLevel(), 'TransactionLevel harus kembali ke tingkat awal');
        // 2. Jumlah project tidak boleh bertambah sama sekali (tidak ada orphan insert)
        $this->assertSame($initialProjectsCount, Project::count(), 'Tidak boleh ada project baru yang ter-insert setelah rollback');
        // 3. Deskripsi yang dioperasikan pada percobaan gagal tidak boleh ada di database
        $this->assertDatabaseMissing('projects', ['description' => 'Deskripsi yang seharusnya tidak tersimpan']);
        // 4. Tidak boleh ada activity log yang tersimpan dari operasi gagal
        $this->assertDatabaseCount('activity_logs', 0);
    }

    /**
     * Regression test P1: Memastikan penanganan ValidationException di dalam transaksi
     * pada updateProject() TIDAK melakukan insert project liar setelah DB::rollBack().
     */
    public function test_update_project_duplicate_name_inside_transaction_rolls_back_without_orphan_insert(): void
    {
        [$owner, , $projectA] = $this->fixture();
        $projectB = Project::factory()->create([
            'name' => 'Project Beta Eksklusif',
            'owner_id' => $owner->id,
        ]);

        $service = app(ProjectService::class);
        $initialProjectsCount = Project::count();
        $initialTxLevel = DB::transactionLevel();
        $originalProjectAName = $projectA->name;
        $originalProjectADesc = $projectA->description;

        // Coba update Project A menggunakan nama Project B (berbeda huruf besar/kecil)
        try {
            $service->updateProject($owner, $projectA, [
                'name' => 'project beta eksklusif',
                'description' => 'Deskripsi baru yang gagal',
            ]);
            $this->fail('Expected ValidationException for duplicate name on update');
        } catch (ValidationException $e) {
            $this->assertTrue($e->validator->errors()->has('name'));
            $this->assertSame('Nama sudah digunakan', $e->validator->errors()->first('name'));
        }

        // Verifikasi pasca-exception:
        // 1. Transaction level utuh
        $this->assertSame($initialTxLevel, DB::transactionLevel(), 'TransactionLevel harus kembali ke tingkat awal');
        // 2. Jumlah total project di database tidak boleh bertambah (tidak ada orphan insert di update)
        $this->assertSame($initialProjectsCount, Project::count(), 'Update gagal tidak boleh menciptakan project baru');
        // 3. Data projectA lama tidak boleh terubah
        $freshA = $projectA->fresh();
        $this->assertSame($originalProjectAName, $freshA->name);
        $this->assertSame($originalProjectADesc, $freshA->description);
        // 4. Tidak ada activity log dari operasi update yang gagal
        $this->assertDatabaseCount('activity_logs', 0);
    }

    /**
     * Regression test P1: Memastikan jika ValidationException dilempar setelah stage penyimpanan
     * di dalam transaksi, seluruh perubahan project di-rollback penuh dan tidak ada insert liar.
     */
    public function test_create_project_simulated_validation_exception_in_transaction_leaves_no_data(): void
    {
        $maintainer = User::factory()->create(['role' => 'MAINTAINER']);
        $service = app(ProjectService::class);
        $initialProjectsCount = Project::count();
        $initialTxLevel = DB::transactionLevel();

        // Simulasikan ValidationException terjadi tepat setelah record project di-save
        // dengan memanfaatkan event Eloquent atau beforeExecuting
        $triggered = false;
        Project::saving(function (Project $p) use (&$triggered): void {
            if ($p->name === 'Project Simulated Fail') {
                $triggered = true;
                throw ValidationException::withMessages([
                    'name' => 'Nama sudah digunakan',
                ]);
            }
        });

        try {
            $service->createProject($maintainer, [
                'name' => 'Project Simulated Fail',
                'description' => 'Simulasi gagal',
            ]);
            $this->fail('Expected ValidationException from saving event');
        } catch (ValidationException $e) {
            $this->assertTrue($e->validator->errors()->has('name'));
        }

        $this->assertTrue($triggered, 'Event saving harus terpanggil');
        $this->assertSame($initialTxLevel, DB::transactionLevel());
        $this->assertSame($initialProjectsCount, Project::count());
        $this->assertDatabaseMissing('projects', ['name' => 'Project Simulated Fail']);
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
