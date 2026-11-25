<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Neemt de bestaande producten en instellingen over uit de vorige versie van de site
 * (database/seeders/data/catalog.json, geëxporteerd uit js/catalog.js).
 * Bestaande producten worden niet overschreven.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $file = database_path('seeders/data/catalog.json');
        $catalog = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        foreach ($catalog['products'] as $i => $p) {
            if (Product::whereKey($p['id'])->exists()) {
                continue;
            }
            $product = new Product(['id' => $p['id']] + Product::attributesFromApi($p) + [
                'rating' => $p['rating'] ?? 0,
                'reviews_count' => $p['reviews'] ?? 0,
            ]);
            $product->created_at = now()->addSeconds($i); // volgorde behouden
            $product->save();
        }

        if (! Setting::find('reviews')) {
            Setting::put('reviews', $catalog['reviews'] ?? []);
        }
    }
}
