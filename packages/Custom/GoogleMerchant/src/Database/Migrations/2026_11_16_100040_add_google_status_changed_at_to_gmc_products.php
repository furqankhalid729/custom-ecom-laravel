<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gmc_products', function (Blueprint $table) {
            // When google_status last changed (google_checked_at moves on every refresh).
            $table->timestamp('google_status_changed_at')->nullable()->after('google_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('gmc_products', function (Blueprint $table) {
            $table->dropColumn('google_status_changed_at');
        });
    }
};
