<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    /**
     * Tentukan apakah pengguna dapat melihat/mengakses project (REQ-07.3).
     * Hanya pemilik (owner) atau anggota aktif (is_active = true) yang diizinkan.
     * Undangan PENDING belum memberikan hak akses.
     */
    public function view(User $user, Project $project): Response
    {
        return $this->canAccessProject($user, $project)
            ? Response::allow()
            : Response::deny('Akses tidak tersedia');
    }

    /**
     * Tentukan apakah pengguna dapat membuka formulir dan membuat project (REQ-03.1).
     * Hanya pengguna dengan peran MAINTAINER yang diizinkan.
     */
    public function create(User $user): Response
    {
        return ($user->role === 'MAINTAINER')
            ? Response::allow()
            : Response::deny('Akses tidak tersedia');
    }

    /**
     * Tentukan apakah pengguna dapat mengubah informasi project (REQ-03.4).
     * Hanya Maintainer pemilik project yang diizinkan.
     */
    public function update(User $user, Project $project): Response
    {
        return ($user->id === $project->owner_id && $user->role === 'MAINTAINER')
            ? Response::allow()
            : Response::deny('Akses tidak tersedia');
    }

    /**
     * Tentukan apakah pengguna dapat mengelola anggota / mengirim undangan (REQ-03.5 s.d. 03.9).
     * Hanya Maintainer pemilik project yang diizinkan.
     */
    public function manageMembers(User $user, Project $project): Response
    {
        return ($user->id === $project->owner_id && $user->role === 'MAINTAINER')
            ? Response::allow()
            : Response::deny('Akses tidak tersedia');
    }

    /**
     * Method reusable untuk verifikasi akses project (REQ-03 / REQ-07.3).
     * True jika user adalah owner ATAU user memiliki keanggotaan aktif.
     */
    public function canAccessProject(User $user, Project $project): bool
    {
        // 1. Pemilik project harus tetap memiliki peran Maintainer.
        if ($user->role === 'MAINTAINER' && $user->id === $project->owner_id) {
            return true;
        }

        if ($user->role !== 'REPORTER') {
            return false;
        }

        // 2. Anggota harus memiliki catatan keanggotaan aktif (is_active = 1)
        return Membership::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }
}
