<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('delivery_uuid')->unique();
            $table->char('body_hash', 64);
            $table->unsignedBigInteger('installation_id');
            $table->unsignedBigInteger('repository_id');
            $table->unsignedInteger('issue_number');
            $table->timestamp('received_at');
        });
        Schema::create('github_webhook_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained('github_webhook_deliveries')->cascadeOnDelete();
            // Snapshots survive connection replacement/deletion for safe rejection.
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('project_id');
            $table->string('repository');
            $table->string('status', 24)->default('RECEIVED')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('lease_until')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['delivery_id', 'connection_id'], 'github_delivery_connection_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_webhook_tasks');
        Schema::dropIfExists('github_webhook_deliveries');
    }
};
