<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Dubbele opt-in (AVG/ePrivacy): alleen bevestigde adressen ontvangen mail; confirmed_at is het bewijs van toestemming. */
    public function up(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $t) {
            $t->timestamp('confirmed_at')->nullable()->after('lang');
            $t->string('confirm_token', 64)->nullable()->unique()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $t) {
            $t->dropColumn(['confirmed_at', 'confirm_token']);
        });
    }
};
