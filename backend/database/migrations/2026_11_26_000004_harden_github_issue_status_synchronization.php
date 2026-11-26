<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_app_connections', function (Blueprint $table): void {
            $table->unsignedBigInteger('status_sync_generation')->default(1);
        });

        Schema::table('github_webhook_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('connection_generation')->default(1);
        });

        Schema::table('github_issue_status_outbox', function (Blueprint $table): void {
            $table->unsignedBigInteger('connection_generation')->default(1);
            $table->timestamp('queued_at')->nullable();
        });

        Schema::create('github_issue_sync_states', function (Blueprint $table): void {
            $table->unsignedBigInteger('issue_id')->primary();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('connection_generation');
            $table->unsignedBigInteger('repository_id');
            $table->string('remote_state', 8);
            $table->timestamp('confirmed_at');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_issue_sync_states');

        Schema::table('github_issue_status_outbox', function (Blueprint $table): void {
            $table->dropColumn(['connection_generation', 'queued_at']);
        });

        Schema::table('github_webhook_tasks', function (Blueprint $table): void {
            $table->dropColumn('connection_generation');
        });

        Schema::table('github_app_connections', function (Blueprint $table): void {
            $table->dropColumn('status_sync_generation');
        });
    }
};
