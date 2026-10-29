<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProjectMemberController extends Controller
{
    protected ProjectService $projectService;

    public function __construct(ProjectService $projectService)
    {
        $this->projectService = $projectService;
    }

    /**
     * Mendapatkan user yang sedang login atau abort 403 (REQ-07.3).
     */
    protected function getAuthUser(): User
    {
        $user = auth()->user();
        if (!$user) {
            abort(403, 'Akses tidak tersedia');
        }
        return $user;
    }

    /**
     * Menampilkan daftar project milik Maintainer atau project yang diikuti Reporter (REQ-07.4).
     * Route: GET /projects
     */
    public function index()
    {
        $user = $this->getAuthUser();

        if ($user->role === 'MAINTAINER') {
            $projects = Project::where('owner_id', $user->id)->latest()->get();
        } else {
            // Reporter hanya melihat project dengan keanggotaan aktif (is_active = 1)
            $projects = Project::whereHas('memberships', function ($q) use ($user) {
                $q->where('user_id', $user->id)->where('is_active', true);
            })->latest()->get();
        }

        $incomingInvitationsCount = ($user->role === 'REPORTER')
            ? Invitation::where('reporter_id', $user->id)->where('status', 'PENDING')->count()
            : 0;

        return view('projects.index', compact('projects', 'user', 'incomingInvitationsCount'));
    }

    /**
     * Menampilkan formulir pembuatan project baru (REQ-03.1).
     * Route: GET /projects/new
     */
    public function create()
    {
        $user = $this->getAuthUser();

        if ($user->role !== 'MAINTAINER') {
            abort(403, 'Akses tidak tersedia');
        }

        return view('projects.create');
    }

    /**
     * Menyimpan project baru ke database (REQ-03.1 s.d. 03.3).
     * Route: POST /projects
     */
    public function store(Request $request)
    {
        $user = $this->getAuthUser();

        try {
            $project = $this->projectService->createProject($user, $request->all());

            return redirect('/projects/' . $project->id)
                ->with('success', 'Project berhasil dibuat');
        } catch (AuthorizationException $e) {
            abort(403, 'Akses tidak tersedia');
        } catch (ValidationException $e) {
            return redirect('/projects/new')
                ->withErrors($e->validator)
                ->withInput();
        }
    }

    /**
     * Menampilkan dashboard / detail project (REQ-07.3, REQ-03).
     * Route: GET /projects/{id}
     */
    public function show($id)
    {
        $user = $this->getAuthUser();
        $project = Project::with(['owner', 'memberships.user'])->findOrFail($id);

        // Hanya owner atau Reporter dengan keanggotaan aktif yang diizinkan (REQ-07.3)
        if (!$this->projectService->canAccessProject($user, $project)) {
            abort(403, 'Akses tidak tersedia');
        }

        return view('projects.show', compact('project', 'user'));
    }

    /**
     * Menampilkan halaman pengaturan & edit project (REQ-03.4).
     * Route: GET /projects/{id}/settings
     */
    public function settings($id)
    {
        $user = $this->getAuthUser();
        $project = Project::findOrFail($id);

        if ($user->id !== $project->owner_id || $user->role !== 'MAINTAINER') {
            abort(403, 'Akses tidak tersedia');
        }

        return view('projects.settings', compact('project'));
    }

    /**
     * Memperbarui data project (REQ-03.4).
     * Route: POST/PUT /projects/{id}/settings
     */
    public function update(Request $request, $id)
    {
        $user = $this->getAuthUser();
        $project = Project::findOrFail($id);

        if ($user->id !== $project->owner_id || $user->role !== 'MAINTAINER') {
            abort(403, 'Akses tidak tersedia');
        }

        try {
            $this->projectService->updateProject($user, $project, $request->all());

            return redirect('/projects/' . $project->id . '/settings')
                ->with('success', 'Perubahan berhasil disimpan');
        } catch (AuthorizationException $e) {
            abort(403, 'Akses tidak tersedia');
        } catch (ValidationException $e) {
            return redirect('/projects/' . $project->id . '/settings')
                ->withErrors($e->validator)
                ->withInput();
        }
    }

    /**
     * Menampilkan daftar anggota dan undangan project (REQ-03.5 s.d. 03.8).
     * Route: GET /projects/{id}/members
     */
    public function members($id)
    {
        $user = $this->getAuthUser();
        $project = Project::with(['memberships.user', 'invitations.reporter'])->findOrFail($id);

        if ($user->id !== $project->owner_id || $user->role !== 'MAINTAINER') {
            abort(403, 'Akses tidak tersedia');
        }

        $activeMembers = $project->memberships()->where('is_active', true)->with('user')->get();
        $invitations = $project->invitations()->with('reporter')->latest()->get();

        return view('projects.members', compact('project', 'activeMembers', 'invitations'));
    }

    /**
     * Mengundang Reporter bergabung ke project (REQ-03.5).
     * Route: POST /projects/{id}/members/invite
     */
    public function inviteMember(Request $request, $id)
    {
        $user = $this->getAuthUser();
        $project = Project::findOrFail($id);

        if ($user->id !== $project->owner_id || $user->role !== 'MAINTAINER') {
            abort(403, 'Akses tidak tersedia');
        }

        try {
            // Tolak array/object sebelum parameter string service (bukan HTTP 500).
            $data = $request->validate(['email' => ['required', 'string', 'email']], [
                'email.required' => 'Email wajib diisi',
                'email.string' => 'Format email tidak valid',
                'email.email' => 'Format email tidak valid',
            ]);
            $this->projectService->inviteReporter($user, $project, $data['email']);

            return redirect('/projects/' . $project->id . '/members')
                ->with('success', 'Undangan berhasil dikirim');
        } catch (AuthorizationException $e) {
            abort(403, 'Akses tidak tersedia');
        } catch (ValidationException $e) {
            return redirect('/projects/' . $project->id . '/members')
                ->withErrors($e->validator)
                ->withInput();
        }
    }

    /**
     * Membatalkan undangan PENDING (REQ-03.7).
     * Menolak jika ID undangan bukan milik project yang bersangkutan (manipulasi URL lintas project).
     * Route: POST /projects/{id}/invitations/{invitationId}/cancel
     */
    public function cancelInvitation($id, $invitationId)
    {
        $user = $this->getAuthUser();
        $project = Project::findOrFail($id);

        if ($user->id !== $project->owner_id || $user->role !== 'MAINTAINER') {
            abort(403, 'Akses tidak tersedia');
        }

        $invitation = Invitation::findOrFail($invitationId);

        // Validasi kepemilikan relasi: undangan HARUS milik project ini
        if ((int) $invitation->project_id !== (int) $project->id) {
            abort(403, 'Akses tidak tersedia');
        }

        // Undangan yang statusnya sudah bukan PENDING
        if ($invitation->status !== 'PENDING') {
            return redirect('/projects/' . $id . '/members')
                ->with('error', 'Hanya undangan berstatus PENDING yang dapat dibatalkan');
        }

        try {
            $this->projectService->cancelInvitation($user, $invitation);

            return redirect('/projects/' . $id . '/members')
                ->with('success', 'Undangan berhasil dibatalkan');
        } catch (AuthorizationException $e) {
            abort(403, 'Akses tidak tersedia');
        } catch (\DomainException $e) {
            return redirect('/projects/' . $id . '/members')
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Menghapus (menonaktifkan) anggota aktif dari project (REQ-03.8).
     * Menolak jika target user bukan anggota dari project yang bersangkutan.
     * Route: POST /projects/{id}/members/{userId}/remove
     */
    public function removeMember($id, $userId)
    {
        $user = $this->getAuthUser();
        $project = Project::findOrFail($id);

        if ($user->id !== $project->owner_id || $user->role !== 'MAINTAINER') {
            abort(403, 'Akses tidak tersedia');
        }

        // Validasi relasi: target user harus memiliki keanggotaan pada project ini
        $membership = Membership::where('project_id', $project->id)
            ->where('user_id', $userId)
            ->first();

        if (!$membership) {
            abort(403, 'Akses tidak tersedia');
        }

        // Jika anggota sudah tidak aktif
        if (!$membership->is_active) {
            return redirect('/projects/' . $project->id . '/members')
                ->with('error', 'Anggota tidak ditemukan atau sudah tidak aktif');
        }

        try {
            $this->projectService->removeMember($user, $project, (int) $userId);

            return redirect('/projects/' . $project->id . '/members')
                ->with('success', 'Keanggotaan dinonaktifkan. Seluruh riwayat tetap tersimpan.');
        } catch (AuthorizationException $e) {
            abort(403, 'Akses tidak tersedia');
        } catch (\DomainException $e) {
            return redirect('/projects/' . $project->id . '/members')
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Halaman daftar undangan masuk untuk Reporter (REQ-03.10 / REQ-07.4).
     * Route: GET /invitations
     */
    public function reporterInvitations()
    {
        $user = $this->getAuthUser();

        if ($user->role !== 'REPORTER') {
            abort(403, 'Akses tidak tersedia');
        }

        $invitations = $this->projectService->getIncomingInvitations($user);

        return view('invitations.index', compact('invitations', 'user'));
    }

    /**
     * Reporter merespons undangan (Terima / Tolak) (REQ-03.10).
     * Menolak jika user login bukan reporter tujuan undangan.
     * Route: POST /invitations/{invitationId}/respond
     */
    public function respondInvitation(Request $request, $invitationId)
    {
        $user = $this->getAuthUser();
        $invitation = Invitation::findOrFail($invitationId);

        if ($user->role !== 'REPORTER' || (int) $user->id !== (int) $invitation->reporter_id) {
            abort(403, 'Akses tidak tersedia');
        }

        $action = $request->input('action');

        // Validasi input action: hanya boleh 'accept' atau 'reject'
        if (!in_array($action, ['accept', 'reject'], true)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Aksi tidak valid (hanya accept atau reject)',
                    'errors' => ['action' => ['Aksi tidak valid (hanya accept atau reject)']],
                ], 422);
            }

            return redirect('/invitations')
                ->withErrors(['action' => 'Aksi tidak valid (hanya accept atau reject)'])
                ->with('error', 'Aksi tidak valid (hanya accept atau reject)');
        }

        // Undangan yang statusnya sudah bukan PENDING (ACCEPTED, REJECTED, CANCELED)
        if ($invitation->status !== 'PENDING') {
            return redirect('/invitations')
                ->with('error', 'Undangan sudah pernah ditanggapi atau dibatalkan');
        }

        try {
            $this->projectService->respondInvitation($user, $invitation, (string) $action);

            $msg = ($action === 'accept')
                ? 'Undangan berhasil diterima. Anda sekarang menjadi anggota aktif.'
                : 'Undangan berhasil ditolak.';

            return redirect('/invitations')->with('success', $msg);
        } catch (AuthorizationException $e) {
            abort(403, 'Akses tidak tersedia');
        } catch (\DomainException $e) {
            return redirect('/invitations')->with('error', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return redirect('/invitations')->with('error', $e->getMessage());
        }
    }
}
