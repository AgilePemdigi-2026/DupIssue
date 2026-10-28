<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('title', 200);
            $table->text('description');
            $table->enum('source_type', ['FORM', 'CSV', 'GITHUB'])->default('FORM');
            $table->string('source_id', 100)->nullable();
            $table->string('github_repo', 255)->nullable();
            $table->integer('github_number')->nullable();
            $table->enum('status', ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED', 'DUPLICATE'])->default('OPEN');
            $table->enum('detection_status', ['PENDING', 'PROCESSING', 'COMPLETED', 'FAILED'])->default('PENDING');
            $table->unsignedBigInteger('duplicate_of')->nullable();
            $table->integer('vote_count')->default(0);
            $table->timestamps();

            $table->foreign('duplicate_of')->references('id')->on('issues')->nullOnDelete();
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'detection_status']);
        });

        if (!Schema::hasTable('votes')) {
            Schema::create('votes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
                $table->unsignedBigInteger('voter_id');
                $table->timestamp('created_at')->useCurrent();

                // REQ-01.15 & REQ-OR-02: Satu Reporter hanya boleh punya 1 vote aktif per issue
                $table->unique(['issue_id', 'voter_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('votes');
        Schema::dropIfExists('issues');
    }
};