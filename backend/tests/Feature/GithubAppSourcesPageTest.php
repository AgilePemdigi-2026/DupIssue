<?php

namespace Tests\Feature;

use App\Models\GithubAppConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class GithubAppSourcesPageTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $this->project = Project::factory()->create([
            'owner_id' => $this->owner->id,
        ]);
    }

    private function pageUrl(): string
    {
        return route('sources.index', [
            'project' => $this->project->id,
        ]);
    }

    private function verifiedSession(): array
    {
        return [
            'project_id' => (int) $this->project->id,
            'actor_id' => (int) $this->owner->id,
            'installation_id' => 12345,
            'github_user_id' => 999,
            'repositories' => [
                [
                    'id' => 5678,
                    'full_name' => 'example/verified-repo',
                ],
            ],
            'expires_at' => now()
                ->addMinutes(10)
                ->timestamp,
        ];
    }

    public function test_verified_repository_is_displayed(): void
    {
        $response = $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_verified_repositories'
                    => $this->verifiedSession(),
            ])
            ->get($this->pageUrl());

        $response->assertOk();

        $response->assertViewHas(
            'githubAppVerifiedRepositories',
            [
                [
                    'id' => 5678,
                    'full_name' => 'example/verified-repo',
                ],
            ]
        );

        $response->assertSee(
            'example/verified-repo'
        );

        $response->assertSee(
            'name="repository_id"',
            false
        );

        $response->assertSee(
            'value="5678"',
            false
        );
    }

    public function test_multi_installation_only_displays_repositories_with_valid_installation_ids(): void
    {
        $session = $this->verifiedSession();
        $session['installation_id'] = null;
        $session['repositories'] = [
            [
                'id' => 1001,
                'full_name' => 'example/valid-first',
                'installation_id' => 111,
            ],
            [
                'id' => 1002,
                'full_name' => 'example/valid-second',
                'installation_id' => 444,
            ],
            [
                'id' => 1003,
                'full_name' => 'example/invalid-missing',
            ],
            [
                'id' => 1004,
                'full_name' => 'example/invalid-null',
                'installation_id' => null,
            ],
            [
                'id' => 1005,
                'full_name' => 'example/invalid-zero',
                'installation_id' => 0,
            ],
            [
                'id' => 1006,
                'full_name' => 'example/invalid-negative',
                'installation_id' => -111,
            ],
            [
                'id' => 1007,
                'full_name' => 'example/invalid-string',
                'installation_id' => '444',
            ],
        ];

        $response = $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_verified_repositories'
                    => $session,
            ])
            ->get($this->pageUrl());

        $response->assertOk();

        $response->assertViewHas(
            'githubAppVerifiedRepositories',
            [
                ['id' => 1001, 'full_name' => 'example/valid-first'],
                ['id' => 1002, 'full_name' => 'example/valid-second'],
            ]
        );

        $response->assertSee('example/valid-first');
        $response->assertSee('example/valid-second');

        foreach ([
            'example/invalid-missing',
            'example/invalid-null',
            'example/invalid-zero',
            'example/invalid-negative',
            'example/invalid-string',
        ] as $repository) {
            $response->assertDontSee($repository);
        }

        $this->assertDatabaseCount('github_app_connections', 0);
    }

    public function test_missing_or_invalid_session_installation_id_is_rejected(): void
    {
        $sessions = [];

        $missing = $this->verifiedSession();
        unset($missing['installation_id']);
        $sessions[] = $missing;

        foreach ([0, -12345, '12345'] as $invalidId) {
            $session = $this->verifiedSession();
            $session['installation_id'] = $invalidId;
            $sessions[] = $session;
        }

        foreach ($sessions as $session) {
            $response = $this->actingAs($this->owner)
                ->withSession([
                    'source_issue.github_app_verified_repositories'
                        => $session,
                ])
                ->get($this->pageUrl());

            $response->assertOk();
            $response->assertViewHas(
                'githubAppVerifiedRepositories',
                []
            );
            $response->assertDontSee('example/verified-repo');
        }

        $this->assertDatabaseCount('github_app_connections', 0);
    }

    public function test_multi_installation_rejects_expired_or_mismatched_session(): void
    {
        $base = $this->verifiedSession();
        $base['installation_id'] = null;
        $base['repositories'][0]['installation_id'] = 111;

        $expired = $base;
        $expired['expires_at'] = now()->subMinute()->timestamp;

        $wrongActor = $base;
        $wrongActor['actor_id'] = (int) $this->owner->id + 999;

        $wrongProject = $base;
        $wrongProject['project_id'] = (int) $this->project->id + 999;

        foreach ([$expired, $wrongActor, $wrongProject] as $session) {
            $response = $this->actingAs($this->owner)
                ->withSession([
                    'source_issue.github_app_verified_repositories'
                        => $session,
                ])
                ->get($this->pageUrl());

            $response->assertOk();
            $response->assertViewHas(
                'githubAppVerifiedRepositories',
                []
            );
            $response->assertDontSee('example/verified-repo');
        }

        $this->assertDatabaseCount('github_app_connections', 0);
    }

    public function test_expired_verification_is_hidden(): void
    {
        $session = $this->verifiedSession();
        $session['expires_at'] = now()
            ->subMinute()
            ->timestamp;

        $response = $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_verified_repositories'
                    => $session,
            ])
            ->get($this->pageUrl());

        $response->assertOk();
        $response->assertDontSee(
            'example/verified-repo'
        );
    }

    public function test_other_users_verification_is_hidden(): void
    {
        $session = $this->verifiedSession();
        $session['actor_id'] = (int) $this->owner->id + 999;

        $response = $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_verified_repositories'
                    => $session,
            ])
            ->get($this->pageUrl());

        $response->assertOk();
        $response->assertDontSee(
            'example/verified-repo'
        );
    }

    public function test_other_projects_verification_is_hidden(): void
    {
        $session = $this->verifiedSession();
        $session['project_id'] = (int) $this->project->id + 999;

        $response = $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_verified_repositories'
                    => $session,
            ])
            ->get($this->pageUrl());

        $response->assertOk();
        $response->assertDontSee(
            'example/verified-repo'
        );
    }

    public function test_saved_connection_shows_app_operations_without_manual_pat(): void
    {
        $connection = new GithubAppConnection();

        $connection->project_id = $this->project->id;
        $connection->installation_id = 12345;
        $connection->repository_id = 5678;
        $connection->repo_full_name = 'example/saved-repo';
        $connection->verified_github_user_id = 999;
        $connection->verified_at = now();
        $connection->save();

        $response = $this->actingAs($this->owner)
            ->get($this->pageUrl());

        $response->assertOk();

        $response->assertSee(
            'example/saved-repo'
        );

        $response->assertSee(
            'id="github-app-operations"',
            false
        );

        $response->assertSee(
            'id="github-app-preview-btn"',
            false
        );

        $response->assertSee(
            'id="github-app-import-btn"',
            false
        );

        $response->assertDontSee(
            'Koneksi Manual (PAT)'
        );

        $response->assertDontSee(
            'id="github-connection-form"',
            false
        );

        foreach ([
            'id="github-pat"',
            'id="github-repo"',
            'id="github-test-btn"',
            'id="github-save-btn"',
            'id="github-preview-section"',
            'id="github-import-btn"',
            'name="pat"',
            'js/github-connection.js',
            'js/github-preview.js',
            'id="github-legacy-migration-notice"',
        ] as $marker) {
            $response->assertDontSee($marker, false);
        }

        $response->assertSee(
            'js/github-app.js'
        );
    }

    /**
     * Periksa kelas aktif dan atribut aksesibilitas
     * pada tombol tab serta panel kontennya.
     */
    private function assertActiveSourceTab(
        \Illuminate\Testing\TestResponse $response,
        string $expectedActive
    ): void {
        $html = $response->getContent();

        foreach (['csv', 'github'] as $name) {
            $shouldBeActive = $name === $expectedActive;

            foreach ([
                ['button', $name . '-tab'],
                ['div', $name . '-panel'],
            ] as [$tag, $id]) {
                $pattern = '~<' . $tag
                    . '\b(?=[^>]*\bid="'
                    . preg_quote($id, '~')
                    . '")[^>]*>~s';

                $this->assertSame(
                    1,
                    preg_match($pattern, $html, $matches),
                    "Elemen {$id} tidak ditemukan."
                );

                $this->assertSame(
                    1,
                    preg_match(
                        '~\bclass="([^"]*)"~',
                        $matches[0],
                        $classMatch
                    )
                );

                $classes = preg_split(
                    '/\s+/',
                    trim($classMatch[1])
                );

                $this->assertSame(
                    $shouldBeActive,
                    in_array('active', $classes, true),
                    "Status active {$id} tidak sesuai."
                );

                if ($tag === 'button') {
                    $this->assertSame(
                        1,
                        preg_match(
                            '~\baria-selected="([^"]*)"~',
                            $matches[0],
                            $ariaMatch
                        )
                    );

                    $this->assertSame(
                        $shouldBeActive ? 'true' : 'false',
                        $ariaMatch[1]
                    );
                } else {
                    $this->assertSame(
                        $shouldBeActive,
                        in_array('show', $classes, true),
                        "Status show {$id} tidak sesuai."
                    );
                }
            }
        }
    }

    public function test_no_connection_activates_csv_tab(): void
    {
        $response = $this->actingAs($this->owner)
            ->get($this->pageUrl());

        $response->assertOk();

        $this->assertActiveSourceTab($response, 'csv');
    }

    public function test_oauth_verification_activates_github_tab(): void
    {
        $response = $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_verified_repositories'
                    => $this->verifiedSession(),
            ])
            ->get($this->pageUrl());

        $response->assertOk();

        $this->assertActiveSourceTab($response, 'github');
    }

    public function test_saved_connection_activates_github_tab(): void
    {
        $connection = new GithubAppConnection();

        $connection->project_id = $this->project->id;
        $connection->installation_id = 12345;
        $connection->repository_id = 5678;
        $connection->repo_full_name = 'example/saved-repo';
        $connection->verified_github_user_id = 999;
        $connection->verified_at = now();
        $connection->save();

        $response = $this->actingAs($this->owner)
            ->get($this->pageUrl());

        $response->assertOk();

        $this->assertActiveSourceTab($response, 'github');
    }

    public function test_expired_oauth_keeps_csv_tab_active(): void
    {
        $session = $this->verifiedSession();

        $session['expires_at'] = now()
            ->subMinute()
            ->timestamp;

        $response = $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_verified_repositories'
                    => $session,
            ])
            ->get($this->pageUrl());

        $response->assertOk();

        $this->assertActiveSourceTab($response, 'csv');
    }

}
