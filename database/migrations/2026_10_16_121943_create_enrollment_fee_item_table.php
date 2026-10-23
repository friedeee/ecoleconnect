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
        // Optional fees (uniform, canteen, transport…) taken by an enrolled student.
        Schema::create('enrollment_fee_item', function (Blueprint $table) {
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['enrollment_id', 'fee_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollment_fee_item');
    }
};
