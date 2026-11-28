<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optionele klantaccounts zonder wachtwoord: inloggen met een eenmalige code per e-mail (bewijs van het mailadres).
     * Bestellen als gast blijft kunnen. Bestellingen horen bij een account via customer_id of via hetzelfde (geverifieerde) e-mailadres.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('email', 200)->unique();          // altijd kleine letters
            $t->string('name', 150)->default('');
            $t->timestamp('last_login_at')->nullable();
            $t->timestamps();
        });
        Schema::create('login_codes', function (Blueprint $t) {
            $t->id();
            $t->string('email', 200)->index();
            $t->string('code_hash', 64);                 // HMAC van de code; de code zelf wordt nergens bewaard
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('wishlist_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('product_id', 40);
            $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->unique(['customer_id', 'product_id']);
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('customer_id')->nullable()->after('order_no')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('customer_id');
        });
        Schema::dropIfExists('wishlist_items');
        Schema::dropIfExists('login_codes');
        Schema::dropIfExists('customers');
    }
};
