<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Support\Str;

/** Dynamische sitemap: homepage, juridische pagina en alle actieve producten. */
class SitemapController extends Controller
{
    public function __invoke()
    {
        $origin = rtrim((string) config('shop.frontend_url'), '/');
        $esc = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $urls = [
            ['loc' => $origin.'/', 'freq' => 'weekly', 'prio' => '1.0', 'mod' => null],
            ['loc' => $origin.'/juridisch', 'freq' => 'yearly', 'prio' => '0.3', 'mod' => null],
        ];
        foreach (Product::active()->orderBy('created_at')->orderBy('id')->get() as $p) {
            $slug = Str::slug($p->name);
            $urls[] = ['loc' => $origin.'/product/'.rawurlencode($p->id).($slug ? '/'.$slug : ''), 'freq' => 'weekly', 'prio' => '0.8', 'mod' => $p->updated_at?->toDateString()];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>'.$esc($u['loc']).'</loc>'.($u['mod'] ? '<lastmod>'.$u['mod'].'</lastmod>' : '').'<changefreq>'.$u['freq'].'</changefreq><priority>'.$u['prio'].'</priority></url>'."\n";
        }

        return response($xml.'</urlset>'."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
