<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\TestProjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TestProjectSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function snapshot(): array
    {
        return array_map(
            fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            ['users', 'projects', 'memberships', 'invitations'],
        );
    }

    public function test_first_run_and_repeated_run_preserve_the_same_records(): void
    {
        $this->seed(TestProjectSeeder::class);

        $maintainer = User::where('email', 'maintainer.test@dupissue.local')->firstOrFail();
        $reporter = User::where('email', 'reporter.test@dupissue.local')->firstOrFail();
        $project = Project::firstOrFail();
        $membership = Membership::firstOrFail();
        $this->assertSame('MAINTAINER', $maintainer->role);
        $this->assertSame('REPORTER', $reporter->role);
        $this->assertSame($maintainer->id, $project->owner_id);
        $this->assertSame($project->id, $membership->project_id);
        $this->assertSame($reporter->id, $membership->user_id);
        $this->assertTrue($membership->is_active);
        $this->assertNotSame($maintainer->password, $reporter->password);
        foreach ([$maintainer, $reporter] as $user) {
            $this->assertFalse(Hash::check('Password123!', $user->password));
        }

        $before = $this->snapshot();
        $this->seed(TestProjectSeeder::class);
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('memberships', 1);
    }

    public static function projectCollisions(): array
    {
        return ['no demo accounts yet' => [false], 'existing demo accounts' => [true]];
    }

    #[DataProvider('projectCollisions')]
    public function test_foreign_owner_collision_leaves_all_existing_data_unchanged(bool $existingAccounts): void
    {
        if ($existingAccounts) {
            User::factory()->create([
                'email' => 'maintainer.test@dupissue.local', 'name' => 'Maintainer Seeder Test', 'role' => 'MAINTAINER',
            ]);
            User::factory()->create([
                'email' => 'reporter.test@dupissue.local', 'name' => 'Reporter Seeder Test', 'role' => 'REPORTER',
            ]);
        }
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id, 'name' => 'Project Seeder Demo']);
        Membership::factory()->inactive()->create(['project_id' => $project->id]);
        $before = $this->snapshot();

        try {
            (new TestProjectSeeder)->run();
            $this->fail('Seeder must reject a project owned by another user.');
        } catch (\LogicException $e) {
            $this->assertSame('TestProjectSeeder: nama project sudah digunakan oleh pemilik lain.', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame($owner->id, $project->fresh()->owner_id);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertFalse(Membership::firstOrFail()->is_active);
    }

    public static function accountCollisions(): array
    {
        return [
            'maintainer wrong role' => ['maintainer.test@dupissue.local', 'Maintainer Seeder Test', 'REPORTER'],
            'reporter wrong role' => ['reporter.test@dupissue.local', 'Reporter Seeder Test', 'MAINTAINER'],
            'maintainer wrong identity' => ['maintainer.test@dupissue.local', 'Existing User', 'MAINTAINER'],
            'reporter wrong identity' => ['reporter.test@dupissue.local', 'Existing User', 'REPORTER'],
        ];
    }

    #[DataProvider('accountCollisions')]
    public function test_existing_account_collision_is_rejected_without_modifying_data(string $email, string $name, string $role): void
    {
        User::factory()->create(compact('email', 'name', 'role'));
        $before = $this->snapshot();

        try {
            (new TestProjectSeeder)->run();
            $this->fail('Seeder must reject an account with a different identity or role.');
        } catch (\LogicException $e) {
            $this->assertSame("TestProjectSeeder: akun {$email} tidak sesuai identitas atau peran pengujian.", $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_membership_write_failure_rolls_back_new_accounts_and_project(): void
    {
        $unrelated = Project::factory()->create();
        Membership::factory()->inactive()->create(['project_id' => $unrelated->id]);
        $before = $this->snapshot();
        $attempted = false;
        $writesBeforeFailure = [];
        DB::connection()->beforeExecuting(function ($sql) use (&$attempted, &$writesBeforeFailure) {
            if (str_starts_with($sql, 'insert into "memberships"')) {
                $attempted = true;
                $writesBeforeFailure = [
                    User::whereIn('email', ['maintainer.test@dupissue.local', 'reporter.test@dupissue.local'])->count(),
                    Project::where('name', 'Project Seeder Demo')->count(),
                ];
                throw new \RuntimeException('Injected membership write failure');
            }
        });

        try {
            (new TestProjectSeeder)->run();
            $this->fail('The injected database write failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected membership write failure', $e->getMessage());
        }

        $this->assertTrue($attempted);
        $this->assertSame([2, 1], $writesBeforeFailure);
        $this->assertSame($before, $this->snapshot());
    }
}
