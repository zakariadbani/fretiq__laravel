<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * SHELL table for Sprint 1. Columns named group_name / setting_key to avoid
     * MySQL reserved words "group" and "key".
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group_name', 32);
            $table->string('setting_key', 100);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['group_name', 'setting_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
