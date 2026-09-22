<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A snapshot of the offer at booking time: a later import may move or reschedule it.
     *
     * Added nullable, backfilled from the offers' current state, then made required, so it
     * also runs on a table that already holds reservations.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable()->after('customer_email');
            $table->date('check_in')->nullable()->after('property_id');
            $table->date('check_out')->nullable()->after('check_in');
        });

        DB::table('reservations')
            ->join('offers', 'offers.id', '=', 'reservations.offer_id')
            ->update([
                'reservations.property_id' => DB::raw('offers.property_id'),
                'reservations.check_in' => DB::raw('offers.check_in'),
                'reservations.check_out' => DB::raw('offers.check_out'),
            ]);

        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable(false)->change();
            $table->date('check_in')->nullable(false)->change();
            $table->date('check_out')->nullable(false)->change();

            $table->foreign('property_id')->references('id')->on('properties');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['property_id']);
            $table->dropColumn(['property_id', 'check_in', 'check_out']);
        });
    }
};
