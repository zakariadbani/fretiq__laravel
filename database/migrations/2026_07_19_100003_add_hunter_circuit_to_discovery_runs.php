<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table): void {
            $table->boolean('hunter_circuit_open')
                ->default(false)
                ->after('contact_consumed');
        });
    }

    public function down(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table): void {
            $table->dropColumn('hunter_circuit_open');
        });
    }
};
