<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('segments') && Schema::hasColumn('segments', 'filter')) {
            $legacyIds = [];
            DB::table('segments')->select(['id', 'filter'])->orderBy('id')->chunkById(250, function ($segments) use (&$legacyIds): void {
                foreach ($segments as $segment) {
                    $filter = is_string($segment->filter) ? json_decode($segment->filter, true) : $segment->filter;
                    if (is_array($filter) && array_key_exists('status', $filter)) {
                        $legacyIds[] = (int) $segment->id;
                    }
                }
            });
            if ($legacyIds !== []) {
                throw new RuntimeException('Migration bloquée : convertir filter.status en filter.lifecycle_state pour les segments '.implode(', ', $legacyIds).'.');
            }
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex('contacts_status_index');
            $table->dropColumn(['status', 'legal_basis', 'consent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('status', 16)->default('new')->index();
            $table->string('legal_basis', 24)->default('unknown');
            $table->dateTime('consent_at')->nullable();
        });
    }
};
