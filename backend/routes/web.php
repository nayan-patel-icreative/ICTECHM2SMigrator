<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\ShopifyAuthController;
use App\Http\Controllers\Webhooks\ComplianceWebhookController;

Route::get('/', function () {
    return redirect()->to('/app'.(request()->getQueryString() ? ('?'.request()->getQueryString()) : ''));
});

Route::get('/auth/shopify', [ShopifyAuthController::class, 'redirectToShopify']);
Route::get('/auth/shopify/callback', [ShopifyAuthController::class, 'handleCallback']);

Route::get('/app', [ShopifyAuthController::class, 'app']);

Route::post('/webhooks/compliance', [ComplianceWebhookController::class, 'handle']);
