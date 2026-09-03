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
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained();
            $table->string('external_import_id');
            $table->dateTime('sent_at')->comment('When the supplier generated the import (UTC)');
            $table->string('status', 16)->default('pending');
            $table->json('payload')->comment('The offers array exactly as the supplier sent it; the job reads from here');
            $table->unsignedInteger('total_offers');
            $table->unsignedInteger('processed_offers')->default(0);
            $table->text('error')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'external_import_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
