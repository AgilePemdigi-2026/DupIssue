<?php

namespace Database\Seeders;

use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
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
        // 1. Akun Maintainer pengujian
        $maintainer = User::where('email', 'maintainer.test@dupissue.local')->first();
        if (!$maintainer) {
            $maintainer = new User([
                'name' => 'Maintainer Seeder Test',
                'email' => 'maintainer.test@dupissue.local',
                'password' => Hash::make(Str::random(32)),
            ]);
            $maintainer->role = 'MAINTAINER';
            $maintainer->save();
        }

        // 2. Akun Reporter pengujian
        $reporter = User::where('email', 'reporter.test@dupissue.local')->first();
        if (!$reporter) {
            $reporter = new User([
                'name' => 'Reporter Seeder Test',
                'email' => 'reporter.test@dupissue.local',
                'password' => Hash::make('Password123!'),
            ]);
            $reporter->role = 'REPORTER';
            $reporter->save();
        }

        // 3. Project pengujian
        $project = Project::where('name', 'Project Seeder Demo')->first();
        if (!$project) {
            $project = new Project([
                'name' => 'Project Seeder Demo',
                'description' => 'Project contoh untuk keperluan pengujian dan verifikasi lokal.',
            ]);
            $project->owner_id = $maintainer->id;
            $project->created_at = now();
            $project->updated_at = now();
            $project->save();
        }

        // 4. Membership aktif pengujian
        $membership = Membership::where('project_id', $project->id)
            ->where('user_id', $reporter->id)
            ->first();

        if (!$membership) {
            Membership::create([
                'project_id' => $project->id,
                'user_id' => $reporter->id,
                'is_active' => true,
                'joined_at' => now(),
                'removed_at' => null,
            ]);
        }
    }
}
