<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 24);
            $table->string('status', 24)->default('DRAFT');
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->date('occurred_on')->nullable();
            $table->string('origin', 32)->nullable();
            $table->string('service_order_number', 120)->nullable();
            $table->string('document_number', 120)->nullable();
            $table->text('description')->nullable();
            $table->text('observations')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('reverses_movement_id')->nullable()->unique()->constrained('inventory_movements')->restrictOnDelete();
            $table->string('source', 32)->default('MANUAL');
            $table->foreignId('import_batch_id')->nullable()->constrained('inventory_import_batches')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'occurred_on', 'id']);
            $table->index(['type', 'occurred_on']);
            $table->index('service_order_number');
            $table->index('document_number');
        });
        Schema::create('inventory_movement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('inventory_product_variants')->restrictOnDelete();
            $table->unsignedBigInteger('quantity')->nullable();
            $table->unsignedBigInteger('counted_quantity')->nullable();
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->json('snapshot');
            $table->timestamps();
            $table->unique(['movement_id', 'variant_id']);
        });
        Schema::create('inventory_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('movement_line_id')->unique()->constrained('inventory_movement_lines')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('inventory_product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->date('effective_on');
            $table->bigInteger('delta');
            $table->timestamp('posted_at');
            $table->index(['variant_id', 'location_id', 'effective_on', 'id'], 'inv_ledger_variant_location_date_idx');
        });
        Schema::create('inventory_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('inventory_product_variants')->restrictOnDelete();
            $table->bigInteger('quantity')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('updated_at')->nullable();
            $table->unique(['location_id', 'variant_id']);
        });
        Schema::table('inventory_import_rows', function (Blueprint $table): void {
            $table->foreign('movement_line_id')->references('id')->on('inventory_movement_lines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_import_rows', function (Blueprint $table): void {
            $table->dropForeign(['movement_line_id']);
        });
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('inventory_ledger_entries');
        Schema::dropIfExists('inventory_movement_lines');
        Schema::dropIfExists('inventory_movements');
    }
};
