<?php

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Productpagina's, startpagina en sitemap: gegevens zitten al in de eerste HTML (zonder JavaScript). */
class FrontendPagesTest extends TestCase
{
    use RefreshDatabase;

    private string $publicDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // Eigen kopie van de gebouwde pagina, zodat de test niet van `npm run build` afhangt.
        $this->publicDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rebazz-test-public-'.uniqid();
        mkdir($this->publicDir.DIRECTORY_SEPARATOR.'app', 0777, true);
        file_put_contents($this->publicDir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'index.html', <<<'HTML'
<!doctype html>
<html lang="nl">
  <head>
    <meta charset="UTF-8" />
    <title>Rebazz</title>
    <meta name="description" content="Standaard beschrijving" />
    <link rel="canonical" href="https://rebazz.nl/" />
    <meta property="og:title" content="Rebazz" />
    <meta property="og:description" content="Standaard" />
    <meta property="og:url" content="https://rebazz.nl/" />
  </head>
  <body>
    <div id="root"></div>
    <noscript>Schakel JavaScript in.</noscript>
  </body>
</html>
HTML);
        $this->app->usePublicPath($this->publicDir);
        config(['shop.frontend_url' => 'https://rebazz.test']);
    }

    protected function tearDown(): void
    {
        @unlink($this->publicDir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'index.html');
        @rmdir($this->publicDir.DIRECTORY_SEPARATOR.'app');
        @rmdir($this->publicDir);
        parent::tearDown();
    }

    public function test_product_page_contains_title_canonical_structured_data_and_fallback_content(): void
    {
        $p = Product::active()->orderBy('created_at')->orderBy('id')->first();
        $res = $this->get('/product/'.$p->id.'/whatever')->assertOk();
        $html = $res->getContent();

        $this->assertStringContainsString('<title>'.e($p->name).' — Rebazz</title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://rebazz.test/product/'.$p->id.'/', $html);
        $this->assertStringContainsString('"@type":"Product"', $html);
        $this->assertStringContainsString('"priceCurrency":"EUR"', $html);
        $this->assertStringContainsString('"price":"'.number_format($p->price, 2, '.', '').'"', $html);
        $this->assertStringContainsString('<noscript><main><h1>'.e($p->name).'</h1>', $html);
        $this->assertStringNotContainsString('Standaard beschrijving', $html); // beschrijving is vervangen
    }

    public function test_product_values_are_escaped_and_special_characters_do_not_break_the_page(): void
    {
        $p = Product::active()->first();
        $p->update(['name' => 'Test "quote" $1 \\0 </script><b>', 'description' => 'Beschrijving met $0 en </noscript> en "aanhalingstekens"']);
        $html = $this->get('/product/'.$p->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('</script><b>', $html);
        $this->assertStringNotContainsString('</noscript> en', $html);
        $this->assertStringContainsString('&quot;quote&quot;', $html);
        $this->assertStringContainsString('$1', $html); // letterlijk, niet als terugverwijzing
        $this->assertStringContainsString('\\u003C/script\\u003E', $html); // JSON-LD kan het script niet afsluiten
    }

    public function test_unknown_or_draft_product_returns_a_real_404_and_noindex(): void
    {
        $draft = Product::where('status', '!=', 'active')->first();
        $res = $this->get('/product/'.($draft?->id ?? 'bestaat-niet'))->assertNotFound();
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow" />', $res->getContent());
    }

    public function test_home_lists_product_links_for_crawlers_and_other_paths_are_served_unchanged(): void
    {
        $p = Product::active()->orderBy('created_at')->orderBy('id')->first();
        $this->assertStringContainsString('href="https://rebazz.test/product/'.$p->id, $this->get('/')->assertOk()->getContent());
        $this->get('/juridisch')->assertOk()->assertSee('Standaard beschrijving', false);
    }

    public function test_sitemap_lists_active_products_only(): void
    {
        $res = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringContainsString('application/xml', $res->headers->get('Content-Type'));
        $xml = $res->getContent();
        $this->assertStringContainsString('<loc>https://rebazz.test/</loc>', $xml);
        $this->assertSame(Product::active()->count() + 2, substr_count($xml, '<url>'));
        $draft = Product::where('status', '!=', 'active')->first();
        if ($draft) {
            $this->assertStringNotContainsString('/product/'.$draft->id, $xml);
        }
    }
}
