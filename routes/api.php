<?php

use Acme\Inventory\Http\Controllers\CatalogController;
use Acme\Inventory\Http\Controllers\InventoryCorrectionController;
use Acme\Inventory\Http\Controllers\InventoryImportController;
use Acme\Inventory\Http\Controllers\InventoryReportsController;
use Acme\Inventory\Http\Controllers\MovementController;
use Acme\Inventory\Http\Controllers\ReplenishmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/categories', [CatalogController::class, 'categories']);
    Route::post('/categories', [CatalogController::class, 'createCategory']);
    Route::patch('/categories/{category}', [CatalogController::class, 'updateCategory']);
    Route::get('/items', [CatalogController::class, 'items']);
    Route::post('/items', [CatalogController::class, 'createItem']);
    Route::get('/items/{item}', [CatalogController::class, 'showItem']);
    Route::patch('/items/{item}', [CatalogController::class, 'updateItem']);
    Route::get('/items/{item}/replenishment', [ReplenishmentController::class, 'show']);
    Route::patch('/items/{item}/replenishment', [ReplenishmentController::class, 'configure']);
    Route::get('/dashboard', [InventoryReportsController::class, 'dashboard']);
    Route::get('/reports/{type}', [InventoryReportsController::class, 'report']);
    Route::get('/exports/{type}', [InventoryReportsController::class, 'export']);
    Route::post('/items/{item}/variants', [CatalogController::class, 'createVariant']);
    Route::patch('/variants/{variant}', [CatalogController::class, 'updateVariant']);
    Route::get('/movements', [MovementController::class, 'index']);
    Route::post('/movements', [MovementController::class, 'create']);
    Route::get('/movements/{movement}', [MovementController::class, 'show']);
    Route::patch('/movements/{movement}', [MovementController::class, 'update']);
    Route::post('/movements/{movement}/cancel', [MovementController::class, 'cancel']);
    Route::post('/movements/{movement}/post', [MovementController::class, 'post']);
    Route::post('/adjustments', [InventoryCorrectionController::class, 'adjust']);
    Route::post('/movements/{movement}/reverse', [InventoryCorrectionController::class, 'reverse']);
    Route::get('/imports', [InventoryImportController::class, 'index']);
    Route::post('/imports/analyze', [InventoryImportController::class, 'analyze']);
    Route::get('/imports/{batch}', [InventoryImportController::class, 'show']);
    Route::patch('/imports/{batch}/resolutions', [InventoryImportController::class, 'resolve']);
    Route::post('/imports/{batch}/commit', [InventoryImportController::class, 'commit']);
});
