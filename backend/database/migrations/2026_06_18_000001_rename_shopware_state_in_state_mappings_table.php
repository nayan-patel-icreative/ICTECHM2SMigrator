<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('state_mappings', function (Blueprint $table) {
            $table->renameColumn('shopware_state', 'magento_state');
        });
    }

    public function down(): void
    {
        Schema::table('state_mappings', function (Blueprint $table) {
            $table->renameColumn('magento_state', 'shopware_state');
        });
    }
};
