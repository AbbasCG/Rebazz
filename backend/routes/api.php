<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminAccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ProductExchangeController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\TeamController;
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
Route::match(['get', 'post'], '/newsletter/unsubscribe', [PrivacyController::class, 'unsubscribe'])->middleware(['signed', 'throttle:30,1,nl-unsub'])->name('newsletter.unsubscribe');
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
// Statistieken: alleen gebeurtenissen van bezoekers die toestemming gaven (de winkel stuurt anders niets); ruim begrensd per IP.
Route::post('/analytics', [AnalyticsController::class, 'collect'])->middleware('throttle:240,1,analytics');
Route::post('/analytics/forget', [AnalyticsController::class, 'forget'])->middleware('throttle:20,1,analytics-forget');
Route::post('/withdrawal', [ShopController::class, 'withdrawal'])->middleware('throttle:5,10,withdrawal');
Route::get('/newsletter/confirm', [ShopController::class, 'confirmNewsletter'])->middleware('throttle:30,1,nl-confirm');
Route::post('/newsletter', [ShopController::class, 'newsletter'])->middleware('throttle:5,1,newsletter');
// Factuur en creditnota voor de klant via een ondertekende link (uit de bevestigingsmail); niet te raden zonder APP_KEY.
Route::get('/orders/{order}/invoice', [DocumentController::class, 'invoice'])->middleware(['signed', 'throttle:30,1,invoice'])->name('orders.invoice');
Route::get('/orders/{order}/credit-note', [DocumentController::class, 'creditNote'])->middleware(['signed', 'throttle:30,1,creditnote'])->name('orders.credit-note');
Route::post('/stripe/webhook', [ShopController::class, 'stripeWebhook'])->middleware('throttle:240,1,stripe-webhook');

/* ---------- Beheer ---------- */
Route::post('/admin/login', [AdminController::class, 'login'])->middleware('throttle:5,1,admin-login');

// Elke route hieronder vereist een actief personeelsaccount met het juiste werkgebied (zie User::ROLES) en, met SHOP_REQUIRE_2FA=on, 2FA.
// Wijzigende acties komen in het activiteitenlogboek (audit).
Route::prefix('admin')->middleware(['auth:sanctum', 'abilities:admin', 'audit'])->group(function () {
    // Eigen account: werkt ook vóór 2FA is ingesteld (anders zou verplichte 2FA zichzelf blokkeren).
    Route::middleware('staff.self')->group(function () {
        Route::post('/logout', [AdminController::class, 'logout']);
        Route::get('/me', [AdminAccountController::class, 'me']);
        Route::put('/me/password', [AdminAccountController::class, 'changePassword'])->middleware('throttle:10,10,admin-pw');
        Route::post('/me/2fa/setup', [AdminAccountController::class, 'setup'])->middleware('throttle:10,10,admin-2fa-setup');
        Route::post('/me/2fa/enable', [AdminAccountController::class, 'enable'])->middleware('throttle:10,10,admin-2fa-enable');
        Route::post('/me/2fa/disable', [AdminAccountController::class, 'disable'])->middleware('throttle:10,10,admin-2fa-disable');
        Route::post('/me/2fa/recovery', [AdminAccountController::class, 'recovery'])->middleware('throttle:10,10,admin-2fa-recovery');
    });

    Route::middleware('staff:any')->group(function () {
        Route::get('/status', [AdminController::class, 'status']);
        Route::get('/ai/status', [AiController::class, 'status']);
        Route::get('/settings/{key}', [AdminController::class, 'setting']);
        Route::put('/settings/{key}', [AdminController::class, 'saveSetting']); // per sleutel gecontroleerd in de controller
    });

    Route::middleware('staff:team')->group(function () {
        Route::get('/team', [TeamController::class, 'index']);
        Route::post('/team', [TeamController::class, 'store'])->middleware('throttle:20,10,admin-team');
        Route::get('/team/audit', [TeamController::class, 'audit']);
        Route::put('/team/{user}', [TeamController::class, 'update']);
        Route::delete('/team/{user}', [TeamController::class, 'destroy']);
        Route::post('/team/{user}/reset-2fa', [TeamController::class, 'resetTwoFactor']);
    });

    Route::middleware('staff:orders')->group(function () {
        Route::get('/orders', [AdminController::class, 'orders']);
        Route::patch('/orders/{order}', [AdminController::class, 'updateOrder']);
        Route::get('/orders/{order}/invoice', [DocumentController::class, 'invoice']);
        Route::get('/orders/{order}/credit-note', [DocumentController::class, 'creditNote']);
        Route::post('/orders/{order}/{action}', [AdminController::class, 'orderAction'])->whereIn('action', ['notify', 'cj-order', 'cj-check']);
        Route::post('/cj-sync', [AdminController::class, 'cjSync']);
    });
    Route::middleware('staff:refunds')->group(function () {
        Route::post('/orders/{order}/refund', [AdminController::class, 'orderAction'])->defaults('action', 'refund');
        Route::post('/returns/{return}/refund', [ReturnController::class, 'action'])->defaults('action', 'refund');
    });
    Route::middleware('staff:returns')->group(function () {
        Route::get('/returns', [ReturnController::class, 'index']);
        Route::get('/returns/{return}/credit-note', [DocumentController::class, 'returnCreditNote']);
        Route::post('/returns/{return}/{action}', [ReturnController::class, 'action'])->whereIn('action', ['approve', 'reject', 'receive']);
    });
    Route::middleware('staff:privacy')->group(function () {
        Route::get('/privacy/lookup', [PrivacyController::class, 'lookup']);
        Route::get('/privacy/export', [PrivacyController::class, 'adminExport']);
        Route::post('/privacy/erase', [PrivacyController::class, 'adminErase']);
        Route::get('/privacy/requests', [PrivacyController::class, 'log']);
    });
    Route::middleware('staff:products')->group(function () {
        Route::post('/uploads', [MediaController::class, 'upload'])->middleware('throttle:60,1,uploads');
        Route::get('/products', [AdminController::class, 'products']);
        Route::get('/products/export', [ProductExchangeController::class, 'export']);
        Route::post('/products/import', [ProductExchangeController::class, 'import'])->middleware('throttle:20,10,products-import');
        Route::get('/products/feed', [ProductExchangeController::class, 'feedCheck']);
        Route::post('/products', [AdminController::class, 'storeProduct']);
        Route::put('/products/{product}', [AdminController::class, 'updateProduct']);
        Route::delete('/products/{product}', [AdminController::class, 'destroyProduct']);
    });
    // AI-hulp (Claude): per werkgebied, ruim begrensd per IP en per medewerker (zie AI_DAILY_LIMIT).
    Route::middleware('staff:products')->post('/ai/description', [AiController::class, 'description'])->middleware('throttle:20,1,ai-description');
    Route::middleware('staff:marketing')->group(function () {
        Route::post('/ai/ad-copy', [AiController::class, 'adCopy'])->middleware('throttle:20,1,ai-ad');
        Route::post('/ai/video-script', [AiController::class, 'videoScript'])->middleware('throttle:20,1,ai-video');
    });
    Route::middleware('staff:orders')->post('/ai/support-reply', [AiController::class, 'supportReply'])->middleware('throttle:20,1,ai-support');
    Route::middleware('staff:tax')->get('/tax', [AdminController::class, 'tax']);
    Route::middleware('staff:marketing')->group(function () {
        Route::get('/subscribers', [AdminController::class, 'subscribers']);
        Route::get('/analytics', [AnalyticsController::class, 'report']);
        Route::get('/newsletters', [NewsletterController::class, 'index']);
        Route::post('/newsletters', [NewsletterController::class, 'store']);
        Route::put('/newsletters/{campaign}', [NewsletterController::class, 'update']);
        Route::delete('/newsletters/{campaign}', [NewsletterController::class, 'destroy']);
        Route::post('/newsletters/{campaign}/test', [NewsletterController::class, 'test'])->middleware('throttle:10,1,nl-test');
        Route::post('/newsletters/{campaign}/send', [NewsletterController::class, 'send']);
    });
});
