<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DiscountMigrationController;
use App\Http\Controllers\Api\ManufacturerMigrationController;
use App\Http\Controllers\Api\MigrationController;
use App\Http\Controllers\Api\MigrationRunReportController;
use App\Http\Controllers\Api\CustomerMigrationController;
use App\Http\Controllers\Api\NewsletterMigrationController;
use App\Http\Controllers\Api\OrderMigrationController;
use App\Http\Controllers\Api\QueueHealthController;
use App\Http\Controllers\Api\StateMappingController;
use App\Http\Controllers\Api\MagentoConnectionController;
use App\Http\Controllers\Api\ShopifyController;
use App\Http\Controllers\Api\MarketMigrationController;

Route::middleware(['shopify.session_token'])->group(function () {
    Route::middleware(['throttle:api'])->group(function () {
        Route::get('/me', [ShopifyController::class, 'me']);
        Route::get('/shopify/locations', [ShopifyController::class, 'locations']);

        Route::get('/queue/health', [QueueHealthController::class, 'show']);

        Route::get('/shopware-connection', [MagentoConnectionController::class, 'show']);
        Route::post('/shopware-connection', [MagentoConnectionController::class, 'store']);
        Route::get('/shopware-languages', [MagentoConnectionController::class, 'languages']);
        Route::get('/shopware-sales-channels', [MagentoConnectionController::class, 'storeViews']);

        Route::get('/state-mappings', [StateMappingController::class, 'show']);
        Route::post('/state-mappings', [StateMappingController::class, 'store']);
    });

    Route::middleware(['throttle:migration'])->group(function () {
        Route::get('/migration/runs/{run}/report', [MigrationRunReportController::class, 'download']);

        Route::prefix('migration/manufacturers')->group(function () {
            Route::post('/preview', [ManufacturerMigrationController::class, 'preview']);
            Route::get('/status', [ManufacturerMigrationController::class, 'status']);
            Route::post('/start', [ManufacturerMigrationController::class, 'start']);
            Route::post('/cancel', [ManufacturerMigrationController::class, 'cancel']);
        });

        Route::prefix('migration/products')->group(function () {
            Route::post('/preview', [MigrationController::class, 'preview']);
            Route::post('/redirects/preview', [MigrationController::class, 'previewRedirects']);
            Route::post('/redirects/import', [MigrationController::class, 'importRedirects']);
            Route::post('/preview-filtered', [MigrationController::class, 'previewFiltered']);
            Route::get('/status', [MigrationController::class, 'status']);
            Route::post('/start', [MigrationController::class, 'start']);
            Route::post('/start-filtered', [MigrationController::class, 'startFiltered']);
            Route::post('/cancel', [MigrationController::class, 'cancel']);
        });

        Route::prefix('migration/collections')->group(function () {
            Route::post('/redirects/preview', [MigrationController::class, 'previewCollectionRedirects']);
            Route::post('/redirects/import', [MigrationController::class, 'importCollectionRedirects']);
        });

        Route::prefix('migration/customers')->group(function () {
            Route::get('/preview', [CustomerMigrationController::class, 'preview']);
            Route::post('/preview', [CustomerMigrationController::class, 'preview']);
            Route::post('/preview-filtered', [CustomerMigrationController::class, 'previewFiltered']);
            Route::get('/status', [CustomerMigrationController::class, 'status']);
            Route::post('/start', [CustomerMigrationController::class, 'start']);
            Route::post('/start-filtered', [CustomerMigrationController::class, 'startFiltered']);
            Route::post('/cancel', [CustomerMigrationController::class, 'cancel']);
        });

        Route::prefix('migration/orders')->group(function () {
            Route::get('/preview', [OrderMigrationController::class, 'preview']);
            Route::post('/preview', [OrderMigrationController::class, 'preview']);
            Route::post('/preview-filtered', [OrderMigrationController::class, 'previewFiltered']);
            Route::get('/status', [OrderMigrationController::class, 'status']);
            Route::post('/start', [OrderMigrationController::class, 'start']);
            Route::post('/start-filtered', [OrderMigrationController::class, 'startFiltered']);
            Route::post('/cancel', [OrderMigrationController::class, 'cancel']);
        });

        Route::prefix('migration/newsletter')->group(function () {
            Route::get('/preview', [NewsletterMigrationController::class, 'preview']);
            Route::post('/preview', [NewsletterMigrationController::class, 'preview']);
            Route::get('/status', [NewsletterMigrationController::class, 'status']);
            Route::post('/start', [NewsletterMigrationController::class, 'start']);
            Route::post('/cancel', [NewsletterMigrationController::class, 'cancel']);
        });

        Route::prefix('migration/discounts')->group(function () {
            Route::post('/preview', [DiscountMigrationController::class, 'preview']);
            Route::get('/status', [DiscountMigrationController::class, 'status']);
            Route::post('/start', [DiscountMigrationController::class, 'start']);
            Route::post('/cancel', [DiscountMigrationController::class, 'cancel']);
        });

        Route::prefix('migration/markets')->group(function () {
            Route::get('/preview', [MarketMigrationController::class, 'preview']);
            Route::post('/preview', [MarketMigrationController::class, 'preview']);
            Route::get('/status', [MarketMigrationController::class, 'status']);
            Route::post('/start', [MarketMigrationController::class, 'start']);
            Route::post('/cancel', [MarketMigrationController::class, 'cancel']);
        });
    });
});
