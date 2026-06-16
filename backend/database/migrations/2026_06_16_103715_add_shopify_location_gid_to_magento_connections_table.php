<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('magento_connections', function (Blueprint $table) {
            $table->string('shopify_location_gid')->nullable()->after('files_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('magento_connections', function (Blueprint $table) {
            $table->dropColumn('shopify_location_gid');
        });
    }
};
