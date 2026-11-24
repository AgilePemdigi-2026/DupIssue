<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_app_connections', function (Blueprint $table): void {
            $table->dateTime('last_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('github_app_connections', function (Blueprint $table): void {
            $table->dropColumn('last_synced_at');
        });
    }
};
