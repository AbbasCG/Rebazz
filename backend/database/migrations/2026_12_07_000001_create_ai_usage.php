<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Gebruik van de AI-functies in het beheer: wie, welke functie, hoeveel tokens. Bevat nooit de inhoud van de teksten. */
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('feature', 30);
            $t->string('model', 60);
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->boolean('ok')->default(true);
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
