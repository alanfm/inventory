<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('normalized_name', 120)->unique();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('category_id')->constrained('inventory_categories')->restrictOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('unit', 3);
            $table->unsignedBigInteger('minimum_stock')->nullable();
            $table->unsignedInteger('purchase_lead_time_days')->nullable();
            $table->unsignedBigInteger('safety_stock')->nullable();
            $table->unsignedSmallInteger('recommendation_window_days')->default(180);
            $table->date('history_coverage_from')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['category_id', 'active']);
        });
        Schema::create('inventory_product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('brand', 120)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('description', 2000);
            $table->char('identity_hash', 64);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['item_id', 'identity_hash']);
            $table->index(['item_id', 'active']);
        });
        Schema::create('inventory_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('inventory_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->char('file_hash', 64)->unique();
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('adapter_version', 32);
            $table->string('status', 32);
            $table->unsignedInteger('analysis_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('counts')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('inventory_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('inventory_import_batches')->restrictOnDelete();
            $table->string('sheet_name', 120);
            $table->unsignedInteger('row_number');
            $table->json('source_payload');
            $table->json('corrected_payload')->nullable();
            $table->string('resolution', 32)->nullable();
            $table->json('errors')->nullable();
            $table->unsignedBigInteger('movement_line_id')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'sheet_name', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_import_rows');
        Schema::dropIfExists('inventory_import_batches');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('inventory_product_variants');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventory_categories');
    }
};
