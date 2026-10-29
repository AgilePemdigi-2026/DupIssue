<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jalankan setelah migration `projects` Reza dan `users` Joshua.
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 200);
            $table->longText('description');
            $table->enum('source_type', ['FORM', 'CSV', 'GITHUB'])->default('FORM');
            $table->enum('status', ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED', 'DUPLICATE'])->default('OPEN');
            $table->enum('detection_status', ['PENDING', 'PROCESSING', 'COMPLETED', 'FAILED'])->default('PENDING');
            $table->unsignedBigInteger('vote_count')->default(0);
            $table->foreignId('duplicate_of')->nullable()->constrained('issues')->nullOnDelete();

            // Metadata impor belum ditambahkan: nama kolom/index harus diverifikasi dari PDM.

            // Pertahankan tipe waktu kode awal; kesesuaian final perlu verifikasi SRS/PDM.
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'detection_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
