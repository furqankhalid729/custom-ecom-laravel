<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gmc_products', function (Blueprint $table) {
            $table->json('field_hashes')->nullable()->after('payload_hash');
        });
    }

    public function down(): void
    {
        Schema::table('gmc_products', function (Blueprint $table) {
            $table->dropColumn('field_hashes');
        });
    }
};
