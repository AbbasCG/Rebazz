<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Serveert de gebouwde React-app (public/app/index.html) en zet de paginagegevens (titel, beschrijving, canonical,
 * Open Graph, productgegevens als JSON-LD en een lijst links) al in de eerste HTML. Zoekmachines en social previews
 * hoeven dan geen JavaScript uit te voeren. Een controller i.p.v. een closure, zodat `route:cache` werkt.
 */
class FrontendController extends Controller
{
    public function __invoke(Request $request)
    {
        $index = public_path('app/index.html');
        abort_unless(is_file($index), 503, 'De frontend is nog niet gebouwd. Voer "npm run build" uit in de map frontend.');
        $html = (string) file_get_contents($index);
        $status = 200;

        $path = trim($request->path(), '/');
        if (preg_match('#^product/([^/]+)#', $path, $m)) {
            $product = Product::active()->find(rawurldecode($m[1]));
            if ($product) {
                $html = $this->injectProduct($html, $product);
            } else {
                $status = 404; // geen "soft 404": een onbekend product geeft ook echt een 404 aan zoekmachines
                $html = $this->setHead($html, ['title' => 'Rebazz', 'robots' => 'noindex, nofollow']);
            }
        } elseif ($path === '') {
            $html = $this->injectHome($html);
        }

        return response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-cache']);
    }

    private function origin(): string
    {
        return rtrim((string) config('shop.frontend_url'), '/');
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function productUrl(Product $p): string
    {
        $slug = Str::slug($p->name);

        return $this->origin().'/product/'.rawurlencode($p->id).($slug ? '/'.$slug : '');
    }

    private function absolute(?string $image): ?string
    {
        if (! $image) {
            return null;
        }

        return str_starts_with($image, '/') ? $this->origin().$image : (str_starts_with($image, 'https://') ? $image : null);
    }

    private function injectProduct(string $html, Product $p): string
    {
        $url = $this->productUrl($p);
        $desc = Str::limit(trim(strip_tags((string) $p->description)) ?: 'Rebazz', 300, '…');
        $image = $this->absolute($p->image);
        $ld = [
            '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $p->name, 'description' => $desc, 'sku' => $p->sku,
            'brand' => ['@type' => 'Brand', 'name' => 'Rebazz'],
            'offers' => ['@type' => 'Offer', 'url' => $url, 'priceCurrency' => 'EUR', 'price' => number_format((float) $p->price, 2, '.', ''),
                'availability' => (! $p->track_stock || $p->stock > 0) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock', 'itemCondition' => 'https://schema.org/NewCondition'],
        ];
        if ($image) {
            $ld['image'] = $image;
        }
        if ($p->reviews_count > 0) {
            $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $p->rating, 'reviewCount' => $p->reviews_count];
        }
        $features = collect($p->features ?? [])->map(fn ($f) => '<li>'.$this->e((string) $f).'</li>')->implode('');
        $fallback = '<main><h1>'.$this->e($p->name).'</h1><p>'.$this->e($desc).'</p><p>€ '.number_format((float) $p->price, 2, ',', '.').'</p>'
            .($features ? '<ul>'.$features.'</ul>' : '').'<p><a href="'.$this->e($this->origin().'/').'">Rebazz</a></p></main>';

        return $this->setHead($html, [
            'title' => $p->name.' — Rebazz', 'description' => $desc, 'canonical' => $url, 'url' => $url, 'image' => $image,
            'jsonld' => $ld, 'noscript' => $fallback,
        ]);
    }

    private function injectHome(string $html): string
    {
        $items = Product::active()->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (Product $p) => '<li><a href="'.$this->e($this->productUrl($p)).'">'.$this->e($p->name).'</a> — € '.number_format((float) $p->price, 2, ',', '.').'</li>')
            ->implode('');

        return $this->setHead($html, ['noscript' => '<main><h1>Rebazz</h1>'.($items ? '<ul>'.$items.'</ul>' : '').'<p>Schakel JavaScript in om te bestellen.</p></main>']);
    }

    /** Vervangt bekende tags in de head en voegt JSON-LD, afbeelding en noscript-inhoud toe. Onbekende tags worden overgeslagen. */
    private function setHead(string $html, array $v): string
    {
        $sub = function (string $pattern, string $replacement) use (&$html) {
            // Closure i.p.v. preg_replace-vervangstring: waarden mogen "$1" of "\" bevatten zonder dat dat wordt geïnterpreteerd.
            $html = preg_replace_callback($pattern, fn () => $replacement, $html, 1) ?? $html;
        };
        if (isset($v['title'])) {
            $sub('#<title>.*?</title>#s', '<title>'.$this->e($v['title']).'</title>');
            $sub('#<meta property="og:title" content="[^"]*"\s*/?>#', '<meta property="og:title" content="'.$this->e($v['title']).'" />');
        }
        if (isset($v['description'])) {
            $sub('#<meta name="description" content="[^"]*"\s*/?>#', '<meta name="description" content="'.$this->e($v['description']).'" />');
            $sub('#<meta property="og:description" content="[^"]*"\s*/?>#', '<meta property="og:description" content="'.$this->e($v['description']).'" />');
        }
        if (isset($v['canonical'])) {
            $sub('#<link rel="canonical" href="[^"]*"\s*/?>#', '<link rel="canonical" href="'.$this->e($v['canonical']).'" />');
        }
        if (isset($v['url'])) {
            $sub('#<meta property="og:url" content="[^"]*"\s*/?>#', '<meta property="og:url" content="'.$this->e($v['url']).'" />');
        }
        $extra = '';
        if (! empty($v['image'])) {
            $extra .= '<meta property="og:image" content="'.$this->e($v['image']).'" />';
        }
        if (isset($v['robots'])) {
            $extra .= '<meta name="robots" content="'.$this->e($v['robots']).'" />';
        }
        if (isset($v['jsonld'])) {
            $extra .= '<script type="application/ld+json">'.json_encode($v['jsonld'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'</script>';
        }
        if ($extra !== '') {
            $sub('#</head>#', $extra.'</head>');
        }
        if (isset($v['noscript'])) {
            $sub('#<noscript>.*?</noscript>#s', '<noscript>'.$v['noscript'].'</noscript>');
        }

        return $html;
    }
}
