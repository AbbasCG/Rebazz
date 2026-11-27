<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Factuurnummers en creditnota's (doorlopend, uniek) en het tijdstip van de orderbevestiging. */
    public function up(): void
    {
        Schema::create('document_counters', function (Blueprint $t) {
            $t->string('key', 40)->primary(); // bijv. invoice-2026
            $t->unsignedInteger('last')->default(0);
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->string('invoice_number', 30)->nullable()->unique()->after('order_no');
            $t->timestamp('invoice_issued_at')->nullable()->after('invoice_number');
            $t->string('credit_note_number', 30)->nullable()->unique()->after('invoice_issued_at');
            $t->timestamp('credit_note_issued_at')->nullable()->after('credit_note_number');
            $t->timestamp('confirmation_sent_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['invoice_number', 'invoice_issued_at', 'credit_note_number', 'credit_note_issued_at', 'confirmation_sent_at']);
        });
        Schema::dropIfExists('document_counters');
    }
};
