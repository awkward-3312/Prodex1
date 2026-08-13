<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreatePaymentSettingsTable extends Migration
{
    public function up()
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->increments('id');

            // Stripe (tenant's own account, used by POS / sales / storefront)
            $table->string('stripe_key')->nullable();
            $table->text('stripe_secret')->nullable();

            $table->timestamps();
        });

        // Until now every tenant shared the Stripe keys stored in the central
        // .env, so copy those into the tenant row to keep the tenant that was
        // actively taking card payments working after the switch to
        // per-tenant storage. env() returns null here when config is cached —
        // the row is still created and the tenant just re-saves its keys.
        DB::table('payment_settings')->insert([
            'stripe_key' => env('STRIPE_KEY'),
            'stripe_secret' => env('STRIPE_SECRET'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('payment_settings');
    }
}
