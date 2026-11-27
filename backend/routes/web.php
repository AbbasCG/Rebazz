<?php

use App\Http\Controllers\FrontendController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
| De React-app wordt gebouwd naar public/app (npm run build in /frontend).
| Alle niet-API-adressen tonen die app; React Router regelt de rest.
*/
Route::get('/sitemap.xml', SitemapController::class);
Route::get('/{any?}', FrontendController::class)->where('any', '^(?!api/|up$|storage/|sitemap\.xml$).*$');
