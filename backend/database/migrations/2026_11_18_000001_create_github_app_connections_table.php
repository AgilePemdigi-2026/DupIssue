<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_app_connections', function (Blueprint $table): void {
            $table->id();

            // Satu koneksi GitHub App per project DupIssue.
            $table->foreignId('project_id')
                ->unique()
                ->constrained('projects')
                ->cascadeOnDelete();

            // Identifier GitHub, bukan access token.
            $table->unsignedBigInteger('installation_id');
            $table->unsignedBigInteger('repository_id');
            $table->string('repo_full_name', 255);

            // Identitas pengguna GitHub saat verifikasi OAuth.
            $table->unsignedBigInteger('verified_github_user_id');
            $table->timestamp('verified_at');

            $table->timestamps();

            $table->index(
                ['installation_id', 'repository_id'],
                'github_app_installation_repository_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_app_connections');
    }
};
