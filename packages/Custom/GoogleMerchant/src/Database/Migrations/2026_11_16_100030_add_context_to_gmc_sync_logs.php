<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gmc_sync_logs', function (Blueprint $table) {
            // Why the operation happened when it was not a product change, e.g. "orphan".
            $table->string('context', 20)->nullable()->after('operation');
        });
    }

    public function down(): void
    {
        Schema::table('gmc_sync_logs', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
