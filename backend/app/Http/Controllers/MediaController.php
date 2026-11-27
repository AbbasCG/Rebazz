<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Productfoto's uploaden (beheer) en tonen (openbaar). Bewust zonder symlink naar public/: bestanden staan in
 * storage/app/public/products en worden via /media/products/<naam> geserveerd. Alleen JPG, PNG en WebP (geen SVG: kan scripts bevatten).
 */
class MediaController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']]); // max. 4 MB
        $file = $request->file('image');
        // Echt een afbeelding? getimagesize leest de bytes; een omgedoopt .php-bestand komt hier niet doorheen.
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] > 6000 || $info[1] > 6000) {
            return response()->json(['message' => 'Alleen JPG, PNG of WebP tot 6000 px.'], 422);
        }
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
        $name = Str::lower(Str::random(32)).'.'.$ext; // willekeurige naam: onraadbaar en de extensie komt uit de inhoud, niet uit de upload
        Storage::disk('public')->putFileAs('products', $file, $name);

        return response()->json(['url' => '/media/products/'.$name], 201);
    }

    public function show(string $file)
    {
        $path = 'products/'.$file;
        abort_unless(preg_match('/^[a-z0-9]{32}\.(jpg|png|webp)$/', $file) === 1 && Storage::disk('public')->exists($path), 404);

        return response()->file(Storage::disk('public')->path($path), [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
