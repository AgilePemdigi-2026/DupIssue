<?php

declare(strict_types=1);

use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Soulcodex\Behat\Addon\Context;

/**
 * Sprint 3 / Feature 14 activity log browser scenarios.
 *
 * FeatureContext provides authenticated Mink sessions
 * and the guarded SQLite in-memory database.
 */
final class Sprint3ActivityBddContext extends Context
{
    private ?Project $project = null;

    private ?Project $foreignProject = null;

    private function requiredProject(): Project
    {
        Assert::assertNotNull($this->project, 'Sprint3 Feature14 project fixture must be created first.');

        return $this->project;
    }

    /** @Given an empty Sprint3 Feature14 project exists */
    public function createEmptyProject(): void
    {
        $actor = User::query()->firstOrFail();

        $owner = $actor->role === 'MAINTAINER'
            ? $actor
            : User::factory()->create(['role' => 'MAINTAINER']);

        $this->project = Project::factory()->create([
            'owner_id' => $owner->id,
            'name' => 'Sprint 3 Activity Test Project',
        ]);

        if ($actor->role === 'REPORTER') {
            Membership::factory()->create([
                'project_id' => $this->project->id,
                'user_id' => $actor->id,
                'is_active' => true,
            ]);
        }
    }

    /** @Given a populated Sprint3 Feature14 project exists with mixed activities */
    public function createPopulatedProject(): void
    {
        $this->createEmptyProject();
        $owner = User::query()->where('id', $this->requiredProject()->owner_id)->firstOrFail();

        // Activity on this project
        DB::table('activity_logs')->insert([
            [
                'project_id' => $this->requiredProject()->id,
                'user_id' => $owner->id,
                'issue_id' => null,
                'action' => 'CREATE_PROJECT',
                'old_values' => null,
                'new_values' => json_encode(['name' => $this->requiredProject()->name], JSON_THROW_ON_ERROR),
                'reason' => 'Inisialisasi project',
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ],
            [
                'project_id' => $this->requiredProject()->id,
                'user_id' => $owner->id,
                'issue_id' => null,
                'action' => 'INVITE_REPORTER',
                'old_values' => null,
                'new_values' => json_encode(['email' => 'dev@dupissue.test'], JSON_THROW_ON_ERROR),
                'reason' => 'Undangan anggota tim frontend',
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ],
        ]);

        // Foreign project with secret activity
        $foreignOwner = User::factory()->create(['role' => 'MAINTAINER']);
        $this->foreignProject = Project::factory()->create([
            'owner_id' => $foreignOwner->id,
            'name' => 'Foreign Secret Project',
        ]);

        DB::table('activity_logs')->insert([
            'project_id' => $this->foreignProject->id,
            'user_id' => $foreignOwner->id,
            'issue_id' => null,
            'action' => 'UPDATE_PROJECT',
            'old_values' => null,
            'new_values' => null,
            'reason' => 'Secret Foreign Log',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);
    }

    /** @Given a foreign Sprint3 Feature14 project exists */
    public function createForeignProjectOnly(): void
    {
        $foreignOwner = User::factory()->create(['role' => 'MAINTAINER']);
        $this->foreignProject = Project::factory()->create([
            'owner_id' => $foreignOwner->id,
            'name' => 'Foreign Unrelated Project',
        ]);
    }

    /** @When I open the Sprint3 Feature14 project page */
    public function openProjectPage(): void
    {
        $this->session()->visit('/projects/' . $this->requiredProject()->id);
    }

    /** @When I follow the Sprint3 Feature14 activity log link */
    public function followActivityLogLink(): void
    {
        $this->session()->getPage()->clickLink('Riwayat & Log');
    }

    /** @When I open the Sprint3 Feature14 activity log directly */
    public function openActivityLogDirectly(): void
    {
        $this->session()->visit('/projects/' . $this->requiredProject()->id . '/activity');
    }

    /** @When I open the foreign Sprint3 Feature14 activity log directly */
    public function openForeignActivityLogDirectly(): void
    {
        Assert::assertNotNull($this->foreignProject, 'Foreign project fixture must exist.');
        $this->session()->visit('/projects/' . $this->foreignProject->id . '/activity');
    }

    /** @When I filter Sprint3 Feature14 by category :category */
    public function filterByCategory(string $category): void
    {
        $this->session()->visit('/projects/' . $this->requiredProject()->id . '/activity?category=' . urlencode($category));
    }

    /** @When I search Sprint3 Feature14 with keyword :keyword */
    public function searchWithKeyword(string $keyword): void
    {
        $this->session()->visit('/projects/' . $this->requiredProject()->id . '/activity?keyword=' . urlencode($keyword));
    }

    /** @When I export the Sprint3 Feature14 activity log to CSV */
    public function exportToCsv(): void
    {
        $this->session()->visit('/projects/' . $this->requiredProject()->id . '/activity/export');
    }

    /** @Then Sprint3 Feature14 returns HTTP status :status */
    public function assertHttpStatus(int $status): void
    {
        Assert::assertSame($status, $this->session()->getStatusCode());
    }

    /** @Then Sprint3 Feature14 shows :text */
    public function assertPageShows(string $text): void
    {
        $content = $this->session()->getPage()->getText();
        Assert::assertStringContainsString($text, $content);
    }

    /** @Then Sprint3 Feature14 does not show :text */
    public function assertPageDoesNotShow(string $text): void
    {
        $content = $this->session()->getPage()->getText();
        Assert::assertStringNotContainsString($text, $content);
    }

    /** @Then Sprint3 Feature14 does not offer an activity log link */
    public function assertNoActivityLogLink(): void
    {
        $link = $this->session()->getPage()->findLink('Riwayat & Log');
        Assert::assertNull($link, 'Activity log link should not be offered to non-owners.');
    }

    /** @Then Sprint3 Feature14 CSV contains :text */
    public function assertCsvContains(string $text): void
    {
        $content = $this->session()->getPage()->getContent();
        Assert::assertStringContainsString($text, $content);
    }

    /** @Then Sprint3 Feature14 CSV does not contain :text */
    public function assertCsvDoesNotContain(string $text): void
    {
        $content = $this->session()->getPage()->getContent();
        Assert::assertStringNotContainsString($text, $content);
    }
}

