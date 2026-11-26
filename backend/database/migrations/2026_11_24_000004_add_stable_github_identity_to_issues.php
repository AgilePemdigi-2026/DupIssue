<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->unsignedBigInteger('github_repository_id')->nullable();
            $table->unique(['project_id', 'github_repository_id', 'github_number'], 'issues_stable_github_unique');
        });
        // Only backfill identity proven by the currently verified connection.
        // Historical repositories without a connection retain their metadata.
        DB::table('github_app_connections')->orderBy('id')->chunkById(100, function ($connections): void {
            foreach ($connections as $connection) {
                DB::table('issues')->where('project_id', $connection->project_id)->where('source_type', 'GITHUB')
                    ->whereRaw('LOWER(github_repo) = ?', [strtolower($connection->repo_full_name)])
                    ->update(['github_repository_id' => $connection->repository_id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->dropUnique('issues_stable_github_unique');
            $table->dropColumn('github_repository_id');
        });
    }
};
