<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Allow this test to run without a development application key.
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
    }

    protected function beforeRefreshingDatabase(): void
    {
        // This hook runs before RefreshDatabase can execute migrate:fresh.
        // Replace the entire connection so DB_URL/read/write settings cannot leak in.
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');

        $connection = DB::connection();
        $this->assertSame('sqlite', $connection->getConfig('driver'));
        $this->assertSame(':memory:', $connection->getDatabaseName());
        $this->assertSame('sqlite', $connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->assertSame('', $connection->selectOne('PRAGMA database_list')->file);
    }

    public function test_authenticated_user_can_logout_and_is_redirected_to_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->get(route('login'))->assertOk()->assertViewIs('auth.login');
    }

    public function test_logout_disallows_get(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/logout')->assertStatus(405);
    }

    public function test_guest_cannot_submit_logout(): void
    {
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_protected_page_is_inaccessible_after_logout(): void
    {
        $user = User::factory()->create();
        $user->role = 'REPORTER';
        $user->save();

        $this->actingAs($user)->get(route('reporter.projects.index'))->assertOk();
        $this->post('/logout')->assertRedirect(route('login'));
        $this->get(route('reporter.projects.index'))->assertRedirect(route('login'));
    }
}
