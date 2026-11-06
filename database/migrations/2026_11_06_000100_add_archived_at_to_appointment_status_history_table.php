<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('appointment_status_history', 'archived_at')) {
            Schema::table('appointment_status_history', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable()->after('note')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('appointment_status_history', 'archived_at')) {
            Schema::table('appointment_status_history', function (Blueprint $table) {
                $table->dropIndex(['archived_at']);
                $table->dropColumn('archived_at');
            });
        }
    }
};
