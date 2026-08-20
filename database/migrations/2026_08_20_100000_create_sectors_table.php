<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sectors — canonical FR discovery-taxonomy lookup table. Referenced by the
     * discovery/segment filter UI (label list); use_in_discovery gates whether a
     * sector is offered as a discovery target, independent of is_active.
     */
    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('use_in_discovery')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sectors');
    }
};
