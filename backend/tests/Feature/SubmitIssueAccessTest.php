<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

// Authentication/role gates need neither Project records nor a replacement service.
class SubmitIssueAccessTest extends TestCase
{
    public function test_guest_browser_is_redirected_to_login(): void
    {
        $this->get('/projects/1/submit')->assertRedirect(route('login'));
        $this->post('/projects/1/submit', [])->assertRedirect(route('login'));
        $this->get('/projects/1/issues/1')->assertRedirect(route('login'));
    }

    public function test_guest_json_is_unauthorized(): void
    {
        $this->getJson('/projects/1/submit')->assertUnauthorized();
        $this->postJson('/projects/1/submit', [])->assertUnauthorized();
        $this->getJson('/projects/1/issues/1')->assertUnauthorized();
    }

    public function test_maintainer_is_rejected_before_resolving_submit_dependencies(): void
    {
        $this->actingAs(User::factory()->make(['id' => 1, 'role' => 'MAINTAINER']));
        $this->get('/projects/1/submit')->assertForbidden();
        $this->post('/projects/1/submit', [])->assertForbidden();
    }
}
