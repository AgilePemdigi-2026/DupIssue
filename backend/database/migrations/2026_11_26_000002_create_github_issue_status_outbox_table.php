<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'github_issue_status_outbox',
            function (Blueprint $table): void {
                $table->id();

                // Retain immutable identity snapshots for safe recovery.
                // A future worker must revalidate every current identity.
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('issue_id');
                $table->unsignedBigInteger('connection_id');
                $table->unsignedBigInteger('installation_id');
                $table->unsignedBigInteger('repository_id');

                $table->string('repo_full_name', 255);
                $table->unsignedInteger('issue_number');
                $table->unsignedBigInteger('requested_by');

                $table->string('desired_state', 8);
                $table->unsignedBigInteger('revision');
                $table->string('status', 24)->default('PENDING');

                $table->timestamps();

                $table->unique(
                    ['issue_id', 'revision'],
                    'github_status_outbox_issue_revision_unique'
                );

                $table->index(
                    ['status', 'id'],
                    'github_status_outbox_processing_index'
                );

                $table->index(
                    ['project_id', 'repository_id'],
                    'github_status_outbox_project_repo_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('github_issue_status_outbox');
    }
};
