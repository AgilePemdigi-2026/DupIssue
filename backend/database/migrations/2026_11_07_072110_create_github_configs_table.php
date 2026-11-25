<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_configs', function (Blueprint $table): void {

            $table->id();

            // Setiap konfigurasi terhubung ke satu project
            $table->foreignId('project_id')
                ->constrained('projects')
                ->restrictOnDelete();

            // Satu konfigurasi GitHub per project
            $table->unique(
                'project_id',
                'github_configs_project_id_unique'
            );

            // Format repository: owner/repository
            $table->string('repo_full_name', 255);

            // Token GitHub terenkripsi (REQ-04.11)
            $table->text('pat_encrypted');

            // Waktu sinkronisasi GitHub terakhir
            $table->dateTime('last_synced_at')->nullable();

            // Timestamp konfigurasi
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_configs');
    }
};
