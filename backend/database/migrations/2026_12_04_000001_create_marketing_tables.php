<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marketing met toestemming:
     * - abandoned_carts: één herinnering per verlaten Stripe-checkout, alleen als de klant daar zelf toestemming gaf (consent.promotions = opt_in).
     * - newsletter_campaigns / newsletter_deliveries: nieuwsbrieven aan bevestigde abonnees; de aflevering per adres voorkomt dubbel versturen.
     */
    public function up(): void
    {
        Schema::create('abandoned_carts', function (Blueprint $t) {
            $t->id();
            $t->string('session_id', 120)->unique();
            $t->string('email', 200)->index();
            $t->string('lang', 5)->default('nl');
            $t->text('recovery_url')->nullable();          // door Stripe aangemaakte link om de kassa te hervatten (30 dagen geldig)
            $t->timestamp('checkout_started_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });
        Schema::create('newsletter_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('subject', 150);
            $t->text('body');
            $t->string('cta_label', 60)->nullable();
            $t->string('cta_url', 500)->nullable();
            $t->string('lang', 5)->nullable();           // null = alle talen
            $t->string('status', 12)->default('draft');   // draft | sending | sent
            $t->unsignedInteger('sent_count')->default(0);
            $t->unsignedInteger('failed_count')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        Schema::create('newsletter_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained('newsletter_campaigns')->cascadeOnDelete();
            $t->string('email', 200);
            $t->boolean('ok')->default(true);
            $t->timestamp('created_at')->nullable();
            $t->unique(['campaign_id', 'email']);
            $t->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_deliveries');
        Schema::dropIfExists('newsletter_campaigns');
        Schema::dropIfExists('abandoned_carts');
    }
};
