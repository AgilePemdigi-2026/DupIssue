<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Soulcodex\Behat\Addon\Context;

/**
 * Sprint 1 BDD glue: real Blade forms through Mink + real Laravel queue worker,
 * with only the NLP HTTP service replaced by a deterministic fake.
 * NEVER run against the normal development database.
 */
final class FeatureContext extends Context
{
    private ?Project $project = null;
    private ?User $user = null;
    private ?Issue $sameProjectCandidate = null;
    private ?Issue $foreignProjectCandidate = null;
    private int $baselineIssueId = 0;

    /** @BeforeScenario */
    public function beforeScenario(BeforeScenarioScope $scope): void
    {
        $app = $this->container();
        $connection = config('database.default');
        $database = config('database.connections.sqlite.database');
        if (! $app->environment('behat') || $app->configurationIsCached()
            || $connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException('BDD REFUSED: requires APP_ENV=behat, uncached config, and SQLite :memory:.');
        }
        DB::purge('sqlite');
        $pdo = DB::connection('sqlite')->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            || DB::connection('sqlite')->selectOne('PRAGMA database_list')->file !== '') {
            throw new RuntimeException('BDD REFUSED: database is not isolated SQLite memory.');
        }
        // Isolate browser and Laravel authentication between BDD scenarios.
        $this->session()->reset();
        app('session.store')->flush();
        app('auth')->forgetGuards();

        // Safe: assertions above guarantee these migrations cannot destroy MySQL/dev data.
        Artisan::call('migrate:fresh', ['--force' => true]);
        config(['services.nlp.base_url' => 'http://nlp.test']);
        Http::preventStrayRequests();
        Http::fake([]);
        $this->project = null;
        $this->user = null;
        $this->sameProjectCandidate = null;
        $this->foreignProjectCandidate = null;
        $this->baselineIssueId = 0;
    }

    /** @Given I am logged in as :email with role :role */
    public function iAmLoggedInAs(string $email, string $role): void
    {
        $password = 'BddTemporaryPassword123!';
        $this->user = User::factory()->create([
            'email' => $email,
            'role' => strtoupper($role),
            'password' => $password,
        ]);
        // Authenticate through the real web form, not Auth::login() in another session.
        $this->visitUrl('/login');
        $page = $this->session()->getPage();
        $page->fillField('email', $email);
        $page->fillField('password', $password);
        $form = $page->find('css', 'form');
        if ($form === null) {
            throw new RuntimeException('Login form was not found.');
        }
        $form->submit();
        if (str_contains($this->session()->getCurrentUrl(), '/login')) {
            throw new RuntimeException('BDD fixture login failed. Check login form/route.');
        }
    }

    /** @Given I am an active member of project :name with id :id */
    public function iAmAnActiveMember(string $name, string $id): void
    {
        if ($this->user === null) {
            throw new RuntimeException('Reporter must be created before project membership.');
        }
        $this->project = Project::factory()->create(['name' => $name]);
        if ((string) $this->project->id !== $id) {
            throw new RuntimeException('SRS fixture expects project #'.$id.'; got #'.$this->project->id);
        }
        Membership::factory()->create([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);
    }

    /** @Given I am not an active member of project :name */
    public function iAmNotAnActiveMember(string $name): void
    {
        if ($this->project === null || $this->project->name !== $name || $this->user === null) {
            throw new RuntimeException('Unknown fixture project.');
        }
        DB::table('memberships')->where('project_id', $this->project->id)
            ->where('user_id', $this->user->id)->update(['is_active' => false]);
    }

    /** @Given I have an existing issue :title in my project */
    public function iHaveAnExistingIssue(string $title): void
    {
        $this->sameProjectCandidate = $this->createIssue($this->requiredProject(), $title);
    }

    /** @Given I have an existing issue :title in another project */
    public function iHaveAnIssueInAnotherProject(string $title): void
    {
        $foreignProject = Project::factory()->create();
        $this->foreignProjectCandidate = $this->createIssue($foreignProject, $title);
    }

    private function createIssue(Project $project, string $title): Issue
    {
        $issue = Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $this->user?->id,
            'title' => $title,
            'description' => 'Kandidat issue untuk pengujian BDD pada Sprint 1.',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
        ]);
        $this->baselineIssueId = max($this->baselineIssueId, (int) $issue->id);
        return $issue;
    }

    /** @Given I have an NLP response with a same-project similarity of :same and a foreign-project similarity of :foreign */
    public function iHaveAnNlpResponse(float $same, float $foreign): void
    {
        if (! $this->sameProjectCandidate || ! $this->foreignProjectCandidate) {
            throw new RuntimeException('Create both comparison issues first.');
        }
        Http::fake(['http://nlp.test/candidates' => Http::response(['candidates' => [
            ['issue_id' => $this->sameProjectCandidate->id, 'similarity' => $same],
            ['issue_id' => $this->foreignProjectCandidate->id, 'similarity' => $foreign],
        ]])]);
    }

    /** @Given I have an NLP service failure with HTTP status :status */
    public function iHaveAnNlpServiceFailure(int $status): void
    {
        Http::fake(['http://nlp.test/candidates' => Http::response(['detail' => 'NLP unavailable'], $status)]);
    }

    /** @When I process one queued NLP job */
    public function iProcessOneQueuedNlpJob(): void
    {
        $status = Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => 'nlp',
            '--once' => true, '--force' => true, '--sleep' => 0,
        ]);
        if ($status !== 0) {
            throw new RuntimeException('The database queue worker failed, exit: '.$status);
        }
    }

    /** @Then I should find one new issue with status :status and detection :detection */
    public function iShouldFindOneNewIssue(string $status, string $detection): void
    {
        $issue = $this->newIssue();
        if ($issue->status !== $status || $issue->detection_status !== $detection) {
            throw new RuntimeException("Unexpected issue statuses: {$issue->status}/{$issue->detection_status}");
        }
        if ($issue->source_type !== 'FORM' || (int) $issue->vote_count !== 0
            || (int) $issue->author_id !== (int) $this->user?->id
            || (int) $issue->project_id !== (int) $this->requiredProject()->id) {
            throw new RuntimeException('REQ-01.11 server-controlled fields are incorrect.');
        }
    }

    /** @Then I should find one NLP job in the :queue queue */
    public function iShouldFindOneNlpJob(string $queue): void
    {
        $jobs = DB::table('jobs')->where('queue', $queue)->get();
        if ($jobs->count() !== 1) {
            throw new RuntimeException("Expected one {$queue} job; got {$jobs->count()}.");
        }
    }

    /** @Then I should find the new issue with detection :status */
    public function iShouldFindDetection(string $status): void
    {
        if ($this->newIssue()->detection_status !== $status) {
            throw new RuntimeException('Detection status is not '.$status);
        }
    }

    /** @Then I should find the new issue with status :status and no duplicate parent */
    public function iShouldFindNoAutomaticDuplicate(string $status): void
    {
        $issue = $this->newIssue();
        if ($issue->status !== $status || $issue->duplicate_of !== null) {
            throw new RuntimeException('NLP must never auto-assign DUPLICATE or duplicate_of.');
        }
    }

    /** @Then I should find a duplicate candidate from the same project with similarity :score */
    public function iShouldFindSameProjectCandidate(float $score): void
    {
        $saved = DB::table('duplicate_candidates')->where('issue_id', $this->newIssue()->id)
            ->where('candidate_issue_id', $this->sameProjectCandidate?->id)->first();
        if ($saved === null || abs((float) $saved->similarity_score - $score) > 0.00001) {
            throw new RuntimeException('Expected same-project candidate with similarity '.$score);
        }
    }

    /** @Then I should find no duplicate candidates from another project */
    public function iShouldFindNoForeignCandidates(): void
    {
        $count = DB::table('duplicate_candidates')->where('issue_id', $this->newIssue()->id)
            ->where('candidate_issue_id', $this->foreignProjectCandidate?->id)->count();
        if ($count !== 0) {
            throw new RuntimeException('REQ-01 project isolation violated by NLP candidate.');
        }
    }

    /** @Then I should find no new issues in the database */
    public function iShouldFindNoNewIssues(): void
    {
        if (Issue::query()->where('id', '>', $this->baselineIssueId)->exists()) {
            throw new RuntimeException('Invalid or unauthorized submit created an issue.');
        }
    }

    /** @Then I should see validation error for :field */
    public function iShouldSeeValidationError(string $field): void
    {
        $element = $this->session()->getPage()->find('css', '#'.$field.'.is-invalid');
        if ($element === null) {
            throw new RuntimeException('Expected visible validation error for '.$field);
        }
    }

    /** @Then I should be denied project access */
    public function iShouldBeDeniedProjectAccess(): void
    {
        $status = $this->session()->getStatusCode();
        if ($status !== 403) {
            throw new RuntimeException('Expected HTTP 403; got '.$status);
        }
    }

    private function requiredProject(): Project
    {
        return $this->project ?? throw new RuntimeException('Project fixture has not been created.');
    }

    private function newIssue(): Issue
    {
        return Issue::query()->where('project_id', $this->requiredProject()->id)
            ->where('id', '>', $this->baselineIssueId)
            ->orderByDesc('id')->firstOrFail();
    }
}
