<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sprint 3a — campaign foundation.
     * Stores reusable email templates (subject + HTML body) that campaigns reference.
     */
    public function up(): void
    {
        Schema::create('campaign_templates', function (Blueprint $table) {
            $table->id();

            $table->string('name', 255);
            $table->string('subject', 255);

            // html_content: full HTML body — longText to accommodate rich layouts
            $table->longText('html_content');

            $table->string('preview_text', 255)->nullable();
            $table->string('thumbnail_path', 255)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_templates');
    }
};
