<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_policy_generations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('cms_page_id')->unsigned();
            $table->string('locale');
            $table->string('template_key');
            $table->char('generated_html_hash', 40);
            $table->char('settings_hash', 40);
            $table->longText('previous_html')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->integer('generated_by')->unsigned()->nullable();

            $table->unique(['cms_page_id', 'locale']);
            $table->foreign('cms_page_id')->references('id')->on('cms_pages')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_policy_generations');
    }
};
