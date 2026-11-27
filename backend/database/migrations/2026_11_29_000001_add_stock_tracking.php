<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Voorraadbeheer is per product optioneel: bij dropshipping heeft de leverancier de voorraad, dus standaard wordt er niets bijgehouden.
     * stock_deducted onthoudt hoeveel stuks een bestelling echt van de voorraad heeft afgeschreven (voor correct terugboeken).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('track_stock')->default(false)->after('stock');
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->unsignedSmallInteger('stock_deducted')->default(0)->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('stock_deducted'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('track_stock'));
    }
};
