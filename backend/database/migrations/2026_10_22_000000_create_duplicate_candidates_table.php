<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->foreignId('candidate_issue_id')->constrained('issues')->cascadeOnDelete();
            $table->decimal('similarity_score', 5, 4);
            $table->timestamps();

            $table->unique(['issue_id', 'candidate_issue_id']);
            $table->index(['issue_id', 'similarity_score']);
        });

        // SQLite's schema builder has no CHECK helper and is used for tests.
        // MySQL gets a database-level guard in addition to model validation.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE duplicate_candidates ADD CONSTRAINT duplicate_candidates_similarity_score_check '
                .'CHECK (similarity_score >= 0 AND similarity_score <= 1)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_candidates');
    }
};
