<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('property_id')->constrained();
            $table->foreignId('import_id')->comment('The last import that wrote this row')->constrained();
            $table->string('external_id');
            $table->dateTime('sent_at')->comment('sent_at of the import that last wrote this row; older imports must not overwrite it');
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedInteger('max_guests');
            $table->unsignedBigInteger('price')->comment('Minor units, e.g. 72500 = 725.00');
            $table->char('currency', 3);
            $table->unsignedInteger('available_units')->comment('Owned by the supplier: written by imports only');
            $table->unsignedInteger('reserved_units')->default(0)->comment('Owned by the application: written by reservations only');
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->unique(['supplier_id', 'external_id']);

            // Serves both access paths of the property search: an equality lookup on the two dates
            // when the search starts from them, and a three-column equality lookup when the
            // city filter narrows properties first (property_id then comes from the join).
            // Checked with EXPLAIN: a mirrored (property_id, check_in, check_out, price)
            // index was never chosen over this one, so it is not kept. MySQL always sorts
            // for the window function regardless of index order; the sort covers only the
            // rows matching the dates. The foreign key adds its own index on property_id.
            $table->index(['check_in', 'check_out', 'property_id', 'price']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
