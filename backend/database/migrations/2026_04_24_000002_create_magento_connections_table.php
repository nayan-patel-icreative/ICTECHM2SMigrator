<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magento_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('api_url');
            $table->text('access_token')->nullable();
            $table->string('store_view_code')->nullable();
            $table->string('store_view_name')->nullable();
            $table->json('language_config')->nullable();
            $table->string('files_path')->nullable();
            $table->timestamps();
            $table->unique('shop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magento_connections');
    }
};
