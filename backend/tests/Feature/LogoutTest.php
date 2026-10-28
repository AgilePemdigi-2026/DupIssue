<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_logout_and_redirect_to_login(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_protected_routes_deny_access_after_user_logs_out(): void
    {
        $user = User::factory()->create();

        // Login kemudian Logout
        $this->actingAs($user)->post('/logout');
        $this->assertGuest();

        // Akses GET form Submit Issue harus ditolak & diarahkan ke /login
        $this->get('/projects/1/submit')->assertRedirect('/login');

        // Akses POST penyimpanan Issue harus ditolak & diarahkan ke /login
        $this->post('/projects/1/issues', [
            'title' => 'Judul issue valid',
            'description' => 'Deskripsi issue valid lebih dari sepuluh karakter.',
        ])->assertRedirect('/login');
    }
}