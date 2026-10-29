<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_home_shows_dupissue_branding_and_auth_navigation(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('More clarity.')
            ->assertSee('Less duplication.')
            ->assertSee('WORKFLOW PREVIEW')
            ->assertSee('images/dupissue-logo.svg')
            ->assertSee('css/dupissue-landing.css')
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('href="'.route('register').'"', false);
    }
}
