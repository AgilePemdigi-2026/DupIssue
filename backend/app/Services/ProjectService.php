<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    /**
     * Membuat project baru (REQ-03.1, REQ-03.2, REQ-03.3).
     * - Hanya Maintainer yang diizinkan membuat project.
     * - owner_id ditetapkan dari auth user ($creator), BUKAN dari form input.
     * - Validasi nama bersifat case-insensitive dan UNIQUE global.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function createProject(User $creator, array $data): Project
    {
        // 1. Verifikasi peran Maintainer
        if ($creator->role !== 'MAINTAINER') {
            throw new AuthorizationException('Akses tidak tersedia');
        }

        // 2. Validasi input nama dan deskripsi
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.required' => 'Nama wajib 3-100 karakter',
            'name.min' => 'Nama wajib 3-100 karakter',
            'name.max' => 'Nama wajib 3-100 karakter',
            'description.max' => 'Deskripsi maksimal 1000 karakter',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Validasi keunikan nama project secara case-insensitive (panduan final)
        $cleanName = trim($data['name']);
        $existingProject = Project::whereRaw('LOWER(name) = ?', [strtolower($cleanName)])->exists();
        if ($existingProject) {
            throw ValidationException::withMessages([
                'name' => 'Nama sudah digunakan',
            ]);
        }

        // 3. Simpan project dengan owner_id dari server dan waktu dateTime()
        $project = new Project([
            'name' => $cleanName,
            'description' => $data['description'] ?? null,
        ]);
        $project->owner_id = $creator->id;
        $project->created_at = now();
        $project->updated_at = now();
        $project->save();

        // 4. Titik pemanggilan log aktivitas (REQ-03.12)
        $this->logActivity('CREATE_PROJECT', $project, $creator, [
            'name' => $project->name,
        ]);

        return $project;
    }

    /**
     * Mengubah informasi project (REQ-03.4).
     * - Hanya Maintainer pemilik project yang diizinkan mengedit.
     * - Dilakukan validasi ulang pada nama dan deskripsi.
     * - Pengecekan nama duplikat bersifat case-insensitive dan mengabaikan project sendiri.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function updateProject(User $user, Project $project, array $data): Project
    {
        // 1. Verifikasi pemilik project
        if ($user->id !== $project->owner_id || $user->role !== 'MAINTAINER') {
            throw new AuthorizationException('Akses tidak tersedia');
        }

        // 2. Validasi ulang input
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.required' => 'Nama wajib 3-100 karakter',
            'name.min' => 'Nama wajib 3-100 karakter',
            'name.max' => 'Nama wajib 3-100 karakter',
            'description.max' => 'Deskripsi maksimal 1000 karakter',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $cleanName = trim($data['name']);

        // Pengecekan nama duplikat case-insensitive (mengabaikan project sendiri)
        $existingProject = Project::whereRaw('LOWER(name) = ?', [strtolower($cleanName)])
            ->where('id', '!=', $project->id)
            ->exists();
        if ($existingProject) {
            throw ValidationException::withMessages([
                'name' => 'Nama sudah digunakan',
            ]);
        }

        $oldValues = [
            'name' => $project->name,
            'description' => $project->description,
        ];

        // 3. Update informasi dengan waktu dateTime()
        $project->name = $cleanName;
        $project->description = $data['description'] ?? null;
        $project->updated_at = now();
        $project->save();

        // 4. Titik pemanggilan log aktivitas (REQ-03.12)
        $this->logActivity('UPDATE_PROJECT', $project, $user, [
            'old' => $oldValues,
            'new' => [
                'name' => $project->name,
                'description' => $project->description,
            ],
        ]);

        return $project;
    }

    /**
     * Mengundang Reporter bergabung ke project (REQ-03.5, REQ-03.6, REQ-03.9).
     * - Menggunakan DB::transaction dan lockForUpdate untuk mencegah race condition.
     * - Format email valid, akun terdaftar dan ber-role REPORTER.
     * - Menolak jika sudah menjadi anggota aktif atau sudah punya undangan PENDING.
     * - Status undangan awal PENDING (belum memberi akses).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function inviteReporter(User $inviter, Project $project, string $email): Invitation
    {
        return DB::transaction(function () use ($inviter, $project, $email) {
            // 1. Verifikasi pemilik project
            if ($inviter->id !== $project->owner_id || $inviter->role !== 'MAINTAINER') {
                throw new AuthorizationException('Akses tidak tersedia');
            }

            // 2. Validasi format email
            $validator = Validator::make(['email' => $email], [
                'email' => ['required', 'email'],
            ], [
                'email.required' => 'Email wajib diisi',
                'email.email' => 'Format email tidak valid',
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            // 3. Cari akun tujuan dan periksa peran REPORTER
            $reporter = User::where('email', $email)->first();
            if (!$reporter || $reporter->role !== 'REPORTER') {
                throw ValidationException::withMessages([
                    'email' => 'Akun bukan Reporter',
                ]);
            }

            // 4. Periksa apakah sudah ada keanggotaan aktif (dengan lockForUpdate)
            $isActiveMember = Membership::where('project_id', $project->id)
                ->where('user_id', $reporter->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->exists();

            // 5. Periksa apakah sudah memiliki undangan PENDING pada project yang sama (dengan lockForUpdate)
            $hasPendingInvitation = Invitation::where('project_id', $project->id)
                ->where('reporter_id', $reporter->id)
                ->where('status', 'PENDING')
                ->lockForUpdate()
                ->exists();

            if ($isActiveMember || $hasPendingInvitation) {
                throw ValidationException::withMessages([
                    'email' => 'Sudah menjadi anggota atau memiliki undangan Pending',
                ]);
            }

            // 6. Buat record undangan baru dengan status PENDING dan waktu dateTime()
            $invitation = Invitation::create([
                'project_id' => $project->id,
                'invited_by' => $inviter->id,
                'reporter_id' => $reporter->id,
                'status' => 'PENDING',
                'created_at' => now(),
            ]);

            // 7. Titik pemanggilan log aktivitas (REQ-03.12)
            $this->logActivity('INVITE_REPORTER', $project, $inviter, [
                'reporter_id' => $reporter->id,
                'email' => $email,
                'invitation_id' => $invitation->id,
            ]);

            return $invitation;
        });
    }

    /**
     * Membatalkan undangan PENDING (REQ-03.7).
     * - Menggunakan DB::transaction dan lockForUpdate.
     * - Status berubah menjadi CANCELED dan mengisi responded_at.
     *
     * @throws AuthorizationException
     */
    public function cancelInvitation(User $user, Invitation $invitation): Invitation
    {
        return DB::transaction(function () use ($user, $invitation) {
            $invitation = Invitation::where('id', $invitation->id)->lockForUpdate()->firstOrFail();
            $project = $invitation->project;

            if ($user->id !== $project->owner_id || $user->role !== 'MAINTAINER') {
                throw new AuthorizationException('Akses tidak tersedia');
            }

            if ($invitation->status !== 'PENDING') {
                throw new \DomainException('Hanya undangan berstatus PENDING yang dapat dibatalkan');
            }

            $invitation->update([
                'status' => 'CANCELED',
                'responded_at' => now(),
            ]);

            // Titik pemanggilan log aktivitas (REQ-03.12)
            $this->logActivity('CANCEL_INVITATION', $project, $user, [
                'invitation_id' => $invitation->id,
                'reporter_id' => $invitation->reporter_id,
            ]);

            return $invitation;
        });
    }

    /**
     * Menghapus anggota aktif dari project (REQ-03.8, REQ-NF-10).
     * - Menggunakan DB::transaction dan lockForUpdate.
     * - Menonaktifkan keanggotaan (is_active = 0, removed_at = now()).
     * - Data user, riwayat issue, dan vote anggota TIDAK dihapus.
     *
     * @throws AuthorizationException
     */
    public function removeMember(User $user, Project $project, int $userId): Membership
    {
        return DB::transaction(function () use ($user, $project, $userId) {
            if ($user->id !== $project->owner_id || $user->role !== 'MAINTAINER') {
                throw new AuthorizationException('Akses tidak tersedia');
            }

            $membership = Membership::where('project_id', $project->id)
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$membership) {
                throw new \DomainException('Anggota tidak ditemukan atau sudah tidak aktif');
            }

            $membership->update([
                'is_active' => false,
                'removed_at' => now(),
            ]);

            // Titik pemanggilan log aktivitas (REQ-03.12)
            $this->logActivity('REMOVE_MEMBER', $project, $user, [
                'user_id' => $userId,
                'membership_id' => $membership->id,
            ]);

            return $membership;
        });
    }

    /**
     * Reporter menanggapi undangan: menerima atau menolak (REQ-03.10).
     * - Menggunakan DB::transaction dan lockForUpdate untuk mencegah race condition.
     * - Diterima: status ACCEPTED, responded_at diisi. MENGAKTIFKAN baris membership yang sudah ada
     *   (is_active=1, removed_at=null, joined_at=now()) atau membuat baris baru jika belum pernah ada.
     * - Ditolak: status REJECTED, responded_at diisi, tidak ada membership aktif.
     *
     * @throws AuthorizationException
     */
    public function respondInvitation(User $reporter, Invitation $invitation, string $action): Invitation
    {
        return DB::transaction(function () use ($reporter, $invitation, $action) {
            $invitation = Invitation::where('id', $invitation->id)->lockForUpdate()->firstOrFail();

            // 1. Verifikasi penerima undangan
            if ($reporter->id !== $invitation->reporter_id) {
                throw new AuthorizationException('Akses tidak tersedia');
            }

            // 2. Verifikasi status undangan masih PENDING
            if ($invitation->status !== 'PENDING') {
                throw new \DomainException('Undangan sudah pernah ditanggapi atau dibatalkan');
            }

            if (!in_array($action, ['accept', 'reject'], true)) {
                throw new \InvalidArgumentException('Aksi tidak valid (hanya accept atau reject)');
            }

            $project = $invitation->project;

            if ($action === 'accept') {
                $invitation->update([
                    'status' => 'ACCEPTED',
                    'responded_at' => now(),
                ]);

                // Kunci dan cek baris membership yang sudah ada (unique: project_id, user_id)
                $membership = Membership::where('project_id', $invitation->project_id)
                    ->where('user_id', $reporter->id)
                    ->lockForUpdate()
                    ->first();

                if ($membership) {
                    // Reaktivasi baris yang sudah ada
                    $membership->update([
                        'is_active' => true,
                        'joined_at' => now(),
                        'removed_at' => null,
                    ]);
                } else {
                    // Buat baris baru hanya jika belum pernah ada
                    Membership::create([
                        'project_id' => $invitation->project_id,
                        'user_id' => $reporter->id,
                        'is_active' => true,
                        'joined_at' => now(),
                        'removed_at' => null,
                    ]);
                }

                $this->logActivity('ACCEPT_INVITATION', $project, $reporter, [
                    'invitation_id' => $invitation->id,
                ]);
            } else {
                $invitation->update([
                    'status' => 'REJECTED',
                    'responded_at' => now(),
                ]);

                $this->logActivity('REJECT_INVITATION', $project, $reporter, [
                    'invitation_id' => $invitation->id,
                ]);
            }

            return $invitation;
        });
    }

    /**
     * Memeriksa apakah pengguna memiliki hak akses ke suatu project (REQ-03 / REQ-07.3).
     * Kontrak bersama untuk dipanggil oleh fitur Submit Issue (Elsa), Status Issue, dll.
     * - True jika pengguna adalah pemilik (owner_id).
     * - True jika pengguna adalah anggota dengan membership aktif (is_active = 1).
     * - False untuk kondisi lainnya (termasuk undangan yang masih PENDING).
     */
    public function canAccessProject(User $user, Project $project): bool
    {
        if ($user->id === $project->owner_id) {
            return true;
        }

        return Membership::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Mendapatkan daftar undangan masuk yang berstatus PENDING untuk seorang Reporter (REQ-03.10 / REQ-07.4).
     */
    public function getIncomingInvitations(User $reporter)
    {
        return Invitation::with(['project', 'inviter'])
            ->where('reporter_id', $reporter->id)
            ->where('status', 'PENDING')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Titik pemanggilan untuk pencatatan riwayat aktivitas project (REQ-03.12 / REQ-07.7).
     * Tidak memanggil query database tabel activity_logs secara langsung sebelum
     * kepemilikan dan skema tabel tersebut difinalisasi bersama Joshua.
     */
    protected function logActivity(string $action, Project $project, ?User $actor, array $details = []): void
    {
        Log::info("DupIssue ActivityLog: [{$action}] on Project #{$project->id} by User #{$actor?->id}", [
            'project_id' => $project->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'details' => $details,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
