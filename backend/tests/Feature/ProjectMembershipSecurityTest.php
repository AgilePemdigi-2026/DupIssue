<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\ProjectService;
use Database\Seeders\TestProjectSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectMembershipSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $reporter = User::factory()->create(['role' => 'REPORTER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $reporter, $project];
    }

    public function test_pending_and_removed_members_have_no_access(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $service = app(ProjectService::class);
        $invitation = $service->inviteReporter($owner, $project, $reporter->email);
        $this->actingAs($reporter)->get("/projects/{$project->id}")->assertForbidden();
        $this->get('/projects')->assertDontSee($project->name);
        $this->get('/invitations')->assertOk()->assertSee($project->name);
        $service->respondInvitation($reporter, $invitation, 'accept');
        $membership = Membership::firstOrFail();
        $this->get("/projects/{$project->id}")->assertOk();
        $service->removeMember($owner, $project, $reporter->id);
        $this->get("/projects/{$project->id}")->assertForbidden();
        $this->get('/projects')->assertDontSee($project->name);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertFalse($membership->fresh()->is_active);
        $this->assertNotNull($membership->fresh()->removed_at);
        $this->assertDatabaseHas('users', ['id' => $reporter->id]);
        $this->assertSame('ACCEPTED', $invitation->fresh()->status);
    }

    public function test_active_reporter_cannot_mutate_project_or_members(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        Membership::factory()->create(['project_id' => $project->id, 'user_id' => $reporter->id]);
        $this->actingAs($reporter)->get("/projects/{$project->id}/settings")->assertForbidden();
        $this->get("/projects/{$project->id}/members")->assertForbidden();
        foreach (['post', 'put'] as $method) {
            $this->$method("/projects/{$project->id}/settings", ['name' => 'Hijacked'])->assertForbidden();
        }
        $this->post("/projects/{$project->id}/members/invite", ['email' => $reporter->email])->assertForbidden();
        $this->post("/projects/{$project->id}/members/{$reporter->id}/remove")->assertForbidden();
        $this->assertSame($project->name, $project->fresh()->name);
    }

    public function test_cross_project_mutations_preserve_original_records(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $other = Project::factory()->create();
        $invitation = Invitation::factory()->create([
            'project_id' => $other->id, 'invited_by' => $other->owner_id, 'reporter_id' => $reporter->id,
        ]);
        $membership = Membership::factory()->create(['project_id' => $other->id, 'user_id' => $reporter->id]);
        $this->actingAs($owner)->post("/projects/{$project->id}/invitations/{$invitation->id}/cancel")->assertForbidden();
        $this->post("/projects/{$project->id}/members/{$reporter->id}/remove")->assertForbidden();
        $this->post("/projects/{$other->id}/members/invite", ['email' => $reporter->email])->assertForbidden();
        $this->get("/projects/{$other->id}")->assertForbidden();
        $this->assertSame('PENDING', $invitation->fresh()->status);
        $this->assertTrue($membership->fresh()->is_active);
        $this->assertDatabaseCount('invitations', 1);
    }

    public function test_owner_can_update_without_changing_owner_from_input(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $this->actingAs($owner)->put("/projects/{$project->id}/settings", [
            'name' => strtoupper($project->name), 'description' => str_repeat('a', 1000), 'owner_id' => $reporter->id,
        ])->assertSessionHasNoErrors()->assertRedirect("/projects/{$project->id}/settings");
        $this->assertSame($owner->id, $project->fresh()->owner_id);
        $this->assertSame(strtoupper($project->name), $project->fresh()->name);
        $this->post('/projects', ['name' => 'New owner test', 'owner_id' => $reporter->id])->assertSessionHasNoErrors();
        $this->assertSame($owner->id, Project::where('name', 'New owner test')->firstOrFail()->owner_id);
    }

    public static function invalidProjectData(): array
    {
        return [
            'short' => [['name' => 'ab'], 'name'],
            'long' => [['name' => str_repeat('a', 101)], 'name'],
            'description' => [['name' => 'Valid name', 'description' => str_repeat('a', 1001)], 'description'],
            'array name' => [['name' => ['bad']], 'name'],
        ];
    }

    #[DataProvider('invalidProjectData')]
    public function test_project_validation_applies_to_create_and_update(array $data, string $field): void
    {
        [$owner, , $project] = $this->fixture();
        $this->actingAs($owner)->post('/projects', $data)->assertSessionHasErrors($field);
        $this->post("/projects/{$project->id}/settings", $data)->assertSessionHasErrors($field);
        $this->assertSame($project->name, $project->fresh()->name);
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_name_boundaries_and_service_trimming(): void
    {
        [$owner] = $this->fixture();
        $service = app(ProjectService::class);
        $this->assertSame('abc', $service->createProject($owner, ['name' => '  abc  '])->name);
        $this->assertSame(100, strlen($service->createProject($owner, ['name' => str_repeat('b', 100)])->name));
        $this->expectException(ValidationException::class);
        $service->createProject($owner, ['name' => '  a  ']);
    }

    public static function invalidEmails(): array
    {
        return [[null], [['bad@example.test']], ['unknown@example.test'], ['invalid']];
    }

    #[DataProvider('invalidEmails')]
    public function test_invalid_invite_input_does_not_return_500(mixed $email): void
    {
        [$owner, , $project] = $this->fixture();
        $this->actingAs($owner)->post("/projects/{$project->id}/members/invite", ['email' => $email])
            ->assertRedirect("/projects/{$project->id}/members")->assertSessionHasErrors('email');
        $this->assertDatabaseCount('invitations', 0);
    }

    public static function closedStatuses(): array
    {
        return [['ACCEPTED'], ['REJECTED'], ['CANCELED']];
    }

    #[DataProvider('closedStatuses')]
    public function test_closed_invitations_cannot_be_replayed_or_canceled(string $status): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $invitation = Invitation::factory()->create([
            'project_id' => $project->id, 'invited_by' => $owner->id, 'reporter_id' => $reporter->id,
            'status' => $status, 'responded_at' => now()->subDay(),
        ]);
        $before = $invitation->fresh()->getRawOriginal();
        foreach (['accept', 'reject'] as $action) {
            $this->actingAs($reporter)->post("/invitations/{$invitation->id}/respond", ['action' => $action])
                ->assertRedirect('/invitations')->assertSessionHas('error');
        }
        $this->actingAs($owner)->post("/projects/{$project->id}/invitations/{$invitation->id}/cancel")
            ->assertRedirect("/projects/{$project->id}/members")->assertSessionHas('error');
        $this->assertSame($before, $invitation->fresh()->getRawOriginal());
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_duplicate_invitation_is_rejected_and_rejection_allows_reinvite(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $service = app(ProjectService::class);
        $first = $service->inviteReporter($owner, $project, $reporter->email);
        $this->actingAs($owner)->post("/projects/{$project->id}/members/invite", ['email' => $reporter->email])
            ->assertSessionHasErrors('email');
        $this->assertDatabaseCount('invitations', 1);
        $service->respondInvitation($reporter, $first, 'reject');
        $second = $service->inviteReporter($owner, $project, $reporter->email);
        $this->assertNotEquals($first->id, $second->id);
        $this->assertSame('REJECTED', $first->fresh()->status);
        $this->assertSame('PENDING', $second->status);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_database_rejects_duplicate_project_names_without_service_precheck(): void
    {
        [$owner, , $project] = $this->fixture();
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('projects')->insert(['owner_id' => $owner->id, 'name' => strtoupper($project->name)]);
    }

    public function test_database_rejects_duplicate_inactive_membership_rows(): void
    {
        [, $reporter, $project] = $this->fixture();
        Membership::factory()->inactive()->create(['project_id' => $project->id, 'user_id' => $reporter->id]);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('memberships')->insert(['project_id' => $project->id, 'user_id' => $reporter->id, 'is_active' => true]);
    }

    public static function writeOperations(): array
    {
        return [['insert'], ['update']];
    }

    #[DataProvider('writeOperations')]
    public function test_project_name_race_returns_validation_error(string $operation): void
    {
        [$owner, , $project] = $this->fixture();
        // Inject a competing write after validation but before the actual SQL write.
        // This exercises the real unique index, not simultaneous worker processes.
        $injected = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$injected, $operation, $owner) {
            $prefix = $operation === 'insert' ? 'insert into "projects"' : 'update "projects"';
            if (! $injected && str_starts_with($sql, $prefix)) {
                $injected = true;
                DB::table('projects')->insert(['owner_id' => $owner->id, 'name' => 'RACE NAME']);
            }
        });
        $url = $operation === 'insert' ? '/projects' : "/projects/{$project->id}/settings";
        $this->actingAs($owner)->post($url, ['name' => 'Race Name'])->assertSessionHasErrors('name');
        $this->assertTrue($injected);
        $this->assertSame(1, Project::where('name', 'Race Name')->count());
    }

    public function test_policy_and_service_require_active_reporter_membership(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $policy = new ProjectPolicy;
        $service = app(ProjectService::class);
        $membership = Membership::factory()->inactive()->create(['project_id' => $project->id, 'user_id' => $reporter->id]);
        foreach ([$policy, $service] as $access) {
            $this->assertTrue($access->canAccessProject($owner, $project));
            $this->assertFalse($access->canAccessProject($reporter, $project));
        }
        $membership->update(['is_active' => true]);
        foreach ([$policy, $service] as $access) {
            $this->assertTrue($access->canAccessProject($reporter, $project));
        }
        $owner->role = 'REPORTER';
        $reporter->role = 'MAINTAINER';
        foreach ([$policy, $service] as $access) {
            $this->assertFalse($access->canAccessProject($owner, $project));
            $this->assertFalse($access->canAccessProject($reporter, $project));
        }
    }

    public function test_seeder_uses_random_passwords_and_preserves_existing_records(): void
    {
        $this->seed(TestProjectSeeder::class);
        $users = User::orderBy('id')->get();
        foreach ($users as $user) {
            $this->assertFalse(Hash::check('Password123!', $user->password));
            $this->assertFalse(Hash::check('password', $user->password));
        }
        $membership = Membership::firstOrFail();
        $membership->update(['is_active' => false, 'removed_at' => now()]);
        $project = Project::firstOrFail();
        $project->update(['description' => 'Keep development edits']);
        $this->seed(TestProjectSeeder::class);
        $this->assertSame($users->pluck('password')->all(), User::orderBy('id')->pluck('password')->all());
        $this->assertSame('Keep development edits', $project->fresh()->description);
        $this->assertFalse($membership->fresh()->is_active);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('memberships', 1);
    }

    public function test_seeder_refuses_production_before_writing(): void
    {
        $this->app->instance('env', 'production');
        try {
            // Invoke the seeder guard itself, without Artisan's production prompt.
            (new TestProjectSeeder)->run();
            $this->fail('Seeder must refuse production.');
        } catch (\LogicException $e) {
            $this->assertSame('TestProjectSeeder hanya untuk local/testing.', $e->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_array_action_and_missing_invitation_are_handled_without_mutation(): void
    {
        [$owner, $reporter, $project] = $this->fixture();
        $invitation = app(ProjectService::class)->inviteReporter($owner, $project, $reporter->email);
        $this->actingAs($reporter)->postJson("/invitations/{$invitation->id}/respond", ['action' => ['accept']])
            ->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->actingAs($owner)->post("/projects/{$project->id}/invitations/999999/cancel")->assertNotFound();
        $this->assertSame('PENDING', $invitation->fresh()->status);
        $this->assertDatabaseCount('memberships', 0);
    }
}
