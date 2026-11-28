<?php

use App\Http\Controllers\FeedController;
use App\Http\Controllers\FrontendController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
| De React-app wordt gebouwd naar public/app (npm run build in /frontend).
| Alle niet-API-adressen tonen die app; React Router regelt de rest.
*/
Route::get('/sitemap.xml', SitemapController::class);
Route::get('/feeds/google.xml', [FeedController::class, 'google'])->middleware('throttle:60,1,feed');
Route::get('/media/products/{file}', [MediaController::class, 'show'])->where('file', '[a-z0-9.]+');
Route::get('/{any?}', FrontendController::class)->where('any', '^(?!api/|up$|storage/|media/|feeds/|sitemap\.xml$).*$');
