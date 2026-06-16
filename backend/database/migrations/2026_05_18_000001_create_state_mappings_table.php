<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('state_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('state_type', 64); // 'order_state' | 'transaction_state' | 'delivery_state'
            $table->string('shopware_state', 128);
            $table->string('shopify_status', 64);
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
            $table->unique(['shop_id', 'state_type', 'shopware_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('state_mappings');
    }
};
