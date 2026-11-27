<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ShopController;
use Illuminate\Support\Facades\Route;

/* ---------- Webwinkel (openbaar) ---------- */
Route::get('/catalog', [ShopController::class, 'catalog']);
Route::post('/checkout', [ShopController::class, 'checkout'])->middleware('throttle:20,1');
Route::get('/orders/confirm', [ShopController::class, 'confirm'])->middleware('throttle:30,1');
Route::post('/orders/track', [ShopController::class, 'track'])->middleware('throttle:10,1');
Route::post('/withdrawal', [ShopController::class, 'withdrawal'])->middleware('throttle:5,10');
Route::get('/newsletter/confirm', [ShopController::class, 'confirmNewsletter'])->middleware('throttle:30,1');
Route::post('/newsletter', [ShopController::class, 'newsletter'])->middleware('throttle:5,1');
// Factuur en creditnota voor de klant via een ondertekende link (uit de bevestigingsmail); niet te raden zonder APP_KEY.
Route::get('/orders/{order}/invoice', [DocumentController::class, 'invoice'])->middleware(['signed', 'throttle:30,1'])->name('orders.invoice');
Route::get('/orders/{order}/credit-note', [DocumentController::class, 'creditNote'])->middleware(['signed', 'throttle:30,1'])->name('orders.credit-note');
Route::post('/stripe/webhook', [ShopController::class, 'stripeWebhook'])->middleware('throttle:240,1');

/* ---------- Beheer ---------- */
Route::post('/admin/login', [AdminController::class, 'login'])->middleware('throttle:5,1');

Route::prefix('admin')->middleware(['auth:sanctum', 'abilities:admin'])->group(function () {
    Route::post('/uploads', [MediaController::class, 'upload'])->middleware('throttle:60,1');
    Route::post('/logout', [AdminController::class, 'logout']);
    Route::get('/status', [AdminController::class, 'status']);
    Route::get('/orders', [AdminController::class, 'orders']);
    Route::patch('/orders/{order}', [AdminController::class, 'updateOrder']);
    Route::get('/orders/{order}/invoice', [DocumentController::class, 'invoice']);
    Route::get('/orders/{order}/credit-note', [DocumentController::class, 'creditNote']);
    Route::post('/orders/{order}/{action}', [AdminController::class, 'orderAction'])->whereIn('action', ['refund', 'notify', 'cj-order', 'cj-check']);
    Route::post('/cj-sync', [AdminController::class, 'cjSync']);
    Route::get('/products', [AdminController::class, 'products']);
    Route::post('/products', [AdminController::class, 'storeProduct']);
    Route::put('/products/{product}', [AdminController::class, 'updateProduct']);
    Route::delete('/products/{product}', [AdminController::class, 'destroyProduct']);
    Route::get('/tax', [AdminController::class, 'tax']);
    Route::get('/settings/{key}', [AdminController::class, 'setting']);
    Route::put('/settings/{key}', [AdminController::class, 'saveSetting']);
    Route::get('/subscribers', [AdminController::class, 'subscribers']);
});
