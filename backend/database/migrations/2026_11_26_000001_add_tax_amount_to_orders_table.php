<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Werkelijk door Stripe Tax berekende btw (null = niet beschikbaar; dan schat het btw-overzicht). */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('tax_amount', 10, 2)->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn('tax_amount');
        });
    }
};
