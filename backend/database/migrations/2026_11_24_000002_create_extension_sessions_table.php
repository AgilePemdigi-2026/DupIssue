<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'extension_sessions',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->unsignedBigInteger('auth_version');

                $table->string('pairing_hash', 64)
                    ->nullable()
                    ->unique();

                $table->dateTime('pairing_expires_at');
                $table->dateTime('paired_at')->nullable();

                $table->string('access_hash', 64)
                    ->nullable()
                    ->unique();

                $table->dateTime('access_expires_at')
                    ->nullable();

                $table->dateTime('revoked_at')->nullable();

                $table->timestamps();

                $table->index(['user_id', 'revoked_at']);
                $table->index('pairing_expires_at');
                $table->index('access_expires_at');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_sessions');
    }
};
