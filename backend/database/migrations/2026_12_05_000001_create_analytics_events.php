<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Statistieken met toestemming, zonder derden: de browser stuurt alleen gebeurtenissen nadat de bezoeker analytische cookies toestond.
     * Er wordt geen IP-adres, user-agent of e-mailadres bewaard; "vid" is een willekeurig nummer dat alleen in de browser van de bezoeker staat.
     */
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $t) {
            $t->id();
            $t->string('vid', 40)->index();               // willekeurige bezoeker-id (alleen na toestemming aangemaakt)
            $t->string('sid', 40);                        // sessie-id (per tabblad)
            $t->string('type', 12);                       // page | product | cart | checkout | purchase
            $t->string('path', 150)->default('/');        // zonder query en hash
            $t->string('product_id', 40)->nullable();
            $t->string('ref', 100)->nullable();           // alleen de host van een externe verwijzer
            $t->string('src', 60)->nullable();            // utm_source
            $t->string('med', 60)->nullable();            // utm_medium
            $t->string('camp', 80)->nullable();           // utm_campaign
            $t->string('device', 8)->nullable();          // mobile | tablet | desktop
            $t->decimal('value', 10, 2)->nullable();      // alleen bij purchase
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['type', 'created_at']);
            $t->index('sid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
