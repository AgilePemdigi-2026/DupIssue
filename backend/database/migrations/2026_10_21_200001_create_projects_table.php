<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            // projects.name: UNIQUE global, case-insensitive (utf8mb4_unicode_ci di MySQL, NOCASE di SQLite)
            $nameColumn = $table->string('name', 100)->unique();
            if (DB::getDriverName() === 'mysql') {
                $nameColumn->collation('utf8mb4_unicode_ci');
            } else {
                $nameColumn->collation('NOCASE');
            }

            $table->string('description', 1000)->nullable();

            // Kolom waktu menggunakan dateTime() sesuai PDM (bukan timestamps())
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
