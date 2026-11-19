<?php

namespace Tests\Unit;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_mapping_for_verified_actions_in_main(): void
    {
        $logProject = new ActivityLog(['action' => 'CREATE_PROJECT']);
        $this->assertSame(ActivityLog::CATEGORY_PROJECT, $logProject->ui_category);

        $logUpdateProject = new ActivityLog(['action' => 'UPDATE_PROJECT']);
        $this->assertSame(ActivityLog::CATEGORY_PROJECT, $logUpdateProject->ui_category);

        $logInvite = new ActivityLog(['action' => 'INVITE_REPORTER']);
        $this->assertSame(ActivityLog::CATEGORY_MEMBERSHIP, $logInvite->ui_category);

        $logRemove = new ActivityLog(['action' => 'REMOVE_MEMBER']);
        $this->assertSame(ActivityLog::CATEGORY_MEMBERSHIP, $logRemove->ui_category);

        $logStatus = new ActivityLog(['action' => 'ISSUE_STATUS_CHANGED']);
        $this->assertSame(ActivityLog::CATEGORY_ISSUE_STATUS, $logStatus->ui_category);

        $logAutoClose = new ActivityLog(['action' => 'DUPLICATE_AUTO_CLOSED']);
        $this->assertSame(ActivityLog::CATEGORY_ISSUE_STATUS, $logAutoClose->ui_category);

        $logDupReview = new ActivityLog(['action' => 'DUPLICATE_REVIEW']);
        $this->assertSame(ActivityLog::CATEGORY_DUPLICATE_REVIEW, $logDupReview->ui_category);

        $logDupCorrected = new ActivityLog(['action' => 'DUPLICATE_CORRECTED']);
        $this->assertSame(ActivityLog::CATEGORY_DUPLICATE_REVIEW, $logDupCorrected->ui_category);

        $logVote = new ActivityLog(['action' => 'VOTE_ADDED']);
        $this->assertSame(ActivityLog::CATEGORY_OTHER, $logVote->ui_category);
    }

    public function test_category_mapping_for_gap_actions_and_unknown_actions(): void
    {
        $logCsv = new ActivityLog(['action' => 'IMPORT_CSV']);
        $this->assertSame(ActivityLog::CATEGORY_ISSUE_SOURCE, $logCsv->ui_category);

        $logGithub = new ActivityLog(['action' => 'IMPORT_GITHUB']);
        $this->assertSame(ActivityLog::CATEGORY_ISSUE_SOURCE, $logGithub->ui_category);

        $logUnknown = new ActivityLog(['action' => 'SOME_FUTURE_UNKNOWN_ACTION']);
        $this->assertSame(ActivityLog::CATEGORY_OTHER, $logUnknown->ui_category);
    }

    public function test_actor_name_fallback_to_sistem_when_user_is_null(): void
    {
        $logWithNullUser = new ActivityLog();
        $this->assertSame('Sistem', $logWithNullUser->actor_name);

        $user = User::factory()->make(['name' => 'John Doe']);
        $logWithUser = new ActivityLog();
        $logWithUser->setRelation('user', $user);
        $this->assertSame('John Doe', $logWithUser->actor_name);
    }

    public function test_sanitization_redacts_sensitive_keys_without_false_positives(): void
    {
        $payload = [
            'name' => 'Project Alpha',
            'status' => 'OPEN',
            'file_path' => '/var/logs/output.txt',
            'path' => 'api/v1/resource',
            'pattern' => 'regex-pattern',
            'token' => 'plain-token',
            'github_token' => 'ghp_secret123456',
            'api_key' => 'xyz-api-key',
            'password' => 'supersecret',
            'pat' => 'bearer-pat',
            'secret' => 'top-secret',
            'authorization' => 'Bearer 12345',
            'cookie' => 'session_cookie_val',
            'private_key' => '-----BEGIN RSA PRIVATE KEY-----',
            'nested' => [
                'safe_field' => 'visible',
                'token' => 'hidden-nested',
                'status' => 'IN_PROGRESS',
            ],
        ];

        $sanitized = ActivityLog::sanitizeValues($payload);

        // Sensitive keys must be redacted
        $this->assertSame('***REDACTED***', $sanitized['token']);
        $this->assertSame('***REDACTED***', $sanitized['github_token']);
        $this->assertSame('***REDACTED***', $sanitized['api_key']);
        $this->assertSame('***REDACTED***', $sanitized['password']);
        $this->assertSame('***REDACTED***', $sanitized['pat']);
        $this->assertSame('***REDACTED***', $sanitized['secret']);
        $this->assertSame('***REDACTED***', $sanitized['authorization']);
        $this->assertSame('***REDACTED***', $sanitized['cookie']);
        $this->assertSame('***REDACTED***', $sanitized['private_key']);
        $this->assertSame('***REDACTED***', $sanitized['nested']['token']);

        // Non-sensitive keys must NOT be false-positives
        $this->assertSame('Project Alpha', $sanitized['name']);
        $this->assertSame('OPEN', $sanitized['status']);
        $this->assertSame('/var/logs/output.txt', $sanitized['file_path']);
        $this->assertSame('api/v1/resource', $sanitized['path']);
        $this->assertSame('regex-pattern', $sanitized['pattern']);
        $this->assertSame('visible', $sanitized['nested']['safe_field']);
        $this->assertSame('IN_PROGRESS', $sanitized['nested']['status']);
    }

    public function test_scope_of_category_filters_properly(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        \DB::table('activity_logs')->insert([
            ['project_id' => $project->id, 'action' => 'CREATE_PROJECT', 'created_at' => now(), 'updated_at' => now()],
            ['project_id' => $project->id, 'action' => 'DUPLICATE_REVIEW', 'created_at' => now(), 'updated_at' => now()],
            ['project_id' => $project->id, 'action' => 'VOTE_ADDED', 'created_at' => now(), 'updated_at' => now()],
            ['project_id' => $project->id, 'action' => 'RANDOM_LEGACY_ACTION', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $projectLogs = ActivityLog::ofCategory(ActivityLog::CATEGORY_PROJECT)->get();
        $this->assertCount(1, $projectLogs);
        $this->assertSame('CREATE_PROJECT', $projectLogs->first()->action);

        $duplicateLogs = ActivityLog::ofCategory(ActivityLog::CATEGORY_DUPLICATE_REVIEW)->get();
        $this->assertCount(1, $duplicateLogs);
        $this->assertSame('DUPLICATE_REVIEW', $duplicateLogs->first()->action);

        $otherLogs = ActivityLog::ofCategory(ActivityLog::CATEGORY_OTHER)->get();
        $this->assertCount(2, $otherLogs); // VOTE_ADDED and RANDOM_LEGACY_ACTION
    }

    public function test_activity_log_is_read_only_and_cannot_be_updated_or_deleted(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $log = ActivityLog::create([
            'project_id' => $project->id,
            'action' => 'CREATE_PROJECT',
        ]);

        $this->expectException(\BadMethodCallException::class);
        $log->update(['action' => 'TAMPERED_ACTION']);
    }

    public function test_activity_log_cannot_be_deleted(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $log = ActivityLog::create([
            'project_id' => $project->id,
            'action' => 'CREATE_PROJECT',
        ]);

        $this->expectException(\BadMethodCallException::class);
        $log->delete();
    }
}
