<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('registrable_domain', 191)
                ->charset('ascii')
                ->collation('ascii_general_ci')
                ->nullable()
                ->index();
        });

        Schema::table('prospect_criteria', function (Blueprint $table): void {
            $table->json('hunter_discover_filters')->nullable();
            $table->char('hunter_discover_prompt_hash', 64)->nullable();
            $table->unsignedInteger('hunter_discover_offset')->default(0);
            $table->boolean('hunter_discover_exhausted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('prospect_criteria', function (Blueprint $table): void {
            $table->dropColumn([
                'hunter_discover_filters',
                'hunter_discover_prompt_hash',
                'hunter_discover_offset',
                'hunter_discover_exhausted',
            ]);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['registrable_domain']);
            $table->dropColumn('registrable_domain');
        });
    }
};
