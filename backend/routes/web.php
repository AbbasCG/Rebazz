<?php

use App\Http\Controllers\FrontendController;
use Illuminate\Support\Facades\Route;

/*
| De React-app wordt gebouwd naar public/app (npm run build in /frontend).
| Alle niet-API-adressen tonen die app; React Router regelt de rest.
*/
Route::get('/{any?}', FrontendController::class)->where('any', '^(?!api/|up$|storage/).*$');
