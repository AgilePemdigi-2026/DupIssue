<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_documentation_root_and_all_topics_are_public_and_rendered(): void
    {
        $topics = [
            'introduction',
            'getting-started',
            'account-and-user-roles',
            'project-and-member-management',
            'submit-an-issue',
            'track-issue-status',
            'review-and-validate-duplicates',
            'import-issues',
            'dashboard-and-activity-logs',
            'frequently-asked-questions',
            'troubleshooting',
        ];

        $this->get('/documentation')->assertOk();

        foreach ($topics as $topic) {
            $response = $this->get('/documentation/'.$topic);
            $response->assertOk();
            $response->assertSeeText('On This Page');
            $response->assertSee(asset('css/dupissue-documentation.css'), false);
            $response->assertSee(asset('js/dupissue-documentation.js'), false);
        }
    }

    public function test_unknown_documentation_topic_returns_not_found(): void
    {
        $this->get('/documentation/tidak-ada')->assertNotFound();
    }

    public function test_removed_qa_and_gherkin_topics_are_not_available(): void
    {
        foreach ([
            'generate-scenario',
            'review-scenario',
            'evaluation-for-qa',
            'gherkin-basics',
            'validation-and-gui-coverage',
        ] as $topic) {
            $this->get('/documentation/'.$topic)->assertNotFound();
        }
    }

    public function test_landing_links_to_documentation_portal(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('/documentation/introduction');
    }

    public function test_about_page_is_separate_and_linked_from_navigation(): void
    {
        $this->get('/tentang')
            ->assertOk()
            ->assertSeeText('Apa itu DupIssue')
            ->assertSeeText('Tentang');

        $this->get('/')
            ->assertOk()
            ->assertSee('/tentang');

        $this->get('/documentation/introduction')
            ->assertOk()
            ->assertSee('/tentang');
    }

    public function test_landing_stops_at_home_actions_without_embedded_about_or_documentation(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeText('Daftar')
            ->assertSeeText('Masuk')
            ->assertDontSee('id="tentang"', false)
            ->assertDontSee('id="dokumentasi"', false)
            ->assertDontSeeText('Laporan yang jelas. Kolaborasi yang terarah.');
    }
}
