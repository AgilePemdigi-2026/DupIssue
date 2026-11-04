<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Soulcodex\Behat\Addon\Context;

/**
 * Sprint 2 REQ-02 BDD steps; FeatureContext is responsible for secure
 * per-scenario in-memory SQLite migration and real browser authentication.
 */
final class IssueStatusBddContext extends Context
{
    private ?int $duplicateId = null;
    private ?int $statusIssueId = null;
    private ?array $beforeRefresh = null;

    /** @Given I have a duplicate issue and its original issue in my project */
    public function iHaveDuplicateWithOriginal(): void
    {
        $project = Project::findOrFail(1);
        $parent = Issue::forceCreate([
            'project_id' => $project->id,
            'title' => 'Issue induk untuk REQ-02',
            'description' => 'Issue original untuk pengujian tautan induk.',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
        ]);
        $child = Issue::forceCreate([
            'project_id' => $project->id,
            'title' => 'Anak duplikat untuk REQ-02',
            'description' => 'Issue duplikat yang harus menautkan induknya.',
            'source_type' => 'FORM',
            'status' => 'DUPLICATE',
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
            'duplicate_of' => $parent->id,
        ]);
        $this->duplicateId = (int) $child->id;
    }

    /** @When I open the duplicate issue detail */
    public function iOpenDuplicateDetail(): void
    {
        if ($this->duplicateId === null) {
            throw new RuntimeException('Duplicate fixture is missing.');
        }
        $this->session()->visit('/projects/1/issues/'.$this->duplicateId);
        if ($this->session()->getStatusCode() !== 200) {
            throw new RuntimeException('Duplicate issue detail did not load.');
        }
    }

    /** @When I request current issue status from the read-only API */
    public function iRequestCurrentStatus(): void
    {
        $issue = Issue::query()->where('project_id', 1)->orderBy('id')->firstOrFail();
        $this->statusIssueId = (int) $issue->id;
        $this->beforeRefresh = (array) DB::table('issues')->where('id', $issue->id)->first();
        $this->session()->visit('/projects/1/issues/'.$issue->id.'/status');
    }

    /** @Then I should receive issue status :status */
    public function iReceiveIssueStatus(string $status): void
    {
        if ($this->session()->getStatusCode() !== 200) {
            throw new RuntimeException('Expected HTTP 200 read-only status response.');
        }
        $data = json_decode($this->session()->getPage()->getContent(), true);
        if (! is_array($data) || ($data['status'] ?? null) !== $status) {
            throw new RuntimeException('Read-only refresh returned unexpected status.');
        }
    }

    /** @Then the status refresh should not mutate the issue */
    public function refreshShouldNotMutateIssue(): void
    {
        if ($this->statusIssueId === null || $this->beforeRefresh === null) {
            throw new RuntimeException('Read-only refresh fixture is missing.');
        }
        $after = (array) DB::table('issues')->where('id', $this->statusIssueId)->first();
        if ($after !== $this->beforeRefresh) {
            throw new RuntimeException('REQ-02 refresh unexpectedly mutated issue.');
        }
    }
}
