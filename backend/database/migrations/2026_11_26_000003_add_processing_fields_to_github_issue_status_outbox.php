<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'github_issue_status_outbox',
            function (Blueprint $table): void {
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('available_at')->nullable();
                $table->timestamp('lease_until')->nullable();
                $table->string('claim_token', 36)->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('last_error_code', 64)->nullable();

                $table->index(
                    ['status', 'available_at', 'id'],
                    'github_status_outbox_recovery_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'github_issue_status_outbox',
            function (Blueprint $table): void {
                $table->dropIndex('github_status_outbox_recovery_index');
                $table->dropColumn([
                    'attempts', 'available_at', 'lease_until',
                    'claim_token', 'completed_at', 'last_error_code',
                ]);
            }
        );
    }
};
