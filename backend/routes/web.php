<?php

use Illuminate\Support\Facades\Route;

/*
| De React-app wordt gebouwd naar public/app (npm run build in /frontend).
| Alle niet-API-adressen tonen die app; React Router regelt de rest.
*/
Route::get('/{any?}', function () {
    $index = public_path('app/index.html');
    abort_unless(is_file($index), 503, 'De frontend is nog niet gebouwd. Voer "npm run build" uit in de map frontend.');

    return response()->file($index, ['Cache-Control' => 'no-cache']);
})->where('any', '^(?!api/|up$|storage/).*$');
