<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** GPSR (EU-productveiligheidsverordening): fabrikant, in de EU gevestigde verantwoordelijke en veiligheidsinformatie. */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('manufacturer', 300)->default('')->after('features');
            $t->string('eu_responsible', 300)->default('')->after('manufacturer');
            $t->text('safety_info')->nullable()->after('eu_responsible');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn(['manufacturer', 'eu_responsible', 'safety_info']);
        });
    }
};
