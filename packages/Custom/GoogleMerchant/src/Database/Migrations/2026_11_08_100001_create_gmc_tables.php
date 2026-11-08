<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmc_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('merchant_id', 30)->nullable();
            $table->string('display_name')->nullable();
            $table->string('auth_type', 20);
            $table->string('google_email')->nullable();
            $table->string('developer_email')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->longText('service_account_json')->nullable();
            $table->string('status', 20)->default('disconnected');
            $table->text('last_error')->nullable();
            $table->timestamp('developer_registered_at')->nullable();
            $table->unsignedInteger('connected_by')->nullable();
            $table->timestamps();

            $table->foreign('connected_by')->references('id')->on('admins')->nullOnDelete();
        });

        Schema::create('gmc_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gmc_account_id')->constrained('gmc_accounts')->cascadeOnDelete();
            $table->unsignedInteger('channel_id');
            $table->string('locale', 10);
            $table->string('content_language', 10);
            $table->string('feed_label', 20);
            $table->json('countries');
            $table->string('currency', 3);
            $table->string('data_source_name')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['gmc_account_id', 'content_language', 'feed_label']);
            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
        });

        Schema::create('gmc_attribute_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gmc_target_id')->nullable()->constrained('gmc_targets')->cascadeOnDelete();
            $table->string('google_field', 60);
            $table->string('source_type', 20);
            $table->string('source_value')->nullable();
            $table->timestamps();

            $table->unique(['gmc_target_id', 'google_field']);
        });

        Schema::create('gmc_category_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('category_id')->unique();
            $table->string('google_product_category')->nullable();
            $table->boolean('excluded')->default(false);
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
        });

        Schema::create('gmc_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id')->nullable()->index();
            $table->unsignedInteger('root_product_id')->nullable();
            $table->foreignId('gmc_target_id')->constrained('gmc_targets')->cascadeOnDelete();
            $table->unsignedInteger('channel_id')->nullable();
            $table->string('offer_id');
            $table->text('product_input_name')->nullable();
            $table->string('state', 20)->default('pending');
            $table->char('payload_hash', 40)->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('synced_revision')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('error_kind', 20)->nullable();
            $table->unsignedTinyInteger('retry_cycles')->default(0);
            $table->json('validation_errors')->nullable();
            $table->string('google_status', 20)->nullable();
            $table->json('google_issues')->nullable();
            $table->timestamp('google_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['gmc_target_id', 'offer_id']);
            $table->index(['gmc_target_id', 'state']);
            $table->index(['root_product_id', 'gmc_target_id']);
            $table->index(['gmc_target_id', 'revision', 'synced_revision']);
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        Schema::create('gmc_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('status', 20)->default('running');
            $table->foreignId('gmc_target_id')->nullable()->constrained('gmc_targets')->cascadeOnDelete();
            $table->json('options')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('cursor')->default(0);
            $table->unsignedInteger('started_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });

        Schema::create('gmc_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gmc_product_id')->nullable()->constrained('gmc_products')->nullOnDelete();
            $table->unsignedInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('gmc_target_id')->nullable();
            $table->string('offer_id')->nullable();
            $table->string('operation', 20);
            $table->string('status', 20);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedBigInteger('gmc_run_id')->nullable()->index();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        foreach (['gmc_sync_logs', 'gmc_runs', 'gmc_products', 'gmc_category_mappings', 'gmc_attribute_mappings', 'gmc_targets', 'gmc_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
