<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    // TEST 1: Halaman Login dapat diakses.
    public function test_login_page_is_accessible(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Masuk ke akun DupIssue Anda');
    }

    // TEST 2: Reporter berhasil Login.
    public function test_reporter_can_login(): void
    {
        $user = User::factory()->create([
            'role' => 'REPORTER',
            'password' => 'Password123!',
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);

        $response->assertRedirect(
            route('reporter.projects.index')
        );

        $this->assertAuthenticatedAs($user);
    }

    // TEST 3: Maintainer berhasil Login.
    public function test_maintainer_can_login(): void
    {
        $user = User::factory()->create([
            'role' => 'MAINTAINER',
            'password' => 'Password123!',
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);

        $response->assertRedirect(
            route('maintainer.dashboard')
        );

        $this->assertAuthenticatedAs($user);
    }

    // TEST 4: Password yang salah harus ditolak.
    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create([
            'password' => 'Password123!',
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'WrongPassword!',
        ]);

        $response->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // TEST 5: Pengunjung yang belum Login tidak
    // boleh mengakses halaman yang dilindungi.
    public function test_guest_cannot_access_protected_pages(): void
    {
        $this->get('/reporter/projects')
            ->assertRedirect(route('login'));

        $this->get('/maintainer/dashboard')
            ->assertRedirect(route('login'));
    }

    // TEST 6: Reporter tidak boleh mengakses
    // Dashboard Maintainer.
    public function test_reporter_cannot_access_maintainer_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => 'REPORTER',
        ]);

        $this->actingAs($user)
            ->get('/maintainer/dashboard')
            ->assertForbidden();
    }

    // TEST 7: Maintainer tidak boleh mengakses
    // halaman khusus Reporter.
    public function test_maintainer_cannot_access_reporter_page(): void
    {
        $user = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $this->actingAs($user)
            ->get('/reporter/projects')
            ->assertForbidden();
    }
}
