<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\ShopController;
use Illuminate\Support\Facades\Route;

/* ---------- Webwinkel (openbaar) ---------- */
Route::get('/catalog', [ShopController::class, 'catalog']);
Route::post('/checkout', [ShopController::class, 'checkout'])->middleware('throttle:20,1,checkout');
Route::get('/orders/confirm', [ShopController::class, 'confirm'])->middleware('throttle:30,1,confirm');
Route::post('/orders/track', [ShopController::class, 'track'])->middleware('throttle:10,1,track');
Route::post('/returns', [ReturnController::class, 'store'])->middleware('throttle:10,10,returns');
Route::get('/returns/{return}/credit-note', [DocumentController::class, 'returnCreditNote'])->middleware(['signed', 'throttle:30,1,returncn'])->name('returns.credit-note');
// AVG: privacyverzoek (inzage/wissen) met verificatie via een tijdelijke link per e-mail; afmelden voor de nieuwsbrief.
Route::post('/privacy/request', [PrivacyController::class, 'request'])->middleware('throttle:10,60,privacy-request');
Route::get('/privacy/export', [PrivacyController::class, 'export'])->middleware(['signed', 'throttle:10,1,privacy-export'])->name('privacy.export');
Route::post('/privacy/erase', [PrivacyController::class, 'erase'])->middleware(['signed', 'throttle:10,1,privacy-erase'])->name('privacy.erase');
Route::get('/newsletter/unsubscribe', [PrivacyController::class, 'unsubscribe'])->middleware(['signed', 'throttle:30,1,nl-unsub'])->name('newsletter.unsubscribe');
// Klantaccount (zonder wachtwoord): inlogcode per e-mail; alles daarna onder /account met een token met het recht "customer".
Route::post('/account/login', [CustomerAuthController::class, 'requestCode'])->middleware('throttle:30,60,acct-login');
Route::post('/account/verify', [CustomerAuthController::class, 'verify'])->middleware('throttle:20,60,acct-verify');
Route::middleware(['auth:sanctum', 'abilities:customer', 'customer'])->prefix('account')->group(function () {
    Route::post('/logout', [CustomerAuthController::class, 'logout']);
    Route::get('/me', [AccountController::class, 'show']);
    Route::patch('/me', [AccountController::class, 'update']);
    Route::get('/orders', [AccountController::class, 'orders']);
    Route::get('/orders/{order}', [AccountController::class, 'order']);
    Route::post('/returns', [AccountController::class, 'createReturn'])->middleware('throttle:10,10,acct-returns');
    Route::get('/wishlist', [AccountController::class, 'wishlist']);
    Route::post('/wishlist/merge', [AccountController::class, 'wishlistMerge']);
    Route::post('/wishlist/{product}', [AccountController::class, 'wishlistAdd']);
    Route::delete('/wishlist/{product}', [AccountController::class, 'wishlistRemove']);
    Route::get('/export', [AccountController::class, 'export'])->middleware('throttle:10,60,acct-export');
    Route::post('/erase', [AccountController::class, 'erase'])->middleware('throttle:5,60,acct-erase');
});
Route::post('/withdrawal', [ShopController::class, 'withdrawal'])->middleware('throttle:5,10,withdrawal');
Route::get('/newsletter/confirm', [ShopController::class, 'confirmNewsletter'])->middleware('throttle:30,1,nl-confirm');
Route::post('/newsletter', [ShopController::class, 'newsletter'])->middleware('throttle:5,1,newsletter');
// Factuur en creditnota voor de klant via een ondertekende link (uit de bevestigingsmail); niet te raden zonder APP_KEY.
Route::get('/orders/{order}/invoice', [DocumentController::class, 'invoice'])->middleware(['signed', 'throttle:30,1,invoice'])->name('orders.invoice');
Route::get('/orders/{order}/credit-note', [DocumentController::class, 'creditNote'])->middleware(['signed', 'throttle:30,1,creditnote'])->name('orders.credit-note');
Route::post('/stripe/webhook', [ShopController::class, 'stripeWebhook'])->middleware('throttle:240,1,stripe-webhook');

/* ---------- Beheer ---------- */
Route::post('/admin/login', [AdminController::class, 'login'])->middleware('throttle:5,1,admin-login');

Route::prefix('admin')->middleware(['auth:sanctum', 'abilities:admin'])->group(function () {
    Route::post('/uploads', [MediaController::class, 'upload'])->middleware('throttle:60,1,uploads');
    Route::post('/logout', [AdminController::class, 'logout']);
    Route::get('/status', [AdminController::class, 'status']);
    Route::get('/orders', [AdminController::class, 'orders']);
    Route::patch('/orders/{order}', [AdminController::class, 'updateOrder']);
    Route::get('/orders/{order}/invoice', [DocumentController::class, 'invoice']);
    Route::get('/orders/{order}/credit-note', [DocumentController::class, 'creditNote']);
    Route::get('/privacy/lookup', [PrivacyController::class, 'lookup']);
    Route::get('/privacy/export', [PrivacyController::class, 'adminExport']);
    Route::post('/privacy/erase', [PrivacyController::class, 'adminErase']);
    Route::get('/privacy/requests', [PrivacyController::class, 'log']);
    Route::get('/returns', [ReturnController::class, 'index']);
    Route::get('/returns/{return}/credit-note', [DocumentController::class, 'returnCreditNote']);
    Route::post('/returns/{return}/{action}', [ReturnController::class, 'action'])->whereIn('action', ['approve', 'reject', 'receive', 'refund']);
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
