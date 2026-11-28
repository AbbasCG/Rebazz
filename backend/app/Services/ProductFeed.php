<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Productfeed in het Google Merchant-formaat (RSS 2.0 met g:-velden). Google Shopping, Meta (Facebook/Instagram), Pinterest en TikTok lezen dit.
 * Bevat alleen actieve producten, nooit inkoopprijzen of leveranciersgegevens. Producten zonder geldige https-foto worden overgeslagen
 * (die worden door de advertentieplatforms toch afgekeurd) en staan in de controlelijst voor het beheer.
 */
class ProductFeed
{
    public const CACHE = 'shop.feed.google';

    private function origin(): string
    {
        return rtrim((string) config('shop.frontend_url'), '/');
    }

    /** Absoluut https-adres van een foto, of null als het geen bruikbaar adres is. */
    private function image(?string $path): ?string
    {
        $path = trim((string) $path);
        if (str_starts_with($path, 'https://')) {
            return $path;
        }
        if (str_starts_with($path, '/media/') && str_starts_with($this->origin(), 'https://')) {
            return $this->origin().$path;
        }

        return null;
    }

    private function text(string $s, int $max): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8')));

        return mb_substr($plain, 0, $max);
    }

    private function money(float $v): string
    {
        return number_format($v, 2, '.', '').' '.strtoupper((string) config('shop.currency'));
    }

    /** @return array{items: array<int, array<string, mixed>>, excluded: array<int, array{id: string, name: string, reason: string}>} */
    public function build(): array
    {
        $brand = Setting::brand();
        $items = [];
        $excluded = [];
        foreach (Product::active()->with('variants')->orderBy('created_at')->orderBy('id')->get() as $p) {
            $api = $p->toApi();
            $images = array_values(array_filter(array_map(fn ($i) => $this->image($i), array_merge([$p->image], (array) $p->images))));
            $images = array_values(array_unique($images));
            if (! $images) {
                $excluded[] = ['id' => $p->id, 'name' => $p->name, 'reason' => 'Geen foto met https-adres (voeg een foto toe of upload er een).'];
                continue;
            }
            $link = $this->origin().'/product/'.rawurlencode($p->id).(Str::slug($p->name) ? '/'.Str::slug($p->name) : '');
            $desc = $this->text((string) $p->description, 5000) ?: $this->text($p->name, 5000);
            $base = [
                'link' => $link, 'description' => $desc, 'brand' => $this->text((string) ($p->manufacturer ?: $brand['name']), 70),
                'category' => $this->text((string) $p->category, 100), 'mpn' => $p->sku,
            ];
            $make = function (string $id, string $title, float $price, ?float $compare, ?string $img, bool $inStock, ?string $group, string $mpn) use ($base, $images) {
                $main = $img ?: $images[0];
                $onSale = $compare && $compare > $price;

                return array_replace($base, [
                    'id' => $id, 'title' => $this->text($title, 150), 'image' => $main,
                    'extra' => array_slice(array_values(array_diff($images, [$main])), 0, 10),
                    'availability' => $inStock ? 'in_stock' : 'out_of_stock',
                    'price' => $this->money($onSale ? $compare : $price), 'sale_price' => $onSale ? $this->money($price) : null,
                    'group' => $group, 'mpn' => $mpn,
                ]);
            };
            if ($p->hasVariants()) {
                foreach ($p->variants as $v) {
                    $vi = collect($api['variants'])->firstWhere('id', $v->id);
                    $items[] = $make($p->id.'-'.$v->id, $p->name.' — '.$v->label, $p->unitPrice($v), $p->compare_price, $this->image($v->image), (bool) ($vi['inStock'] ?? true), $p->id, $v->sku ?: $p->sku);
                }
            } else {
                $items[] = $make($p->id, $p->name, (float) $p->price, $p->compare_price, null, (bool) $api['inStock'], null, $p->sku);
            }
        }

        return ['items' => $items, 'excluded' => $excluded];
    }

    public function xml(): string
    {
        return Cache::remember(self::CACHE, 600, function () {
            $brand = Setting::brand();
            $esc = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">'."\n<channel>\n"
                .'<title>'.$esc($brand['name']).'</title><link>'.$esc($this->origin().'/').'</link><description>'.$esc($brand['name'].' productfeed').'</description>'."\n";
            foreach ($this->build()['items'] as $i) {
                $xml .= "<item>\n"
                    .'<g:id>'.$esc($i['id']).'</g:id><title>'.$esc($i['title']).'</title><description>'.$esc($i['description']).'</description><link>'.$esc($i['link']).'</link>'
                    .'<g:image_link>'.$esc($i['image']).'</g:image_link>';
                foreach ($i['extra'] as $e) {
                    $xml .= '<g:additional_image_link>'.$esc($e).'</g:additional_image_link>';
                }
                $xml .= '<g:availability>'.$i['availability'].'</g:availability><g:price>'.$esc($i['price']).'</g:price>'
                    .($i['sale_price'] ? '<g:sale_price>'.$esc($i['sale_price']).'</g:sale_price>' : '')
                    .'<g:condition>new</g:condition><g:brand>'.$esc($i['brand']).'</g:brand>'
                    .($i['mpn'] ? '<g:mpn>'.$esc($i['mpn']).'</g:mpn>' : '').'<g:identifier_exists>no</g:identifier_exists>'
                    .($i['category'] ? '<g:product_type>'.$esc($i['category']).'</g:product_type>' : '')
                    .($i['group'] ? '<g:item_group_id>'.$esc($i['group']).'</g:item_group_id>' : '')
                    ."\n</item>\n";
            }

            return $xml."</channel>\n</rss>\n";
        });
    }

    public function url(): string
    {
        return $this->origin().'/feeds/google.xml';
    }
}
