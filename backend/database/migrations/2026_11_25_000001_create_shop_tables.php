<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('sku', 60)->unique();
            $t->string('name', 200);
            $t->string('category', 60);
            $t->decimal('price', 10, 2);
            $t->decimal('compare_price', 10, 2)->nullable();
            $t->decimal('cost', 10, 2)->default(0);
            $t->string('image', 500)->default('');
            $t->string('icon', 30)->default('package');
            $t->unsignedSmallInteger('hue')->default(220);
            $t->string('supplier', 100)->default('');
            $t->string('supplier_url', 500)->default('');
            $t->string('cj_vid', 80)->default('');
            $t->string('cj_from', 2)->default('CN');
            $t->string('cj_logistic', 80)->default('');
            $t->string('warehouse', 60)->default('');
            $t->unsignedSmallInteger('days_min')->default(3);
            $t->unsignedSmallInteger('days_max')->default(7);
            $t->integer('stock')->default(0);
            $t->decimal('rating', 3, 2)->default(0);
            $t->unsignedInteger('reviews_count')->default(0);
            $t->string('status', 10)->default('draft')->index();
            $t->text('description')->nullable();
            $t->jsonb('features')->default('[]');
            $t->timestamps();
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_no', 30)->unique();
            $t->string('stripe_session_id', 120)->unique();
            $t->string('payment_intent_id', 120)->nullable()->index();
            $t->boolean('livemode')->default(false);
            $t->string('status', 20)->default('paid')->index(); // paid | refunded
            $t->string('customer_name', 200)->default('');
            $t->string('email', 200)->default('');
            $t->string('phone', 50)->default('');
            $t->string('country', 2)->default('');
            $t->jsonb('address')->default('{}');
            $t->string('lang', 5)->default('nl');
            $t->decimal('subtotal', 10, 2)->default(0);
            $t->decimal('discount', 10, 2)->default(0);
            $t->decimal('shipping', 10, 2)->default(0);
            $t->decimal('total', 10, 2)->default(0);
            $t->decimal('cost', 10, 2)->default(0);
            $t->string('payment_method', 40)->default('');
            $t->string('ship_method', 20)->default('standard');
            $t->unsignedTinyInteger('step')->default(0);
            $t->string('carrier', 60)->default('');
            $t->string('tracking', 450)->default('');
            $t->boolean('delayed')->default(false);
            $t->string('cj_status', 20)->default('');
            $t->string('cj_order_ids', 300)->default('');
            $t->string('cj_error', 500)->default('');
            $t->boolean('cj_wait')->default(false)->index();
            $t->timestamp('paid_at')->nullable()->index();
            $t->timestamps();
        });

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('product_id', 40)->nullable();
            $t->string('sku', 60)->default('');
            $t->string('name', 200);
            $t->decimal('price', 10, 2);
            $t->decimal('cost', 10, 2)->default(0);
            $t->unsignedSmallInteger('qty');
            $t->string('supplier', 100)->default('');
            $t->string('supplier_url', 500)->default('');
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->jsonb('value')->nullable();
            $t->timestamps();
        });

        Schema::create('newsletter_subscribers', function (Blueprint $t) {
            $t->id();
            $t->string('email', 200)->unique();
            $t->string('lang', 5)->default('nl');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');
    }
};
