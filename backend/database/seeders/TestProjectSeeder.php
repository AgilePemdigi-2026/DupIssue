<?php

namespace Database\Seeders;

use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TestProjectSeeder extends Seeder
{
    /**
     * Seeder kecil khusus testing untuk Project + Membership.
     * Catatan: Password akun Maintainer dan Reporter menggunakan hash acak,
     * tidak menyertakan plaintext password ter-commit.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('TestProjectSeeder hanya untuk local/testing.');
        }

        DB::transaction(function () {
            // Periksa seluruh identitas sebelum menulis; jangan mengambil alih data existing.
            $maintainer = $this->findExpectedUser('maintainer.test@dupissue.local', 'Maintainer Seeder Test', 'MAINTAINER');
            $reporter = $this->findExpectedUser('reporter.test@dupissue.local', 'Reporter Seeder Test', 'REPORTER');
            $project = Project::where('name', 'Project Seeder Demo')->lockForUpdate()->first();

            if ($project && (! $maintainer || $project->owner_id !== $maintainer->id)) {
                throw new \LogicException('TestProjectSeeder: nama project sudah digunakan oleh pemilik lain.');
            }

            // 1. Akun Maintainer pengujian
            if (! $maintainer) {
                $maintainer = new User([
                    'name' => 'Maintainer Seeder Test',
                    'email' => 'maintainer.test@dupissue.local',
                    'password' => Hash::make(Str::random(32)),
                ]);
                $maintainer->role = 'MAINTAINER';
                $maintainer->save();
            }

            // 2. Akun Reporter pengujian
            if (! $reporter) {
                $reporter = new User([
                    'name' => 'Reporter Seeder Test',
                    'email' => 'reporter.test@dupissue.local',
                    'password' => Hash::make(Str::random(32)),
                ]);
                $reporter->role = 'REPORTER';
                $reporter->save();
            }

            // 3. Project pengujian
            if (! $project) {
                $project = new Project([
                    'name' => 'Project Seeder Demo',
                    'description' => 'Project contoh untuk keperluan pengujian dan verifikasi lokal.',
                ]);
                $project->owner_id = $maintainer->id;
                $project->created_at = now();
                $project->updated_at = now();
                $project->save();
            }

            // 4. Membership existing, termasuk yang nonaktif, tetap dipertahankan.
            $membership = Membership::where('project_id', $project->id)
                ->where('user_id', $reporter->id)
                ->lockForUpdate()
                ->first();

            if (! $membership) {
                Membership::create([
                    'project_id' => $project->id,
                    'user_id' => $reporter->id,
                    'is_active' => true,
                    'joined_at' => now(),
                    'removed_at' => null,
                ]);
            }
        });
    }

    private function findExpectedUser(string $email, string $name, string $role): ?User
    {
        $user = User::where('email', $email)->lockForUpdate()->first();

        if ($user && ($user->email !== $email || $user->name !== $name || $user->role !== $role)) {
            throw new \LogicException("TestProjectSeeder: akun {$email} tidak sesuai identitas atau peran pengujian.");
        }

        return $user;
    }
}
