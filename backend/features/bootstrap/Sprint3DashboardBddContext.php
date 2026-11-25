<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Soulcodex\Behat\Addon\Context;

/**
 * Sprint 3 / Feature 13 dashboard browser scenarios.
 *
 * FeatureContext provides authenticated Mink sessions
 * and the guarded SQLite in-memory database.
 */
final class Sprint3DashboardBddContext extends Context
{
    private ?Project $project = null;

    private ?Issue $parent = null;

    private ?Issue $child = null;

    /** @Given an empty Sprint3 Feature13 project exists */
    public function createEmptyProject(): void
    {
        $actor = User::query()->firstOrFail();

        $owner = $actor->role === 'MAINTAINER'
            ? $actor
            : User::factory()->create(['role' => 'MAINTAINER']);

        $this->project = Project::factory()->create([
            'owner_id' => $owner->id,
        ]);

        if ($actor->role === 'REPORTER') {
            Membership::factory()->create([
                'project_id' => $this->project->id,
                'user_id' => $actor->id,
                'is_active' => true,
            ]);
        }
    }

    /** @Given a populated Sprint3 Feature13 project exists */
    public function createPopulatedProject(): void
    {
        $this->createEmptyProject();

        $createdAt = now()->subMinutes(30);

        $this->parent = Issue::forceCreate([
            'project_id' => $this->requiredProject()->id,
            'author_id' => null,
            'title' => 'Dashboard local parent',
            'description' => 'Feature 13 parent issue fixture.',
            'source_type' => 'FORM',
            'status' => 'IN_PROGRESS',
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
            'created_at' => $createdAt,
        ]);

        $this->child = Issue::forceCreate([
            'project_id' => $this->requiredProject()->id,
            'author_id' => null,
            'title' => 'Dashboard local duplicate',
            'description' => 'Feature 13 duplicate issue fixture.',
            'source_type' => 'FORM',
            'status' => 'DUPLICATE',
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
            'duplicate_of' => $this->parent->id,
            'created_at' => $createdAt,
        ]);

        $foreignOwner = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $foreignProject = Project::factory()->create([
            'owner_id' => $foreignOwner->id,
        ]);

        Issue::forceCreate([
            'project_id' => $foreignProject->id,
            'author_id' => null,
            'title' => 'Dashboard foreign secret',
            'description' => 'Must not appear in another project.',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
        ]);
    }

    /** @Given a foreign Sprint3 Feature13 project exists */
    public function createForeignProject(): void
    {
        $owner = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $this->project = Project::factory()->create([
            'owner_id' => $owner->id,
        ]);
    }

    /** @When I open the Sprint3 Feature13 project page */
    public function openProject(): void
    {
        $this->session()->visit(
            '/projects/'.$this->requiredProject()->id
        );
    }

    /** @When I follow the Sprint3 Feature13 dashboard link */
    public function followDashboardLink(): void
    {
        $link = $this->session()->getPage()->findLink(
            'Buka Dashboard Statistik'
        );

        if ($link === null) {
            throw new RuntimeException(
                'Dashboard navigation link is missing.'
            );
        }

        $link->click();
    }

    /** @When I follow the Sprint3 Feature13 source link */
    public function followSourceLink(): void
    {
        $link = $this->session()->getPage()->findLink(
            'Impor CSV atau Hubungkan GitHub'
        );

        if ($link === null) {
            throw new RuntimeException(
                'Issue source navigation link is missing.'
            );
        }

        $link->click();
    }

    /** @When I open the Sprint3 Feature13 dashboard directly */
    public function openDashboard(): void
    {
        $this->session()->visit(
            '/projects/'.$this->requiredProject()->id.'/dashboard'
        );
    }

    /** @When I resolve the Sprint3 Feature13 parent through the status form */
    public function resolveParent(): void
    {
        $parent = $this->parent
            ?? throw new RuntimeException('Missing parent fixture.');

        $this->session()->visit(
            '/projects/'.$this->requiredProject()->id
            .'/issues/'.$parent->id
        );

        $this->assertHttpStatus(200);

        $form = $this->session()->getPage()->find(
            'css',
            'form[action*="/status/preview"]'
        );

        if ($form === null) {
            throw new RuntimeException(
                'Missing status preview form.'
            );
        }

        $select = $form->find(
            'css',
            'select[name="new_status"]'
        );

        if ($select === null) {
            throw new RuntimeException(
                'Missing status selector.'
            );
        }

        $select->selectOption('RESOLVED');
        $form->submit();

        $this->assertHttpStatus(200);

        $confirmation = $this->session()->getPage()->find(
            'css',
            'form[action*="/status/confirm"]'
        );

        if ($confirmation === null) {
            throw new RuntimeException(
                'Missing status confirmation form.'
            );
        }

        $confirmation->submit();

        $this->assertHttpStatus(200);

        if ($parent->fresh()->status !== 'RESOLVED') {
            throw new RuntimeException(
                'Parent issue was not resolved.'
            );
        }

        if ($this->child?->fresh()->status !== 'CLOSED') {
            throw new RuntimeException(
                'Linked duplicate was not auto-closed.'
            );
        }
    }

    /** @Then Sprint3 Feature13 returns HTTP status :code */
    public function assertHttpStatus(int $code): void
    {
        $actual = $this->session()->getStatusCode();

        if ($actual !== $code) {
            throw new RuntimeException(
                "Expected HTTP {$code}; received {$actual}."
            );
        }
    }

    /** @Then Sprint3 Feature13 shows :message */
    public function assertText(string $message): void
    {
        $text = $this->session()->getPage()->getText();

        if (! str_contains($text, $message)) {
            throw new RuntimeException(
                'Expected page text: '.$message
            );
        }
    }

    /** @Then Sprint3 Feature13 does not show :message */
    public function assertTextAbsent(string $message): void
    {
        $text = $this->session()->getPage()->getText();

        if (str_contains($text, $message)) {
            throw new RuntimeException(
                'Unexpected page text: '.$message
            );
        }
    }

    /** @Then Sprint3 Feature13 status :status has count :count */
    public function assertStatusCount(
        string $status,
        int $count
    ): void {
        $section = $this->session()->getPage()->find(
            'css',
            '[aria-labelledby="distribution-heading"]'
        );

        if ($section === null) {
            throw new RuntimeException(
                'Status distribution section is missing.'
            );
        }

        $text = preg_replace(
            '/\s+/u',
            ' ',
            $section->getText()
        );

        $pattern = '/(?<![A-Z_])'
            .preg_quote($status, '/')
            .'\s+'
            .preg_quote((string) $count, '/')
            .'\s*\(/u';

        if (! preg_match($pattern, (string) $text)) {
            throw new RuntimeException(
                "Expected status {$status} count {$count}; got {$text}"
            );
        }
    }

    /** @Then Sprint3 Feature13 does not offer a dashboard link */
    public function assertNoDashboardLink(): void
    {
        $link = $this->session()->getPage()->find(
            'css',
            'a[href$="/dashboard"]'
        );

        if ($link !== null) {
            throw new RuntimeException(
                'Unauthorized dashboard link was rendered.'
            );
        }
    }

    /** @Then Sprint3 Feature13 has recorded both status audit events */
    public function assertAuditEvents(): void
    {
        $query = DB::table('activity_logs')
            ->where('project_id', $this->requiredProject()->id);

        $statusCount = (clone $query)
            ->where('action', 'ISSUE_STATUS_CHANGED')
            ->where('issue_id', $this->parent?->id)
            ->count();

        $autoCloseCount = (clone $query)
            ->where('action', 'DUPLICATE_AUTO_CLOSED')
            ->where('issue_id', $this->child?->id)
            ->count();

        if ($statusCount !== 1 || $autoCloseCount !== 1) {
            throw new RuntimeException(
                'Expected one status change and one auto-close audit.'
            );
        }
    }

    private function requiredProject(): Project
    {
        return $this->project
            ?? throw new RuntimeException('Missing project fixture.');
    }
}
