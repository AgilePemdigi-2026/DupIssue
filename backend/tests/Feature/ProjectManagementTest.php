<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\TestProjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper untuk membuat user dengan peran MAINTAINER.
     */
    protected function createMaintainer(array $attributes = []): User
    {
        $user = new User(array_merge([
            'name' => 'Maintainer User',
            'email' => 'maintainer_' . uniqid() . '@dupissue.test',
            'password' => Hash::make('Password123!'),
        ], $attributes));
        $user->role = 'MAINTAINER';
        $user->save();

        return $user;
    }

    /**
     * Helper untuk membuat user dengan peran REPORTER.
     */
    protected function createReporter(array $attributes = []): User
    {
        $user = new User(array_merge([
            'name' => 'Reporter User',
            'email' => 'reporter_' . uniqid() . '@dupissue.test',
            'password' => Hash::make('Password123!'),
        ], $attributes));
        $user->role = 'REPORTER';
        $user->save();

        return $user;
    }

    /**
     * Helper untuk membuat project dengan owner_id yang valid.
     */
    protected function createProject(User $owner, array $attributes = []): Project
    {
        $project = new Project(array_merge([
            'name' => 'Project ' . uniqid(),
            'description' => 'Deskripsi Project Test',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
        $project->owner_id = $owner->id;
        $project->save();

        return $project;
    }

    /**
     * Kasus 1: Maintainer buat project berhasil (REQ-03.1 s.d. 03.3).
     */
    public function test_maintainer_can_create_project_successfully(): void
    {
        $maintainer = $this->createMaintainer();

        $response = $this->actingAs($maintainer)->post('/projects', [
            'name' => 'Toko Online Baju',
            'description' => 'Aplikasi toko baju online untuk uji regresi',
        ]);

        $project = Project::where('name', 'Toko Online Baju')->first();

        $this->assertNotNull($project, 'Project harus tersimpan di database.');
        $this->assertSame($maintainer->id, $project->owner_id, 'owner_id harus berasal dari Maintainer yang login.');
        $response->assertRedirect('/projects/' . $project->id);
        $response->assertSessionHas('success', 'Project berhasil dibuat');
    }

    /**
     * Kasus 2: Reporter ditolak buat project (REQ-03.1 / REQ-07.3).
     */
    public function test_reporter_is_forbidden_from_creating_project(): void
    {
        $reporter = $this->createReporter();

        // 1. Ditolak saat membuka formulir /projects/new
        $formResponse = $this->actingAs($reporter)->get('/projects/new');
        $formResponse->assertStatus(403);
        $formResponse->assertSee('Akses tidak tersedia');

        // 2. Ditolak saat mencoba mengirim POST /projects langsung
        $postResponse = $this->actingAs($reporter)->post('/projects', [
            'name' => 'Project Ilegal Reporter',
            'description' => 'Mencoba membuat project tanpa hak Maintainer',
        ]);

        $postResponse->assertStatus(403);
        $postResponse->assertSee('Akses tidak tersedia');
        $this->assertDatabaseMissing('projects', [
            'name' => 'Project Ilegal Reporter',
        ]);
    }

    /**
     * Kasus 3: Undangan diterima membuat membership aktif (REQ-03.6, REQ-03.10).
     */
    public function test_accepted_invitation_creates_active_membership(): void
    {
        $maintainer = $this->createMaintainer();
        $reporter = $this->createReporter(['email' => 'valentino@dupissue.test']);

        $project = $this->createProject($maintainer, ['name' => 'Project Alpha']);

        // Maintainer mengundang Reporter
        $invitation = Invitation::create([
            'project_id' => $project->id,
            'invited_by' => $maintainer->id,
            'reporter_id' => $reporter->id,
            'status' => 'PENDING',
            'created_at' => now(),
        ]);

        // Reporter menerima undangan
        $response = $this->actingAs($reporter)->post("/invitations/{$invitation->id}/respond", [
            'action' => 'accept',
        ]);

        $response->assertRedirect('/invitations');
        $response->assertSessionHas('success');

        // Status undangan berubah menjadi ACCEPTED
        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => 'ACCEPTED',
        ]);

        // Membership aktif terbentuk
        $this->assertDatabaseHas('memberships', [
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => 1,
        ]);

        // Reporter sekarang dapat mengakses halaman project
        $accessResponse = $this->actingAs($reporter)->get("/projects/{$project->id}");
        $accessResponse->assertStatus(200);
        $accessResponse->assertSee($project->name);
    }

    /**
     * Kasus 4: Undangan ditolak tidak membuat akses (REQ-03.10 / REQ-07.3).
     */
    public function test_rejected_invitation_does_not_grant_project_access(): void
    {
        $maintainer = $this->createMaintainer();
        $reporter = $this->createReporter(['email' => 'reporter_tolak@dupissue.test']);

        $project = $this->createProject($maintainer, ['name' => 'Project Beta']);

        $invitation = Invitation::create([
            'project_id' => $project->id,
            'invited_by' => $maintainer->id,
            'reporter_id' => $reporter->id,
            'status' => 'PENDING',
            'created_at' => now(),
        ]);

        // Reporter menolak undangan
        $response = $this->actingAs($reporter)->post("/invitations/{$invitation->id}/respond", [
            'action' => 'reject',
        ]);

        $response->assertRedirect('/invitations');

        // Status undangan berubah menjadi REJECTED
        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => 'REJECTED',
        ]);

        // Tidak ada membership aktif
        $this->assertDatabaseMissing('memberships', [
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => 1,
        ]);

        // Reporter ditolak mengakses project
        $accessResponse = $this->actingAs($reporter)->get("/projects/{$project->id}");
        $accessResponse->assertStatus(403);
        $accessResponse->assertSee('Akses tidak tersedia');
    }

    /**
     * Kasus 5: Reporter non-member ditolak masuk project (REQ-07.3).
     */
    public function test_non_member_reporter_is_forbidden_from_accessing_project(): void
    {
        $maintainer = $this->createMaintainer();
        $strangerReporter = $this->createReporter(['email' => 'stranger@dupissue.test']);

        $project = $this->createProject($maintainer, ['name' => 'Project Rahasia']);

        // Akses langsung ke detail project
        $response = $this->actingAs($strangerReporter)->get("/projects/{$project->id}");
        $response->assertStatus(403);
        $response->assertSee('Akses tidak tersedia');
    }

    /**
     * Kasus 6: Akses project milik orang lain ditolak (REQ-03.4 / REQ-07.3).
     */
    public function test_access_to_another_users_project_settings_and_members_is_forbidden(): void
    {
        $ownerMaintainer = $this->createMaintainer(['email' => 'owner@dupissue.test']);
        $otherMaintainer = $this->createMaintainer(['email' => 'other_maintainer@dupissue.test']);

        $project = $this->createProject($ownerMaintainer, ['name' => 'Project Milik Owner']);

        // 1. Maintainer lain mencoba buka settings
        $settingsResponse = $this->actingAs($otherMaintainer)->get("/projects/{$project->id}/settings");
        $settingsResponse->assertStatus(403);
        $settingsResponse->assertSee('Akses tidak tersedia');

        // 2. Maintainer lain mencoba buka daftar anggota
        $membersResponse = $this->actingAs($otherMaintainer)->get("/projects/{$project->id}/members");
        $membersResponse->assertStatus(403);
        $membersResponse->assertSee('Akses tidak tersedia');

        // 3. Maintainer lain mencoba mengubah info project via POST
        $updateResponse = $this->actingAs($otherMaintainer)->post("/projects/{$project->id}/settings", [
            'name' => 'Nama Bajakan',
            'description' => 'Deskripsi bajakan',
        ]);
        $updateResponse->assertStatus(403);
        $updateResponse->assertSee('Akses tidak tersedia');
    }

    /**
     * Kasus 7: Undang user ganda ditolak saat masih PENDING (REQ-03.5 / REQ-03.11 / BR-04).
     */
    public function test_inviting_duplicate_user_or_already_member_is_rejected(): void
    {
        $maintainer = $this->createMaintainer();
        $reporter = $this->createReporter(['email' => 'target@dupissue.test']);

        $project = $this->createProject($maintainer, ['name' => 'Project Tes Undangan Ganda']);

        // Undangan pertama: sukses
        $firstInvite = $this->actingAs($maintainer)->post("/projects/{$project->id}/members/invite", [
            'email' => 'target@dupissue.test',
        ]);
        $firstInvite->assertRedirect("/projects/{$project->id}/members");
        $firstInvite->assertSessionHas('success', 'Undangan berhasil dikirim');

        $this->assertDatabaseCount('invitations', 1);

        // Undangan kedua dengan email yang sama saat masih PENDING: harus ditolak
        $duplicateInvite = $this->actingAs($maintainer)->post("/projects/{$project->id}/members/invite", [
            'email' => 'target@dupissue.test',
        ]);

        $duplicateInvite->assertSessionHasErrors(['email']);
        $this->assertSame(
            'Sudah menjadi anggota atau memiliki undangan Pending',
            session('errors')->first('email')
        );

        // Pastikan jumlah record undangan tetap 1 (tidak bertambah)
        $this->assertDatabaseCount('invitations', 1);
    }

    /**
     * Kasus 8: Batalkan undangan PENDING menjadi CANCELED (REQ-03.7).
     */
    public function test_maintainer_can_cancel_pending_invitation(): void
    {
        $maintainer = $this->createMaintainer();
        $reporter = $this->createReporter(['email' => 'batal_undangan@dupissue.test']);
        $project = $this->createProject($maintainer, ['name' => 'Project Batal Undangan']);

        $invitation = Invitation::create([
            'project_id' => $project->id,
            'invited_by' => $maintainer->id,
            'reporter_id' => $reporter->id,
            'status' => 'PENDING',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($maintainer)->post("/projects/{$project->id}/invitations/{$invitation->id}/cancel");

        $response->assertRedirect("/projects/{$project->id}/members");
        $response->assertSessionHas('success', 'Undangan berhasil dibatalkan');

        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => 'CANCELED',
        ]);

        $this->assertNotNull($invitation->fresh()->responded_at);
    }

    /**
     * Kasus 9: Undang ulang anggota setelah undangan dibatalkan atau keanggotaan dihapus (REQ-03.9).
     */
    public function test_maintainer_can_reinvite_user_after_cancellation_or_removal(): void
    {
        $maintainer = $this->createMaintainer();
        $reporter = $this->createReporter(['email' => 'fakhri@dupissue.test']);
        $project = $this->createProject($maintainer, ['name' => 'Project Reinvite']);

        // 1. Kondisi A: Undangan sebelumnya berstatus CANCELED
        Invitation::create([
            'project_id' => $project->id,
            'invited_by' => $maintainer->id,
            'reporter_id' => $reporter->id,
            'status' => 'CANCELED',
            'created_at' => now(),
            'responded_at' => now(),
        ]);

        // Undang kembali
        $reinviteResponse = $this->actingAs($maintainer)->post("/projects/{$project->id}/members/invite", [
            'email' => 'fakhri@dupissue.test',
        ]);

        $reinviteResponse->assertRedirect("/projects/{$project->id}/members");
        $reinviteResponse->assertSessionHas('success', 'Undangan berhasil dikirim');

        // Harus ada 2 record undangan: 1 CANCELED lama dan 1 PENDING baru
        $this->assertDatabaseCount('invitations', 2);
        $latestInvitation = Invitation::where('project_id', $project->id)
            ->where('reporter_id', $reporter->id)
            ->where('status', 'PENDING')
            ->first();
        $this->assertNotNull($latestInvitation);

        // 2. Kondisi B: Reporter pernah dikeluarkan (is_active = 0)
        $latestInvitation->update(['status' => 'ACCEPTED']);
        Membership::create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => false,
            'joined_at' => now()->subDays(5),
            'removed_at' => now(),
        ]);

        // Undang ulang setelah keanggotaan non-aktif
        $reinviteAfterRemoval = $this->actingAs($maintainer)->post("/projects/{$project->id}/members/invite", [
            'email' => 'fakhri@dupissue.test',
        ]);

        $reinviteAfterRemoval->assertRedirect("/projects/{$project->id}/members");
        $reinviteAfterRemoval->assertSessionHas('success', 'Undangan berhasil dikirim');

        $this->assertDatabaseCount('invitations', 3);
    }

    /**
     * Kasus 10: Validasi data project sesuai tabel contoh SRS 4.4.6.
     */
    public function test_project_validation_errors_match_srs_table(): void
    {
        $maintainer = $this->createMaintainer();
        $this->createProject($maintainer, ['name' => 'Toko Online Baju']);

        // Baris 1: Nama kosong
        $emptyResponse = $this->actingAs($maintainer)->post('/projects', [
            'name' => '',
            'description' => 'Deskripsi valid lebih dari 10 karakter',
        ]);
        $emptyResponse->assertSessionHasErrors(['name']);
        $this->assertSame('Nama wajib 3-100 karakter', session('errors')->first('name'));

        // Baris 2: Nama terlalu pendek ('ab')
        $shortResponse = $this->actingAs($maintainer)->post('/projects', [
            'name' => 'ab',
            'description' => 'Deskripsi valid lebih dari 10 karakter',
        ]);
        $shortResponse->assertSessionHasErrors(['name']);
        $this->assertSame('Nama wajib 3-100 karakter', session('errors')->first('name'));

        // Baris 3: Nama sudah digunakan
        $dupResponse = $this->actingAs($maintainer)->post('/projects', [
            'name' => 'Toko Online Baju',
            'description' => 'Deskripsi valid lebih dari 10 karakter',
        ]);
        $dupResponse->assertSessionHasErrors(['name']);
        $this->assertSame('Nama sudah digunakan', session('errors')->first('name'));
    }

    /**
     * Kasus 11: Validasi error undangan sesuai tabel contoh SRS 4.4.6.
     */
    public function test_invitation_validation_errors_match_srs_table(): void
    {
        $maintainer = $this->createMaintainer();
        $project = $this->createProject($maintainer, ['name' => 'Project Validasi Undangan']);

        // User Maintainer (bukan Reporter)
        $this->createMaintainer(['email' => 'joshua@dupissue.test']);

        // User Reporter yang sudah menjadi anggota aktif
        $activeMember = $this->createReporter(['email' => 'valentino@dupissue.test']);
        Membership::create([
            'project_id' => $project->id,
            'user_id' => $activeMember->id,
            'is_active' => true,
            'joined_at' => now(),
        ]);

        // Baris 1: Format email tidak valid
        $invalidEmailResponse = $this->actingAs($maintainer)->post("/projects/{$project->id}/members/invite", [
            'email' => 'bukan-email',
        ]);
        $invalidEmailResponse->assertSessionHasErrors(['email']);
        $this->assertSame('Format email tidak valid', session('errors')->first('email'));

        // Baris 2: Akun bukan Reporter
        $notReporterResponse = $this->actingAs($maintainer)->post("/projects/{$project->id}/members/invite", [
            'email' => 'joshua@dupissue.test',
        ]);
        $notReporterResponse->assertSessionHasErrors(['email']);
        $this->assertSame('Akun bukan Reporter', session('errors')->first('email'));

        // Baris 3: Sudah menjadi anggota atau memiliki undangan Pending
        $alreadyMemberResponse = $this->actingAs($maintainer)->post("/projects/{$project->id}/members/invite", [
            'email' => 'valentino@dupissue.test',
        ]);
        $alreadyMemberResponse->assertSessionHasErrors(['email']);
        $this->assertSame('Sudah menjadi anggota atau memiliki undangan Pending', session('errors')->first('email'));
    }

    /**
     * Kasus 12: Nama duplikat beda huruf besar/kecil ditolak (Case-insensitive check).
     */
    public function test_duplicate_project_name_with_different_case_is_rejected(): void
    {
        $maintainer = $this->createMaintainer();
        $this->createProject($maintainer, ['name' => 'Toko Online Baju']);

        // 1. Mencoba membuat nama project dengan variasi huruf kecil
        $lowerCaseResponse = $this->actingAs($maintainer)->post('/projects', [
            'name' => 'toko online baju',
            'description' => 'Deskripsi valid',
        ]);
        $lowerCaseResponse->assertSessionHasErrors(['name']);
        $this->assertSame('Nama sudah digunakan', session('errors')->first('name'));

        // 2. Mencoba membuat nama project dengan variasi huruf besar
        $upperCaseResponse = $this->actingAs($maintainer)->post('/projects', [
            'name' => 'TOKO ONLINE BAJU',
            'description' => 'Deskripsi valid',
        ]);
        $upperCaseResponse->assertSessionHasErrors(['name']);
        $this->assertSame('Nama sudah digunakan', session('errors')->first('name'));

        // 3. Edit project lain ke nama duplikat beda huruf besar/kecil juga ditolak
        $projectB = $this->createProject($maintainer, ['name' => 'Project Lain']);
        $updateResponse = $this->actingAs($maintainer)->post("/projects/{$projectB->id}/settings", [
            'name' => 'Toko ONLINE Baju',
            'description' => 'Mencoba meniru nama project A',
        ]);
        $updateResponse->assertSessionHasErrors(['name']);
        $this->assertSame('Nama sudah digunakan', session('errors')->first('name'));
    }

    /**
     * Kasus 13: Manipulasi ID lintas project ditolak dengan HTTP 403 (Akses tidak tersedia).
     */
    public function test_cross_project_id_manipulation_is_forbidden(): void
    {
        $maintainerA = $this->createMaintainer(['email' => 'maintainer_a@dupissue.test']);
        $maintainerB = $this->createMaintainer(['email' => 'maintainer_b@dupissue.test']);
        $reporterB = $this->createReporter(['email' => 'reporter_b@dupissue.test']);
        $strangerReporter = $this->createReporter(['email' => 'stranger_reporter@dupissue.test']);

        $projectA = $this->createProject($maintainerA, ['name' => 'Project Milik A']);
        $projectB = $this->createProject($maintainerB, ['name' => 'Project Milik B']);

        // Undangan milik Project B
        $invitationB = Invitation::create([
            'project_id' => $projectB->id,
            'invited_by' => $maintainerB->id,
            'reporter_id' => $reporterB->id,
            'status' => 'PENDING',
            'created_at' => now(),
        ]);

        // Membership di Project B
        $membershipB = Membership::create([
            'project_id' => $projectB->id,
            'user_id' => $reporterB->id,
            'is_active' => true,
            'joined_at' => now(),
        ]);

        // A. Maintainer A mencoba membatalkan undangan milik Project B lewat URL Project A
        $cancelCrossResponse = $this->actingAs($maintainerA)->post("/projects/{$projectA->id}/invitations/{$invitationB->id}/cancel");
        $cancelCrossResponse->assertStatus(403);
        $cancelCrossResponse->assertSee('Akses tidak tersedia');

        // B. Maintainer A mencoba menghapus user Reporter B (anggota Project B) lewat URL Project A
        $removeCrossResponse = $this->actingAs($maintainerA)->post("/projects/{$projectA->id}/members/{$reporterB->id}/remove");
        $removeCrossResponse->assertStatus(403);
        $removeCrossResponse->assertSee('Akses tidak tersedia');

        // C. Reporter lain mencoba merespons undangan yang bukan miliknya
        $respondCrossResponse = $this->actingAs($strangerReporter)->post("/invitations/{$invitationB->id}/respond", [
            'action' => 'accept',
        ]);
        $respondCrossResponse->assertStatus(403);
        $respondCrossResponse->assertSee('Akses tidak tersedia');
    }

    /**
     * Kasus 14: Reaktivasi membership yang sudah ada tanpa membuat baris baru.
     */
    public function test_reaccepting_invitation_reactivates_existing_membership_row(): void
    {
        $maintainer = $this->createMaintainer();
        $reporter = $this->createReporter(['email' => 'reaktivasi@dupissue.test']);
        $project = $this->createProject($maintainer, ['name' => 'Project Reaktivasi']);

        // 1. Reporter bergabung pertama kali
        $firstInvite = Invitation::create([
            'project_id' => $project->id,
            'invited_by' => $maintainer->id,
            'reporter_id' => $reporter->id,
            'status' => 'PENDING',
            'created_at' => now(),
        ]);
        $this->actingAs($reporter)->post("/invitations/{$firstInvite->id}/respond", ['action' => 'accept']);

        $this->assertDatabaseCount('memberships', 1);
        $this->assertDatabaseHas('memberships', [
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => 1,
        ]);

        // 2. Maintainer menghapus anggota tersebut (is_active = 0)
        $this->actingAs($maintainer)->post("/projects/{$project->id}/members/{$reporter->id}/remove");

        $this->assertDatabaseHas('memberships', [
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => 0,
        ]);
        $this->assertNotNull(Membership::first()->removed_at);

        // 3. Maintainer mengundang ulang
        $this->actingAs($maintainer)->post("/projects/{$project->id}/members/invite", [
            'email' => 'reaktivasi@dupissue.test',
        ]);
        $secondInvite = Invitation::where('project_id', $project->id)
            ->where('reporter_id', $reporter->id)
            ->where('status', 'PENDING')
            ->firstOrFail();

        // 4. Reporter menerima undangan ulang
        $this->actingAs($reporter)->post("/invitations/{$secondInvite->id}/respond", ['action' => 'accept']);

        // 5. Verifikasi: JUMLAH RECORD TETAP 1 (karena constraint unik dan reaktivasi row yang sama)
        $this->assertDatabaseCount('memberships', 1);
        $membership = Membership::where('project_id', $project->id)
            ->where('user_id', $reporter->id)
            ->first();

        $this->assertTrue((bool) $membership->is_active);
        $this->assertNull($membership->removed_at);
        $this->assertNotNull($membership->joined_at);
    }

    /**
     * Kasus 15: Verifikasi ProjectFactory, MembershipFactory, InvitationFactory, dan Seeder.
     */
    public function test_factories_and_seeder_work_properly(): void
    {
        // Seeder berjalan tanpa error
        $this->seed(TestProjectSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'maintainer.test@dupissue.local', 'role' => 'MAINTAINER']);
        $this->assertDatabaseHas('users', ['email' => 'reporter.test@dupissue.local', 'role' => 'REPORTER']);
        $this->assertDatabaseHas('projects', ['name' => 'Project Seeder Demo']);
        $this->assertDatabaseHas('memberships', ['is_active' => 1]);

        // ProjectFactory
        $factoryProject = Project::factory()->create();
        $this->assertNotNull($factoryProject->id);

        // MembershipFactory states
        $activeMembership = Membership::factory()->active()->create();
        $this->assertTrue((bool) $activeMembership->is_active);

        $inactiveMembership = Membership::factory()->inactive()->create();
        $this->assertFalse((bool) $inactiveMembership->is_active);
        $this->assertNotNull($inactiveMembership->removed_at);

        // InvitationFactory states
        $pendingInv = Invitation::factory()->pending()->create();
        $this->assertSame('PENDING', $pendingInv->status);

        $acceptedInv = Invitation::factory()->accepted()->create();
        $this->assertSame('ACCEPTED', $acceptedInv->status);

        $rejectedInv = Invitation::factory()->rejected()->create();
        $this->assertSame('REJECTED', $rejectedInv->status);

        $canceledInv = Invitation::factory()->canceled()->create();
        $this->assertSame('CANCELED', $canceledInv->status);
    }
}
