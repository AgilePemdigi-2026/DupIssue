
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_votes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('issue_id')
                ->constrained('issues')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->unique(
                ['issue_id', 'user_id'],
                'issue_votes_issue_user_unique'
            );

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_votes');
    }
};
