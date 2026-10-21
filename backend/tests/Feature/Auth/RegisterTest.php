<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_page_can_be_accessed(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertSee('Daftar Akun');
    }

    public function test_user_can_register_successfully(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test Reporter',
            'email' => 'reporter@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertRedirect(route('register'));
        $response->assertSessionHas('success');

        $user = User::where(
            'email',
            'reporter@example.com'
        )->firstOrFail();

        $this->assertSame('REPORTER', $user->role);

        $this->assertTrue(
            Hash::check('Password123!', $user->password)
        );
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'reporter@example.com',
        ]);

        $response = $this->post('/register', [
            'name' => 'Another Reporter',
            'email' => 'reporter@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertSessionHasErrors(['email']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_password_confirmation_must_match(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test Reporter',
            'email' => 'reporter@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Different123!',
        ]);

        $response->assertSessionHasErrors(['password']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_must_have_at_least_eight_characters(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test Reporter',
            'email' => 'reporter@example.com',
            'password' => '1234567',
            'password_confirmation' => '1234567',
        ]);

        $response->assertSessionHasErrors(['password']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_user_cannot_register_as_maintainer(): void
    {
        $this->post('/register', [
            'name' => 'Test Reporter',
            'email' => 'reporter@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'MAINTAINER',
        ]);

        $user = User::where(
            'email',
            'reporter@example.com'
        )->firstOrFail();

        $this->assertSame('REPORTER', $user->role);
    }

    public function test_required_fields_are_validated(): void
    {
        $response = $this->post('/register', []);

        $response->assertSessionHasErrors([
            'name',
            'email',
            'password',
        ]);

        $this->assertDatabaseCount('users', 0);
    }
}
