<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {

            $table->id();

            // Project tujuan impor
            $table->foreignId('project_id')
                ->constrained('projects')
                ->restrictOnDelete();

            // Maintainer yang melakukan impor
            $table->foreignId('actor_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Jenis sumber data
            $table->enum('source_type', [
                'CSV',
                'GITHUB'
            ]);

            // Nama file CSV; kosong untuk impor GitHub
            $table->string('file_name', 255)->nullable();

            // Statistik hasil impor
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);

            // Status proses impor
            $table->enum('status', [
                'PENDING',
                'PROCESSING',
                'COMPLETED',
                'FAILED'
            ])->default('PENDING');

            // PDM hanya menetapkan created_at
            $table->dateTime('created_at')->useCurrent();

            // Mempercepat pencarian riwayat impor per project
            $table->index(
                ['project_id', 'created_at'],
                'import_batches_project_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
