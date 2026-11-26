<?php

namespace App\Http\Controllers;

/** Serveert de gebouwde React-app (public/app/index.html). Een controller i.p.v. een closure, zodat `route:cache` werkt. */
class FrontendController extends Controller
{
    public function __invoke()
    {
        $index = public_path('app/index.html');
        abort_unless(is_file($index), 503, 'De frontend is nog niet gebouwd. Voer "npm run build" uit in de map frontend.');

        return response()->file($index, ['Cache-Control' => 'no-cache']);
    }
}
