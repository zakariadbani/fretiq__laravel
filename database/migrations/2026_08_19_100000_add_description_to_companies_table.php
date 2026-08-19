<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds companies.description — the exhibitor/company blurb carried from a
     * pasted or CSV-imported list (CompanyListParser) through to the promoted
     * Company row, so it survives parsing → batch item → CRUD detail/search.
     *
     * DO NOT RUN automatically — user runs `php artisan migrate` explicitly.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->text('description')->nullable()->after('sector');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
