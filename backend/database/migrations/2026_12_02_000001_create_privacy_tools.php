<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AVG/GDPR: verzoeken van betrokkenen (inzage/export, wissen) worden vastgelegd als verantwoording, zonder het e-mailadres
     * zelf te bewaren (alleen een hash). Bestellingen krijgen een "geanonimiseerd op"-markering.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->timestamp('anonymized_at')->nullable()->after('delivered_at');
        });
        Schema::create('privacy_requests', function (Blueprint $t) {
            $t->id();
            $t->string('email_hash', 64)->index();                       // sha256 van het e-mailadres (kleine letters)
            $t->string('type', 10);                                      // export | erase
            $t->string('source', 10)->default('customer');               // customer (via e-mail verificatie) | admin
            $t->string('status', 12)->default('pending');                // pending | completed
            $t->jsonb('result')->nullable();                             // samenvatting van wat is gedaan (geen persoonsgegevens)
            $t->timestamp('requested_at');
            $t->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_requests');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('anonymized_at'));
    }
};
