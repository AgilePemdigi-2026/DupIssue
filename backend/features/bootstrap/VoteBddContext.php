<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Soulcodex\Behat\Addon\Context;

/**
 * Sprint 2 Feature 9 Vote/Unvote acceptance steps. FeatureContext sets up
 * a real authenticated Mink session and guarded SQLite :memory: fixtures.
 * No external API or persistent database access is required here.
 */
final class VoteBddContext extends Context
{
    private ?int $issueId = null;
    private ?int $votingTargetId = null;
    private ?int $childId = null;
    private ?int $foreignIssueId = null;
    private ?string $foreignIssueTitle = null;

    /** @Given I have an open issue for voting */
    public function iHaveOpenIssueForVoting(): void
    {
        $project = Project::findOrFail(1);
        $reporter = $this->reporter();
        $issue = $this->createIssue($project, $reporter, 'Issue Voting utama', 'OPEN');
        $this->issueId = (int) $issue->id;
        $this->votingTargetId = (int) $issue->id;
    }

    /** @Given I have a CLOSED duplicate child and its original voting issue */
    public function iHaveClosedDuplicateForVoting(): void
    {
        $project = Project::findOrFail(1);
        $reporter = $this->reporter();
        $parent = $this->createIssue($project, $reporter, 'Induk voting sah', 'OPEN');
        $child = $this->createIssue($project, $reporter, 'Duplikat CLOSED', 'CLOSED', (int) $parent->id);
        $this->issueId = (int) $child->id;
        $this->votingTargetId = (int) $parent->id;
        $this->childId = (int) $child->id;
    }

    /** @Given I have a duplicate issue linked to a foreign-project issue for voting */
    public function iHaveForeignParentForVoting(): void
    {
        $project = Project::findOrFail(1);
        $reporter = $this->reporter();
        $foreignProject = Project::factory()->create();
        $foreign = $this->createIssue($foreignProject, $reporter, 'Rahasia induk project lain', 'OPEN');
        $child = $this->createIssue($project, $reporter, 'Duplikat dengan induk asing', 'DUPLICATE', (int) $foreign->id);
        $this->issueId = (int) $child->id;
        $this->childId = (int) $child->id;
        $this->foreignIssueId = (int) $foreign->id;
        $this->foreignIssueTitle = (string) $foreign->title;
    }

    /** @When I open the voting issue detail */
    public function iOpenVotingIssueDetail(): void
    {
        $this->session()->visit('/projects/1/issues/'.$this->requiredIssueId());
        $this->assertStatus(200, 'open issue detail');
    }

    /** @When I submit Vote using the issue form */
    public function iSubmitVoteUsingIssueForm(): void
    {
        $page = $this->session()->getPage();
        if ($page->findButton('Vote Issue') === null) {
            throw new RuntimeException('Voting form is not available to the Reporter.');
        }
        $page->pressButton('Vote Issue');
        $this->assertStatus(200, 'Vote form/redirect');
    }

    /** @When I submit Unvote using the issue form */
    public function iSubmitUnvoteUsingIssueForm(): void
    {
        $page = $this->session()->getPage();
        if ($page->findButton('Batalkan Vote') === null) {
            throw new RuntimeException('Unvote form is missing after voting.');
        }
        $page->pressButton('Batalkan Vote');
        $this->assertStatus(200, 'Unvote form/redirect');
    }

    /** @Then the Issue Status refresh controls should remain available */
    public function statusControlsRemainAvailable(): void
    {
        $page = $this->session()->getPage();
        foreach (['#issue-status-refresh', '#issue-status-history'] as $selector) {
            if ($page->find('css', $selector) === null) {
                throw new RuntimeException('Integration regression: missing '.$selector);
            }
        }
    }

    /** @Then the voting target should have :count vote */
    public function votingTargetShouldHaveSingular(int $count): void
    {
        $this->votingTargetShouldHave($count);
    }

    /** @Then the voting target should have :count votes */
    public function votingTargetShouldHave(int $count): void
    {
        $target = $this->requiredTargetId();
        $records = DB::table('votes')->where('issue_id', $target)->count();
        $saved = (int) Issue::findOrFail($target)->vote_count;
        if ($records !== $count || $saved !== $count) {
            throw new RuntimeException("Vote mismatch for issue {$target}: votes={$records}, vote_count={$saved}, expected={$count}");
        }
    }

    /** @Then the voting audit should contain exactly one :action event */
    public function votingAuditShouldContainOne(string $action): void
    {
        if (!in_array($action, ['VOTE_ADDED', 'VOTE_REMOVED'], true)) {
            throw new RuntimeException('Unexpected Vote audit action: '.$action);
        }
        $n = DB::table('activity_logs')
            ->where('project_id', 1)
            ->where('issue_id', $this->requiredTargetId())
            ->where('user_id', $this->reporter()->id)
            ->where('action', $action)
            ->count();
        if ($n !== 1) {
            throw new RuntimeException("Expected one {$action} audit record, found {$n}");
        }
    }

    /** @Then the duplicate child should have zero votes */
    public function duplicateChildShouldHaveZeroVotes(): void
    {
        $child = $this->childId ?? throw new RuntimeException('No duplicate child in fixture.');
        if (DB::table('votes')->where('issue_id', $child)->exists()
            || (int) Issue::findOrFail($child)->vote_count !== 0) {
            throw new RuntimeException('Vote was incorrectly stored on the duplicate child.');
        }
    }

    /** @Then I should be redirected to the original voting issue */
    public function iShouldBeRedirectedToOriginalIssue(): void
    {
        $actual = parse_url($this->session()->getCurrentUrl(), PHP_URL_PATH);
        $expected = '/projects/1/issues/'.$this->requiredTargetId();
        if ($actual !== $expected) {
            throw new RuntimeException('Expected final page '.$expected.', got '.(string) $actual);
        }
    }

    /** @Then voting controls must be hidden */
    public function votingControlsMustBeHidden(): void
    {
        $page = $this->session()->getPage();
        if ($page->findButton('Vote Issue') !== null || $page->findButton('Batalkan Vote') !== null) {
            throw new RuntimeException('Unsafe Vote/Unvote button exposed for invalid parent.');
        }
    }

    /** @Then the foreign issue detail must not be disclosed */
    public function foreignIssueDetailMustNotBeDisclosed(): void
    {
        if ($this->foreignIssueId === null || $this->foreignIssueTitle === null) {
            throw new RuntimeException('Missing foreign-parent fixture.');
        }
        $page = $this->session()->getPage();
        $text = $page->getText();
        if (str_contains($text, $this->foreignIssueTitle)) {
            throw new RuntimeException('Foreign-project issue title was leaked.');
        }
        $forbiddenUrl = '/projects/1/issues/'.$this->foreignIssueId;
        $html = $page->getContent();
        if (str_contains($html, 'href="'.$forbiddenUrl.'"')) {
            throw new RuntimeException('Foreign-project parent link was leaked.');
        }
    }

    private function assertStatus(int $expected, string $context): void
    {
        $status = (int) $this->session()->getStatusCode();
        if ($status !== $expected) {
            throw new RuntimeException("Unexpected status {$status} for {$context}; expected {$expected}");
        }
    }

    private function reporter(): User
    {
        return User::query()->where('email', 'reporter.vote@dupissue.test')
            ->where('role', 'REPORTER')->firstOrFail();
    }

    private function requiredIssueId(): int
    {
        return $this->issueId ?? throw new RuntimeException('Voting issue fixture is missing.');
    }

    private function requiredTargetId(): int
    {
        return $this->votingTargetId ?? throw new RuntimeException('Vote target fixture is missing.');
    }

    private function createIssue(
        Project $project,
        User $author,
        string $title,
        string $status,
        ?int $duplicateOf = null
    ): Issue {
        return Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $author->id,
            'title' => $title,
            'description' => 'Issue untuk acceptance test Voting Sprint 2.',
            'source_type' => 'FORM',
            'status' => $status,
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
            'duplicate_of' => $duplicateOf,
        ]);
    }
}
