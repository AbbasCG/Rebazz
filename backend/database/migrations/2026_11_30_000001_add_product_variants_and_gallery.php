<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Productvarianten (maat/kleur) met eigen prijs, inkoop, CJ-variant en voorraad, plus extra productfoto's. */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->string('product_id', 40);
            $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $t->string('label', 120);                       // bijv. "Zwart / M"
            $t->jsonb('values')->default('{}');             // {"Kleur": "Zwart", "Maat": "M"}
            $t->string('sku', 60)->default('');
            $t->decimal('price', 10, 2)->nullable();        // leeg = prijs van het product
            $t->decimal('cost', 10, 2)->nullable();         // leeg = inkoop van het product
            $t->string('cj_vid', 80)->default('');          // leeg = CJ-variant van het product
            $t->integer('stock')->default(0);
            $t->string('image', 500)->default('');
            $t->unsignedSmallInteger('position')->default(0);
            $t->index('product_id');
        });
        Schema::table('products', function (Blueprint $t) {
            $t->jsonb('options')->default('[]')->after('features');  // [{"name":"Kleur","values":["Zwart","Wit"]}]
            $t->jsonb('images')->default('[]')->after('options');    // extra foto's naast "image"
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->unsignedBigInteger('variant_id')->nullable()->after('product_id');
            $t->string('variant_label', 120)->default('')->after('variant_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn(['variant_id', 'variant_label']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['options', 'images']));
        Schema::dropIfExists('product_variants');
    }
};
