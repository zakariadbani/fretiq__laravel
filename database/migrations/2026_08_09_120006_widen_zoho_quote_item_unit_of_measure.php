<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_quote_items', function (Blueprint $table): void {
            $table->string('unit_of_measure', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: narrowing this column could truncate
        // verified Zoho values that are valid at the widened 255-char limit.
    }
};
