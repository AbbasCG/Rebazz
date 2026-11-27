<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Retourverzoeken (RMA): de klant vraagt aan, de winkel beoordeelt, ontvangt en betaalt (deels) terug met creditnota. */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->timestamp('delivered_at')->nullable()->after('paid_at'); // start van de retourtermijn
        });
        DB::table('orders')->where('step', '>=', 4)->whereNull('delivered_at')->update(['delivered_at' => DB::raw('updated_at')]);

        Schema::create('order_returns', function (Blueprint $t) {
            $t->id();
            $t->string('rma_number', 30)->unique();                       // RMA-2026-00001
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20)->default('requested')->index();      // requested | approved | rejected | received | refunded
            $t->string('reason', 30);                                     // defective | damaged | wrong_item | not_as_described | changed_mind | other
            $t->text('comment')->nullable();                              // toelichting van de klant
            $t->text('admin_note')->nullable();                           // instructies of reden van afwijzing (zichtbaar voor de klant)
            $t->string('return_tracking', 120)->default('');
            $t->decimal('refund_amount', 10, 2)->nullable();
            $t->string('refund_reference', 120)->default('');             // Stripe-refund-id
            $t->string('credit_note_number', 30)->nullable()->unique();
            $t->timestamp('credit_note_issued_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamp('refunded_at')->nullable();
            $t->timestamps();
        });

        Schema::create('order_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_return_id')->constrained('order_returns')->cascadeOnDelete();
            $t->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $t->unsignedSmallInteger('qty');
            $t->unsignedSmallInteger('restocked')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('delivered_at'));
    }
};
