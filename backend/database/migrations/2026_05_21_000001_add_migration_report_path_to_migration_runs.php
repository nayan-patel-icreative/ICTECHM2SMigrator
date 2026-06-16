<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_runs', function (Blueprint $table) {
            $table->string('report_path')->nullable()->after('shopify_location_gid');
        });
    }

    public function down(): void
    {
        Schema::table('migration_runs', function (Blueprint $table) {
            $table->dropColumn('report_path');
        });
    }
};

