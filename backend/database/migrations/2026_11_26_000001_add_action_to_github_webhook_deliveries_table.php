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
            'github_webhook_deliveries',
            function (Blueprint $table): void {
                // Historical deliveries were exclusively issues.opened.
                $table->string('action', 24)->default('opened');
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'github_webhook_deliveries',
            function (Blueprint $table): void {
                $table->dropColumn('action');
            }
        );
    }
};
