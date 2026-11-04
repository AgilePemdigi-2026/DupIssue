<?php

declare(strict_types=1);

use App\Models\Invitation;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Soulcodex\Behat\Addon\Context;

/**
 * REQ-03 / REQ-07.2 acceptance steps through actual Laravel Blade pages.
 * FeatureContext::beforeScenario provides guarded in-memory SQLite migration,
 * browser reset, and auth isolation. Never migrate a database here.
 */
final class ProjectBddContext extends Context
{
    private const PASSWORD = 'BddTemporaryPassword123!';
    private ?Project $project = null;
    private ?User $reporter = null;
    private ?Issue $historicalIssue = null;

    /** @Then I should have no authenticated user */
    public function iShouldHaveNoAuthenticatedUser(): void
    {
        if (Auth::check()) {
            throw new RuntimeException('Logout failed: Laravel auth guard is still authenticated.');
        }
    }

    /** @Given I have a registered Reporter :email */
    public function iHaveRegisteredReporter(string $email): void
    {
        $this->reporter = User::factory()->create([
            'email' => $email, 'role' => 'REPORTER', 'password' => self::PASSWORD,
        ]);
    }

    /** @Given I have a registered Maintainer :email */
    public function iHaveRegisteredMaintainer(string $email): void
    {
        User::factory()->create([
            'email' => $email, 'role' => 'MAINTAINER', 'password' => self::PASSWORD,
        ]);
    }

    /** @Given I own a project named :name */
    public function iOwnAProjectNamed(string $name): void
    {
        $user = Auth::user();
        if ($user === null || $user->role !== 'MAINTAINER') {
            throw new RuntimeException('Project fixture must belong to a logged-in Maintainer.');
        }
        $this->project = Project::forceCreate([
            'name' => $name, 'description' => 'Project untuk BDD Sprint 1',
            'owner_id' => $user->id,
        ]);
    }

    /** @Given I have a pending invitation for Reporter :email */
    public function iHavePendingInvitation(string $email): void
    {
        $project = $this->requireProject();
        $reporter = $this->reporterByEmail($email);
        Invitation::create([
            'project_id' => $project->id, 'invited_by' => Auth::id(),
            'reporter_id' => $reporter->id, 'status' => 'PENDING',
            'created_at' => now(),
        ]);
    }

    /** @Given I have an active Reporter :email with an existing issue */
    public function iHaveActiveReporterWithIssue(string $email): void
    {
        $project = $this->requireProject();
        $reporter = $this->reporterByEmail($email);
        Membership::create([
            'project_id' => $project->id, 'user_id' => $reporter->id,
            'is_active' => true, 'joined_at' => now(), 'removed_at' => null,
        ]);
        $this->historicalIssue = Issue::forceCreate([
            'title' => 'Bug historis dari Reporter',
            'description' => 'Laporan yang harus tetap ada setelah anggota dihapus',
            'project_id' => $project->id, 'author_id' => $reporter->id,
            'source_type' => 'FORM', 'status' => 'OPEN',
            'detection_status' => 'COMPLETED', 'vote_count' => 0,
        ]);
    }

    /** @When I sign in as registered Reporter :email */
    public function iSignInAsReporter(string $email): void
    {
        $this->loginAsRegisteredUser($email, 'REPORTER');
    }

    /** @When I sign in as registered Maintainer :email */
    public function iSignInAsMaintainer(string $email): void
    {
        $this->loginAsRegisteredUser($email, 'MAINTAINER');
    }

    /** @When I confirm the pending invitation cancellation */
    public function iConfirmPendingInvitationCancellation(): void
    {
        $form = $this->session()->getPage()->find('css', 'form[action$="/cancel"]');
        if ($form === null) {
            throw new RuntimeException('Real invitation cancellation form not found.');
        }
        $form->submit();
    }

    /** @When I confirm removal of Reporter :email */
    public function iConfirmReporterRemoval(string $email): void
    {
        $reporter = $this->reporterByEmail($email);
        $form = $this->session()->getPage()
            ->find('css', 'form[action$="/members/'.$reporter->id.'/remove"]');
        if ($form === null) {
            throw new RuntimeException('Real member removal confirmation form not found.');
        }
        $form->submit();
    }

    /** @Then I should find project :name owned by the current Maintainer */
    public function iShouldFindMaintainerProject(string $name): void
    {
        $project = Project::query()->where('name', $name)->first();
        if (!$project || (int) $project->owner_id !== (int) Auth::id()) {
            throw new RuntimeException('Project missing or owner_id not the logged-in Maintainer.');
        }
        $this->project = $project;
    }

    /** @Then I should find invitation for :email with status :status */
    public function iShouldFindInvitationStatus(string $email, string $status): void
    {
        $reporter = $this->reporterByEmail($email);
        $invitation = Invitation::query()->where('project_id', $this->requireProject()->id)
            ->where('reporter_id', $reporter->id)->orderByDesc('id')->first();
        if ($invitation === null || $invitation->status !== $status) {
            throw new RuntimeException('Expected invitation '.$status.' for '.$email);
        }
        if ($status !== 'PENDING' && $invitation->responded_at === null) {
            throw new RuntimeException('Answered invitation is missing responded_at.');
        }
    }

    /** @Then I should find :count invitation records for :email */
    public function iShouldFindInvitationCount(int $count, string $email): void
    {
        $actual = Invitation::query()->where('project_id', $this->requireProject()->id)
            ->where('reporter_id', $this->reporterByEmail($email)->id)->count();
        if ($actual !== $count) {
            throw new RuntimeException("Expected {$count} invitations for {$email}; got {$actual}.");
        }
    }

    /** @Then I should find no active membership for :email */
    public function iShouldFindNoActiveMembership(string $email): void
    {
        $actual = Membership::query()->where('project_id', $this->requireProject()->id)
            ->where('user_id', $this->reporterByEmail($email)->id)
            ->where('is_active', true)->count();
        if ($actual !== 0) {
            throw new RuntimeException("Reporter {$email} unexpectedly has active project access.");
        }
    }

    /** @Then I should find active membership for :email */
    public function iShouldFindActiveMembership(string $email): void
    {
        $membership = $this->membershipFor($email);
        if ($membership === null || !$membership->is_active || $membership->removed_at !== null) {
            throw new RuntimeException("Reporter {$email} should have active membership.");
        }
    }

    /** @Then I should find inactive membership for :email */
    public function iShouldFindInactiveMembership(string $email): void
    {
        $membership = $this->membershipFor($email);
        if ($membership === null || $membership->is_active || $membership->removed_at === null) {
            throw new RuntimeException("Reporter {$email} should have inactive membership with removed_at.");
        }
    }

    /** @Then I should find the existing issue preserved */
    public function iShouldFindHistoricalIssuePreserved(): void
    {
        if (!$this->historicalIssue || !Issue::query()->whereKey($this->historicalIssue->id)
            ->where('project_id', $this->requireProject()->id)
            ->where('author_id', $this->historicalIssue->author_id)->exists()) {
            throw new RuntimeException('Removing a member deleted their historical issue.');
        }
    }

    /** @Then I should find no projects created by the current Maintainer */
    public function iShouldFindNoCreatedProjects(): void
    {
        $count = Project::query()->where('owner_id', Auth::id())->count();
        if ($count !== 0) {
            throw new RuntimeException("Expected no projects owned by current user; got {$count}.");
        }
    }

    /** @Then I should find exactly one project named :name */
    public function iShouldFindExactlyOneProject(string $name): void
    {
        $count = Project::query()->where('name', $name)->count();
        if ($count !== 1) {
            throw new RuntimeException("Expected one project named {$name}; got {$count}.");
        }
    }

    /** @Then I should find no project invitations */
    public function iShouldFindNoProjectInvitations(): void
    {
        $count = Invitation::query()->where('project_id', $this->requireProject()->id)->count();
        if ($count !== 0) {
            throw new RuntimeException('Invalid invitation request created a database invitation.');
        }
    }

    private function loginAsRegisteredUser(string $email, string $role): void
    {
        $user = User::query()->where('email', $email)->where('role', $role)->first();
        if ($user === null) {
            throw new RuntimeException("Registered {$role} {$email} fixture not found.");
        }
        if (Auth::check()) {
            $this->session()->getPage()->pressButton('Logout');
            if (Auth::check()) {
                throw new RuntimeException('Previous account was not logged out.');
            }
        }
        $this->visitUrl('/login');
        $page = $this->session()->getPage();
        $page->fillField('email', $email);
        $page->fillField('password', self::PASSWORD);
        $form = $page->find('css', 'form');
        if ($form === null) {
            throw new RuntimeException('Real login form not found.');
        }
        $form->submit();
        if ((int) Auth::id() !== (int) $user->id) {
            throw new RuntimeException("Login as {$email} was unsuccessful.");
        }
    }

    private function reporterByEmail(string $email): User
    {
        $reporter = User::query()->where('email', $email)->where('role', 'REPORTER')->first();
        if ($reporter === null) {
            $reporter = User::factory()->create([
                'email' => $email, 'role' => 'REPORTER', 'password' => self::PASSWORD,
            ]);
        }
        $this->reporter = $reporter;
        return $reporter;
    }

    private function membershipFor(string $email): ?Membership
    {
        return Membership::query()->where('project_id', $this->requireProject()->id)
            ->where('user_id', $this->reporterByEmail($email)->id)->first();
    }

    private function requireProject(): Project
    {
        return $this->project ?? throw new RuntimeException('BDD project fixture is not set.');
    }
}
