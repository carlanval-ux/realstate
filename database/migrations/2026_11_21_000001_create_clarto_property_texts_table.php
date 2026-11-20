<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public texts of a property per language (es, en, tr).
 *
 * The Castilian title and description stay in real_estate_properties (title, description), which every
 * module reads; this table holds what the property model has no column for (short description, SEO)
 * and the English and Turkish versions. One row per property and language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_property_texts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained('real_estate_properties')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title')->nullable();
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 170)->nullable();
            // manual (typed by the team) | ia (machine proposal, pending review). See docs/deuda-tecnica.md.
            $table->string('source', 10)->default('manual');
            $table->timestamps();
            $table->unique(['property_id', 'locale']);
        });

        // Users never chose "en": it is the column default of the profiles module. CLARTO works in Castilian.
        DB::table('users')->where('locale', 'en')->update(['locale' => 'es']);
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_property_texts');
    }
};
