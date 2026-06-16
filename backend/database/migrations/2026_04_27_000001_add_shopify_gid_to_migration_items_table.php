<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_items', function (Blueprint $table) {
            if (!Schema::hasColumn('migration_items', 'shopify_gid')) {
                $table->string('shopify_gid')->nullable()->after('source_id');
                $table->index(['migration_run_id', 'entity_type', 'shopify_gid'], 'migration_items_run_entity_gid_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('migration_items', function (Blueprint $table) {
            if (Schema::hasColumn('migration_items', 'shopify_gid')) {
                $table->dropIndex('migration_items_run_entity_gid_idx');
                $table->dropColumn('shopify_gid');
            }
        });
    }
};
