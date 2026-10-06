<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 80);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->string('request_id', 128)->nullable();
            $table->index(['entity_type', 'entity_id', 'occurred_at']);
        });
        Schema::create('inventory_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 80);
            $table->string('resource_key', 160);
            $table->string('key', 160);
            $table->char('request_hash', 64);
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'operation', 'resource_key', 'key'], 'inventory_idempotency_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_idempotency_keys');
        Schema::dropIfExists('inventory_audit_events');
    }
};
