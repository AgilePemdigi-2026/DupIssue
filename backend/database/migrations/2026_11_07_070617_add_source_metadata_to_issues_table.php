<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table): void {

            // Identitas issue dari dataset CSV
            $table->string('source_id', 100)->nullable();

            // Identitas issue dari repository GitHub
            $table->string('github_repo', 255)->nullable();
            $table->unsignedInteger('github_number')->nullable();

            // URL sumber issue GitHub (REQ-04.14)
            $table->text('source_url')->nullable();

            // Mencegah impor CSV yang sama dalam satu project
            $table->unique(
                ['project_id', 'source_type', 'source_id'],
                'issues_project_source_id_unique'
            );

            // Mencegah impor GitHub issue yang sama dalam satu project
            $table->unique(
                [
                    'project_id',
                    'source_type',
                    'github_repo',
                    'github_number'
                ],
                'issues_project_github_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table): void {

            $table->dropUnique('issues_project_source_id_unique');
            $table->dropUnique('issues_project_github_unique');

            $table->dropColumn([
                'source_id',
                'github_repo',
                'github_number',
                'source_url'
            ]);
        });
    }
};
