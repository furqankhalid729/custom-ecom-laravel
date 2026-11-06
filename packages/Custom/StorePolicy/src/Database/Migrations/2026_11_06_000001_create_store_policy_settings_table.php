<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_policy_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('channel_id')->unsigned()->unique();
            $table->json('business')->nullable();
            $table->json('payment')->nullable();
            $table->json('privacy')->nullable();
            $table->json('terms')->nullable();
            $table->json('regions')->nullable();
            $table->timestamps();

            $table->foreign('channel_id')->references('id')->on('channels')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_policy_settings');
    }
};
