
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            // Menyesuaikan panjang nama dengan PDM DupIssue.
            $table->string('name', 100)->change();

            // Menambahkan role dengan nilai awal REPORTER.
            $table->enum('role', ['MAINTAINER', 'REPORTER'])
                ->default('REPORTER')
                ->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {

            // Membatalkan perubahan jika migration di-rollback.
            $table->dropColumn('role');
            $table->string('name', 255)->change();
        });
    }
};
