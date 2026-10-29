<?php

namespace Tests\Unit;

use App\Models\Issue;
use App\Models\User;
use Tests\TestCase;

class IssueTest extends TestCase
{
    public function test_worker_can_fill_detection_status_without_unlocking_other_system_fields(): void
    {
        $issue = new Issue;
        $issue->fill([
            'title' => 'Valid title', 'description' => 'Valid description',
            'detection_status' => 'PROCESSING', 'project_id' => 999, 'author_id' => 999,
            'status' => 'DUPLICATE', 'source_type' => 'GITHUB', 'vote_count' => 999,
            'duplicate_of' => 999,
        ]);

        $this->assertSame('PROCESSING', $issue->detection_status);
        foreach (['project_id', 'author_id', 'status', 'source_type', 'vote_count', 'duplicate_of'] as $field) {
            $this->assertArrayNotHasKey($field, $issue->getAttributes());
        }
        $issue->fill(['detection_status' => 'COMPLETED']);
        $this->assertSame('COMPLETED', $issue->detection_status);
    }

    public function test_author_and_duplicate_relationships_use_the_schema_keys(): void
    {
        $issue = new Issue;
        $this->assertInstanceOf(User::class, $issue->author()->getRelated());
        $this->assertSame('author_id', $issue->author()->getForeignKeyName());
        $this->assertSame('duplicate_of', $issue->parentIssue()->getForeignKeyName());
        $this->assertSame('duplicate_of', $issue->childDuplicates()->getForeignKeyName());
    }
}
