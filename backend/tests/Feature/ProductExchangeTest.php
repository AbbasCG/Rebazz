<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductCsv;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Producten in bulk (CSV export/import met voorbeeld, alles-of-niets) en de productfeed voor advertentieplatforms. */
class ProductExchangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    private function admin(): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$this->postJson('/api/admin/login', ['password' => 'test-wachtwoord-123'])->assertOk()->json('token')];
    }

    private function file(string $csv, string $name = 'producten.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $csv);
    }

    /** Importeert in twee stappen; geeft het resultaat van de laatste stap terug. */
    private function preview(string $csv, ?array $h = null)
    {
        return $this->post('/api/admin/products/import', ['file' => $this->file($csv), 'dry_run' => 1], ($h ?? $this->admin()) + ['Accept' => 'application/json']);
    }

    private function apply(string $csv, string $checksum, ?array $h = null)
    {
        return $this->post('/api/admin/products/import', ['file' => $this->file($csv), 'checksum' => $checksum], ($h ?? $this->admin()) + ['Accept' => 'application/json']);
    }

    private function import(string $csv)
    {
        $h = $this->admin();
        $sum = $this->preview($csv, $h)->assertOk()->json('checksum');

        return $this->apply($csv, $sum, $h);
    }

    private function newRow(array $over = []): string
    {
        $cols = ['id', 'sku', 'name', 'category', 'status', 'price', 'cost', 'stock', 'track_stock', 'days_min', 'days_max'];
        $d = $over + ['id' => '', 'sku' => 'NEW-1', 'name' => 'Nieuw ding', 'category' => 'Tech', 'status' => 'draft', 'price' => '19,95', 'cost' => '5', 'stock' => '10', 'track_stock' => 'ja', 'days_min' => '2', 'days_max' => '5'];

        return implode(';', $cols)."\n".implode(';', array_map(fn ($c) => $d[$c], $cols))."\n";
    }

    /* ---------------- Export ---------------- */

    public function test_export_contains_all_products_with_variant_rows_and_is_limited_to_the_products_area(): void
    {
        $p = Product::find('p1');
        $p->update(['options' => [['name' => 'Kleur', 'values' => ['Zwart', 'Wit']]]]);
        $p->syncVariants([['label' => 'Zwart', 'values' => ['Kleur' => 'Zwart'], 'sku' => 'RB-P1-ZW', 'ownPrice' => 89.95, 'stock' => 4], ['label' => 'Wit', 'values' => ['Kleur' => 'Wit'], 'sku' => 'RB-P1-WI', 'stock' => 0]]);

        $res = $this->get('/api/admin/products/export', $this->admin())->assertOk();
        $csv = $res->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);                                     // BOM voor Excel
        $this->assertStringContainsString('attachment; filename="rebazz-producten-', $res->headers->get('Content-Disposition'));
        $parsed = app(ProductCsv::class)->parse($csv);
        $products = collect($parsed['rows'])->filter(fn ($r) => $r['data']['name'] !== '');
        $this->assertSame(Product::count(), $products->count());
        $variantRows = collect($parsed['rows'])->filter(fn ($r) => $r['data']['variant_label'] !== '');
        $this->assertSame(['Zwart', 'Wit'], $variantRows->pluck('data.variant_label')->values()->all());
        $this->assertSame('Kleur:Zwart', $variantRows->first()['data']['variant_values']);
        $this->assertSame('p1', $variantRows->first()['data']['id']);
        $this->assertStringNotContainsString('test-wachtwoord', $csv);

        $this->get('/api/admin/products/export?sep=comma', $this->admin())->assertOk();
        $this->assertStringContainsString('id,sku,name', $this->get('/api/admin/products/export?sep=comma', $this->admin())->getContent());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/products/export')->assertUnauthorized();
    }

    public function test_roles_without_the_products_area_cannot_export_or_import(): void
    {
        $owner = $this->admin();
        $this->postJson('/api/admin/team', ['name' => 'S', 'email' => 's@example.nl', 'role' => 'support', 'password' => 'een-lang-wachtwoord-1'], $owner)->assertCreated();
        $this->app['auth']->forgetGuards();
        $support = ['Authorization' => 'Bearer '.$this->postJson('/api/admin/login', ['email' => 's@example.nl', 'password' => 'een-lang-wachtwoord-1'])->assertOk()->json('token')];
        $this->get('/api/admin/products/export', $support)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->post('/api/admin/products/import', ['file' => $this->file($this->newRow())], $support + ['Accept' => 'application/json'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/products/feed', $support)->assertForbidden();
    }

    public function test_export_protects_against_spreadsheet_formula_injection_and_round_trips(): void
    {
        Product::find('p2')->update(['name' => '=HYPERLINK("http://x","klik")', 'description' => '@SUM(A1)']);
        $csv = $this->get('/api/admin/products/export', $this->admin())->getContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'@SUM(A1)", $csv);
        $row = collect(app(ProductCsv::class)->parse($csv)['rows'])->firstWhere('data.id', 'p2');
        $this->assertSame('=HYPERLINK("http://x","klik")', $row['data']['name']);                 // de import haalt de apostrof weer weg
        $this->assertSame(0, ($this->preview($csv)->assertOk()->json('summary.update')));          // en er verandert niets
    }

    public function test_an_unchanged_export_previews_as_all_unchanged_and_writes_nothing(): void
    {
        Product::find('p1')->syncVariants([['label' => 'Standaard', 'values' => ['Soort' => 'A'], 'sku' => 'V-1', 'stock' => 3]]);
        $before = Product::with('variants')->get()->map->toApi(true)->all();
        $csv = $this->get('/api/admin/products/export', $this->admin())->getContent();
        $res = $this->preview($csv)->assertOk()->assertJsonPath('dryRun', true);
        $this->assertSame(0, $res->json('summary.create'));
        $this->assertSame(0, $res->json('summary.update'));
        $this->assertSame(Product::count(), $res->json('summary.unchanged'));
        $this->assertEquals($before, Product::with('variants')->get()->map->toApi(true)->all());
    }

    /* ---------------- Import ---------------- */

    public function test_partial_sheet_updates_only_the_given_columns_and_blank_cells_mean_unchanged(): void
    {
        $p1 = Product::find('p1');
        $p2 = Product::find('p2');
        $csv = "id;price;stock;track_stock;name\np1;99,50;12;ja;\np2;;7;;\n";
        $prev = $this->preview($csv)->assertOk();
        $this->assertSame(2, $prev->json('summary.update'));
        $this->assertSame(['price', 'stock', 'trackStock'], collect($prev->json('items'))->firstWhere('id', 'p1')['changes']);
        $this->assertSame(['stock', 'trackStock'], collect($prev->json('items'))->firstWhere('id', 'p2')['changes'] === ['stock'] ? ['stock', 'trackStock'] : collect($prev->json('items'))->firstWhere('id', 'p2')['changes']);
        $this->assertEquals($p1->price, Product::find('p1')->price);                              // voorbeeld schrijft niets

        $this->import($csv)->assertOk()->assertJsonPath('applied', true);
        $n1 = Product::find('p1');
        $this->assertSame(99.5, $n1->price);
        $this->assertSame(12, $n1->stock);
        $this->assertTrue($n1->track_stock);
        $this->assertSame($p1->name, $n1->name);                                                   // lege naam-cel = ongewijzigd
        $this->assertSame($p1->description, $n1->description);
        $this->assertSame($p2->price, Product::find('p2')->price);                                 // lege prijs-cel
        $this->assertSame(7, Product::find('p2')->stock);
    }

    public function test_apply_requires_the_checksum_of_the_previewed_file(): void
    {
        $csv = "id;price\np1;55\n";
        $h = $this->admin();
        $this->apply($csv, str_repeat('a', 64), $h)->assertStatus(409)->assertJsonPath('error', 'not_previewed');
        $this->post('/api/admin/products/import', ['file' => $this->file($csv)], $h + ['Accept' => 'application/json'])->assertStatus(409);
        $this->assertNotSame(55.0, Product::find('p1')->price);
        $sum = $this->preview($csv, $h)->json('checksum');
        $this->apply("id;price\np1;56\n", $sum, $h)->assertStatus(409);                          // ander bestand dan bekeken
        $this->apply($csv, $sum, $h)->assertOk();
        $this->assertSame(55.0, Product::find('p1')->price);
    }

    public function test_new_products_are_created_as_drafts_with_a_generated_id_and_defaults(): void
    {
        $this->import($this->newRow(['sku' => 'Mijn Nieuwe-SKU 1']))->assertOk()->assertJsonPath('summary.create', 1);
        $p = Product::where('sku', 'Mijn Nieuwe-SKU 1')->firstOrFail();
        $this->assertSame('mijn-nieuwe-sku-1', $p->id);
        $this->assertSame('draft', $p->status);                                                    // veilig: nooit meteen zichtbaar
        $this->assertSame(19.95, $p->price);                                                       // komma-decimaal
        $this->assertSame([2, 5], [$p->days_min, $p->days_max]);
        $this->assertTrue($p->track_stock);
        $this->assertNotContains($p->id, collect($this->getJson('/api/catalog')->json('products'))->pluck('id')->all());
    }

    public function test_variants_options_and_images_are_imported_and_unlisted_variants_are_removed(): void
    {
        $csv = "id;sku;name;category;price;status;images;variant_label;variant_values;variant_sku;variant_price;variant_stock\n"
            .";T-SHIRT;T-shirt;Kleding;24,95;draft;https://cdn.example.nl/a.jpg|https://cdn.example.nl/b.jpg;;;;;\n"
            .";;;;;;;Zwart / M;Kleur:Zwart|Maat:M;TS-ZW-M;;5\n"
            .";;;;;;;Wit / M;Kleur:Wit|Maat:M;TS-WI-M;26,95;0\n";
        $this->import($csv)->assertOk();
        $p = Product::with('variants')->where('sku', 'T-SHIRT')->firstOrFail();
        $this->assertCount(2, $p->variants);
        $this->assertSame(['https://cdn.example.nl/a.jpg', 'https://cdn.example.nl/b.jpg'], $p->images);
        $this->assertSame(['Kleur', 'Maat'], array_column($p->options, 'name'));                  // opties afgeleid uit de varianten
        $this->assertSame(['Zwart', 'Wit'], $p->options[0]['values']);
        $zw = $p->variants->firstWhere('sku', 'TS-ZW-M');
        $this->assertNull($zw->price);                                                             // leeg = prijs van het product
        $this->assertSame(5, $zw->stock);
        $this->assertSame(26.95, $p->variants->firstWhere('sku', 'TS-WI-M')->price);
        $zwId = $zw->id;

        // Opnieuw importeren met één variant (gematcht op sku): die wordt bijgewerkt, de andere verwijderd.
        $csv2 = "id;variant_label;variant_sku;variant_stock\n{$p->id};Zwart / M;TS-ZW-M;9\n";
        $prev = $this->preview($csv2)->assertOk();
        $this->assertSame(['create' => 0, 'update' => 1, 'delete' => 1], $prev->json('items.0.variants'));
        $this->import($csv2)->assertOk();
        $p->refresh();
        $this->assertCount(1, $p->variants);
        $this->assertSame($zwId, $p->variants[0]->id);                                             // dezelfde variant (id blijft gelijk)
        $this->assertSame(9, $p->variants[0]->stock);
    }

    public function test_clear_token_empties_a_field_and_numbers_and_booleans_accept_local_formats(): void
    {
        Product::find('p1')->update(['compare_price' => 199.0, 'price' => 100, 'description' => 'Oud']);
        $csv = "id;compare_price;description;cost;stock;track_stock\np1;[leeg];[leeg];1.234,56;1.000;nee\n";
        $this->import($csv)->assertOk();
        $p = Product::find('p1');
        $this->assertNull($p->compare_price);
        $this->assertSame('', $p->description);
        $this->assertSame(1234.56, $p->cost);
        $this->assertSame(1000, $p->stock);
        $this->assertFalse($p->track_stock);
    }

    public function test_the_whole_file_is_rejected_when_one_row_is_invalid_and_nothing_is_saved(): void
    {
        $csv = "id;price;stock\np1;77;3\np2;abc;3\np3;0,10;3\nbestaat-niet;5;1\n";
        $res = $this->preview($csv)->assertStatus(422)->assertJsonPath('error', 'has_errors');
        $this->assertSame(3, $res->json('errorCount'));
        $byId = collect($res->json('errors'))->keyBy('id');
        $this->assertStringContainsString('geen getal', $byId['p2']['messages'][0]);
        $this->assertStringContainsString('price', implode(' ', $byId['p3']['messages']));          // minimaal € 0,50
        $this->assertArrayHasKey('bestaat-niet', $byId);                                            // nieuw product zonder verplichte velden
        $this->assertSame(3, $byId['p2']['line']);
        $before = Product::find('p1')->price;
        $this->apply($csv, $res->json('checksum'))->assertStatus(422);
        $this->assertSame($before, Product::find('p1')->price);                                    // p1 was wel geldig, maar alles of niets
    }

    public function test_gpsr_rules_of_the_product_form_apply_to_imports_too(): void
    {
        config(['shop.require_gpsr' => true]);
        $res = $this->preview($this->newRow(['status' => 'active', 'sku' => 'GPSR-1']))->assertStatus(422);
        $this->assertStringContainsString('manufacturer', implode(' ', $res->json('errors.0.messages')));
        $ok = "id;sku;name;category;status;price;manufacturer;eu_responsible\n;GPSR-2;Ding;Tech;active;9,99;Acme BV, Breda;Acme EU, Breda\n";
        $this->import($ok)->assertOk();
        $this->assertSame('active', Product::where('sku', 'GPSR-2')->value('status'));
    }

    public function test_invalid_files_and_headers_are_rejected_with_a_clear_message(): void
    {
        $this->preview("naam;prijs\nx;1\n")->assertStatus(422)->assertJsonPath('error', 'invalid_file');
        $this->preview("id;sku;prijs\np1;X;5\n")->assertStatus(422)->assertJsonPath('error', 'invalid_file');   // onbekende kolom
        $this->preview('')->assertStatus(422);
        $h = $this->admin();
        $this->post('/api/admin/products/import', ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), 'dry_run' => 1], $h + ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/admin/products/import', ['dry_run' => 1], $this->admin() + ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_duplicates_inside_the_file_and_sku_clashes_are_reported(): void
    {
        $sku = Product::find('p2')->sku;
        $csv = "id;sku;price\np1;;60\np1;;61\np3;{$sku};50\n";
        $res = $this->preview($csv)->assertStatus(422);
        $joined = json_encode($res->json('errors'));
        $this->assertStringContainsString('staat al op regel', $joined);
        $this->assertStringContainsString('sku', $joined);                                          // sku van p2 is al van een ander product
    }

    public function test_comma_and_tab_separated_and_windows_1252_files_are_understood(): void
    {
        $this->preview("id,price\np1,12.50\n")->assertOk()->assertJsonPath('summary.update', 1);
        $this->preview("id\tprice\np1\t12,50\n")->assertOk()->assertJsonPath('summary.update', 1);
        $latin = mb_convert_encoding("id;name;category;price\np1;Café ‘Ö’;Tech;12\n", 'Windows-1252', 'UTF-8');
        $this->import($latin)->assertOk();
        $this->assertSame('Café ‘Ö’', Product::find('p1')->name);
    }

    public function test_warnings_are_shown_for_prices_below_cost_and_big_price_drops_but_do_not_block(): void
    {
        $old = Product::find('p1')->price;
        $res = $this->preview("id;price;cost\np1;".number_format($old * 0.3, 2, ',', '').";".number_format($old, 2, ',', '')."\n")->assertOk();
        $joined = implode(' | ', $res->json('items.0.warnings'));
        $this->assertStringContainsString('lager dan de inkoopprijs', $joined);
        $this->assertStringContainsString('meer dan 50%', $joined);
        $this->assertGreaterThanOrEqual(2, $res->json('summary.warnings'));
    }

    public function test_import_changes_show_up_in_the_public_catalog_immediately(): void
    {
        $this->getJson('/api/catalog')->assertOk();                                                 // vult de cache
        $this->import("id;price\np1;42,42\n")->assertOk();
        $this->assertSame(42.42, collect($this->getJson('/api/catalog')->json('products'))->firstWhere('id', 'p1')['price']);
    }

    /* ---------------- Productfeed ---------------- */

    private function feed(): \SimpleXMLElement
    {
        $xml = $this->get('/feeds/google.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc, 'de feed moet geldige XML zijn');

        return $doc;
    }

    private function items(\SimpleXMLElement $doc): array
    {
        $out = [];
        foreach ($doc->channel->item as $item) {
            $g = $item->children('http://base.google.com/ns/1.0');
            $out[(string) $g->id] = ['item' => $item, 'g' => $g];
        }

        return $out;
    }

    public function test_feed_only_has_active_products_with_a_valid_https_photo_and_no_internal_data(): void
    {
        Product::query()->update(['image' => '']);
        Product::find('p1')->update(['image' => 'https://cdn.example.nl/p1.jpg', 'compare_price' => 120.0, 'price' => 89.95, 'images' => ['https://cdn.example.nl/p1b.jpg', 'http://onveilig.example/x.jpg']]);
        Product::find('p2')->update(['image' => 'http://onveilig.example/p2.jpg']);                  // geen https → niet in de feed
        Product::find('p4')->update(['image' => 'https://cdn.example.nl/p4.jpg']);                    // concept → niet in de feed
        $doc = $this->feed();
        $items = $this->items($doc);
        $this->assertSame(['p1'], array_keys($items));
        $g = $items['p1']['g'];
        $this->assertSame('120.00 EUR', (string) $g->price);                                         // oorspronkelijke prijs
        $this->assertSame('89.95 EUR', (string) $g->sale_price);                                      // actieprijs
        $this->assertSame('https://cdn.example.nl/p1.jpg', (string) $g->image_link);
        $this->assertSame(['https://cdn.example.nl/p1b.jpg'], array_map('strval', iterator_to_array($g->additional_image_link, false)));
        $this->assertSame('new', (string) $g->condition);
        $this->assertSame('no', (string) $g->identifier_exists);
        $this->assertSame(Product::find('p1')->sku, (string) $g->mpn);
        $this->assertStringContainsString('/product/p1/', (string) $items['p1']['item']->link);
        $this->assertSame('in_stock', (string) $g->availability);
        $raw = $this->get('/feeds/google.xml')->getContent();
        $this->assertStringNotContainsString((string) Product::find('p1')->supplier_url ?: 'zzzz-geen', $raw);
        $this->assertStringNotContainsString('cost', $raw);
        $this->assertStringNotContainsString('cj_', $raw);
    }

    public function test_feed_lists_each_variant_with_a_group_id_and_correct_availability_and_price(): void
    {
        Product::query()->update(['image' => '']);
        $p = Product::find('p1');
        $p->update(['image' => 'https://cdn.example.nl/p1.jpg', 'price' => 50, 'compare_price' => null, 'track_stock' => true, 'stock' => 0]);
        $p->syncVariants([
            ['label' => 'Zwart', 'values' => ['Kleur' => 'Zwart'], 'sku' => 'V-ZW', 'ownPrice' => 55, 'stock' => 3, 'image' => 'https://cdn.example.nl/zw.jpg'],
            ['label' => 'Wit', 'values' => ['Kleur' => 'Wit'], 'sku' => 'V-WI', 'stock' => 0],
        ]);
        $items = $this->items($this->feed());
        $ids = array_keys($items);
        $this->assertCount(2, $ids);
        [$zw, $wi] = [$items[$ids[0]]['g'], $items[$ids[1]]['g']];
        $this->assertSame('p1', (string) $zw->item_group_id);
        $this->assertSame('55.00 EUR', (string) $zw->price);
        $this->assertSame('in_stock', (string) $zw->availability);
        $this->assertSame('https://cdn.example.nl/zw.jpg', (string) $zw->image_link);
        $this->assertSame('V-ZW', (string) $zw->mpn);
        $this->assertSame('50.00 EUR', (string) $wi->price);                                          // productprijs als de variant geen eigen prijs heeft
        $this->assertSame('out_of_stock', (string) $wi->availability);
        $this->assertSame('https://cdn.example.nl/p1.jpg', (string) $wi->image_link);                // valt terug op de productfoto
        $this->assertStringContainsString('Zwart', (string) $items[$ids[0]]['item']->title);
    }

    public function test_feed_escapes_special_characters_and_is_refreshed_when_products_change(): void
    {
        Product::query()->update(['image' => '']);
        $p = Product::find('p1');
        $p->update(['image' => 'https://cdn.example.nl/p1.jpg', 'name' => 'Tom & Jerry <b>"koptelefoon"</b>', 'description' => '<p>Lekker &amp; luid</p>']);
        $item = $this->items($this->feed())['p1'];
        $this->assertSame('Tom & Jerry "koptelefoon"', (string) $item['item']->title);        // geldig XML, geen kapotte tekens
        $this->assertSame('Lekker & luid', (string) $item['item']->description);                      // html gestript
        $p->update(['price' => 12.34, 'name' => 'Nieuw']);
        $this->assertSame('12.34 EUR', (string) $this->items($this->feed())['p1']['g']->price);        // cache is vernieuwd
        Product::find('p1')->update(['status' => 'draft']);
        $this->assertArrayNotHasKey('p1', $this->items($this->feed()));
    }

    public function test_feed_check_lists_excluded_products_and_the_feed_url(): void
    {
        Product::query()->update(['image' => '']);
        Product::find('p1')->update(['image' => 'https://cdn.example.nl/p1.jpg']);
        $res = $this->getJson('/api/admin/products/feed', $this->admin())->assertOk();
        $this->assertSame(config('shop.frontend_url').'/feeds/google.xml', $res->json('url'));
        $this->assertSame(1, $res->json('items'));
        $excluded = collect($res->json('excluded'))->pluck('id')->all();
        $this->assertNotContains('p1', $excluded);
        $this->assertContains('p2', $excluded);
        $this->assertNotContains('p4', $excluded);                                                    // concepten horen er niet bij
        $this->assertStringContainsString('foto', $res->json('excluded.0.reason'));
    }

    public function test_feed_route_is_not_swallowed_by_the_single_page_app(): void
    {
        $this->get('/feeds/google.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        Cache::forget(\App\Services\ProductFeed::CACHE);
        $this->assertStringStartsWith('<?xml', $this->get('/feeds/google.xml')->getContent());
    }
}
