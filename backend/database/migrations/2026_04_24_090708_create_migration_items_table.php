<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_run_id')->constrained('migration_runs')->cascadeOnDelete();
            $table->string('entity_type');
            $table->string('source_id');
            $table->string('status');
            $table->string('fingerprint', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('error_context')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['migration_run_id', 'entity_type', 'source_id']);
            $table->index(['migration_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_items');
    }
};
