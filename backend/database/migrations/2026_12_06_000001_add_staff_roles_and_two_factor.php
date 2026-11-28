<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Personeelsaccounts met rollen en tweestapsverificatie (TOTP + herstelcodes), plus een activiteitenlogboek van het beheer.
     * Bestaande gebruiker (de eigenaar via ADMIN_PASSWORD) wordt "owner".
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 12)->default('owner');                  // owner | manager | support | marketing
            $t->boolean('active')->default(true);
            $t->text('two_factor_secret')->nullable();                 // versleuteld (encrypted cast)
            $t->text('two_factor_pending')->nullable();                // geheim tijdens het instellen, nog niet bevestigd
            $t->timestamp('two_factor_enabled_at')->nullable();
            $t->text('two_factor_recovery')->nullable();               // JSON met HMAC's van de herstelcodes (de codes zelf worden niet bewaard)
            $t->unsignedBigInteger('two_factor_last_step')->nullable(); // laatst gebruikte 30-seconden-stap: een code werkt maar één keer
            $t->timestamp('last_login_at')->nullable();
        });
        Schema::create('admin_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('user_label', 120)->nullable();                  // blijft leesbaar nadat het account is verwijderd
            $t->string('action', 80);
            $t->string('detail', 300)->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['role', 'active', 'two_factor_secret', 'two_factor_pending', 'two_factor_enabled_at', 'two_factor_recovery', 'two_factor_last_step', 'last_login_at']);
        });
    }
};
