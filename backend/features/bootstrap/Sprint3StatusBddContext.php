<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Soulcodex\Behat\Addon\Context;

/**
 * Sprint 3 / Feature 12: browser-level status flow through the real Blade
 * forms. FeatureContext guarantees an isolated SQLite :memory: database.
 */
final class Sprint3StatusBddContext extends Context
{
    private ?Project $project = null;
    private ?Issue $parent = null;
    private ?Issue $child = null;

    /** @Given a Sprint3 Feature12 project and linked duplicate exist */
    public function createStatusFixture(): void
    {
        // FeatureContext::iAmLoggedInAs creates the only user before this step.
        $actor = User::query()->firstOrFail();
        $owner = $actor->role === 'MAINTAINER'
            ? $actor
            : User::factory()->create(['role' => 'MAINTAINER']);

        $this->project = Project::factory()->create(['owner_id' => $owner->id]);

        if ($actor->role === 'REPORTER') {
            Membership::factory()->create([
                'project_id' => $this->project->id,
                'user_id' => $actor->id,
                'is_active' => true,
            ]);
        }

        $this->parent = Issue::forceCreate([
            'project_id' => $this->project->id,
            'author_id' => $actor->role === 'REPORTER' ? $actor->id : null,
            'title' => 'Status Sprint 3 - induk',
            'description' => 'Fixture pengujian status issue dan auto-close.',
            'source_type' => 'FORM',
            'status' => 'IN_PROGRESS',
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
        ]);
        $this->child = Issue::forceCreate([
            'project_id' => $this->project->id,
            'author_id' => $actor->role === 'REPORTER' ? $actor->id : null,
            'title' => 'Status Sprint 3 - duplikat',
            'description' => 'Fixture duplikat yang menaut ke issue induk.',
            'source_type' => 'FORM',
            'status' => 'DUPLICATE',
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
            'duplicate_of' => $this->parent->id,
        ]);
    }

    /** @Given the Sprint3 Feature12 parent is resolved and its duplicate is closed */
    public function prepareReopen(): void
    {
        $parent = $this->requiredParent();
        $child = $this->requiredChild();
        DB::table('issues')->where('id', $parent->id)->update(['status' => 'RESOLVED']);
        DB::table('issues')->where('id', $child->id)->update(['status' => 'CLOSED']);
    }

    /** @When I view Sprint3 Feature12 parent detail */
    public function viewParent(): void
    {
        $this->session()->visit('/projects/'.$this->requiredProject()->id
            .'/issues/'.$this->requiredParent()->id);
        $this->assertHttpStatus(200);
    }

    /** @Then the Sprint3 Feature12 form offers :target */
    public function assertAllowedStatus(string $target): void
    {
        $form = $this->session()->getPage()->find(
            'css', 'form[action*="/status/preview"]'
        );
        if ($form === null) {
            throw new RuntimeException('Expected owner-only status form.');
        }
        $select = $form->find('css', 'select[name="new_status"]');
        if ($select === null) {
            throw new RuntimeException('Status selector is missing.');
        }
        $html = $select->getOuterHtml();
        if (! str_contains($html, 'value="'.$target.'"')) {
            throw new RuntimeException('Expected legal status target '.$target);
        }
        if (str_contains($html, 'value="DUPLICATE"')) {
            throw new RuntimeException('DUPLICATE must never be selectable.');
        }
    }

    /** @When I preview Sprint3 Feature12 status :target */
    public function previewStatus(string $target): void
    {
        $form = $this->session()->getPage()->find(
            'css', 'form[action*="/status/preview"]'
        );
        if ($form === null) {
            throw new RuntimeException('Cannot find status preview form.');
        }
        $select = $form->find('css', 'select[name="new_status"]');
        if ($select === null) {
            throw new RuntimeException('Cannot find status selector.');
        }
        $select->selectOption($target);
        $form->submit();
    }

    /** @Then the Sprint3 Feature12 confirmation shows :count duplicate */
    public function assertAutoCloseCount(int $count): void
    {
        $this->assertHttpStatus(200);
        $page = preg_replace('/\s+/u', ' ', strip_tags(
            $this->session()->getPage()->getContent()
        ));
        if (! str_contains((string) $page,
            'Jumlah duplikat tertaut yang akan ditutup: '.$count)) {
            throw new RuntimeException('Auto-close count was not confirmed.');
        }
    }

    /** @When I confirm Sprint3 Feature12 status */
    public function confirmStatus(): void
    {
        $form = $this->session()->getPage()->find(
            'css', 'form[action*="/status/confirm"]'
        );
        if ($form === null) {
            throw new RuntimeException('Cannot find confirmation form.');
        }
        $form->submit();
    }

    /** @When an external Sprint3 Feature12 status change occurs */
    public function changeOutsideStatus(): void
    {
        DB::table('issues')->where('id', $this->requiredParent()->id)
            ->update(['status' => 'OPEN']);
    }

    /** @Then the Sprint3 Feature12 parent status is :status */
    public function assertParentStatus(string $status): void
    {
        $actual = $this->requiredParent()->fresh()->status;
        if ($actual !== $status) {
            throw new RuntimeException("Parent status {$actual}, expected {$status}.");
        }
    }

    /** @Then the Sprint3 Feature12 duplicate status is :status */
    public function assertChildStatus(string $status): void
    {
        $actual = $this->requiredChild()->fresh()->status;
        if ($actual !== $status) {
            throw new RuntimeException("Duplicate status {$actual}, expected {$status}.");
        }
    }

    /** @Then Sprint3 Feature12 created :count status audit entries */
    public function assertAuditCount(int $count): void
    {
        $actual = DB::table('activity_logs')
            ->where('project_id', $this->requiredProject()->id)
            ->whereIn('action', ['ISSUE_STATUS_CHANGED', 'DUPLICATE_AUTO_CLOSED'])
            ->count();
        if ($actual !== $count) {
            throw new RuntimeException("Status audit count {$actual}, expected {$count}.");
        }
    }

    /** @Then Sprint3 Feature12 shows :message */
    public function assertFlash(string $message): void
    {
        $html = $this->session()->getPage()->getContent();
        if (! str_contains($html, $message)) {
            throw new RuntimeException('Expected user feedback: '.$message);
        }
    }

    /** @Then Sprint3 Feature12 returns HTTP status :code */
    public function assertHttpStatus(int $code): void
    {
        $actual = $this->session()->getStatusCode();
        if ($actual !== $code) {
            throw new RuntimeException("Expected HTTP {$code}; got {$actual}.");
        }
    }

    /** @Then Sprint3 Feature12 does not offer a status form */
    public function assertNoStatusForm(): void
    {
        $form = $this->session()->getPage()->find(
            'css', 'form[action*="/status/preview"]'
        );
        if ($form !== null) {
            throw new RuntimeException('Unauthorized status form was rendered.');
        }
    }

    private function requiredProject(): Project
    {
        return $this->project ?? throw new RuntimeException('No project fixture.');
    }

    private function requiredParent(): Issue
    {
        return $this->parent ?? throw new RuntimeException('No parent fixture.');
    }

    private function requiredChild(): Issue
    {
        return $this->child ?? throw new RuntimeException('No duplicate fixture.');
    }
}
