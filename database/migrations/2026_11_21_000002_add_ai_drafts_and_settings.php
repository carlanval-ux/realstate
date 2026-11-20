<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI drafting of public texts:
 * - clarto_property_texts gets a status (aprobado | borrador): the website only reads approved texts;
 *   an AI proposal is a borrador row for the same property and language, editable until approved.
 * - review_required_at flags an approved en/tr text whose Castilian original changed afterwards.
 * - clarto_ai_settings holds the provider, models and keys (encrypted with APP_KEY, never sent to the browser).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clarto_property_texts', function (Blueprint $table): void {
            $table->string('status', 10)->default('aprobado')->after('locale');
            $table->timestamp('review_required_at')->nullable();
            $table->string('model', 120)->nullable();
            $table->text('notes')->nullable();
        });

        Schema::table('clarto_property_texts', function (Blueprint $table): void {
            $table->dropUnique(['property_id', 'locale']);
            $table->unique(['property_id', 'locale', 'status']);
        });

        Schema::create('clarto_ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 20)->default('openrouter');
            $table->text('openrouter_api_key')->nullable();
            $table->text('openai_api_key')->nullable();
            $table->string('writing_model', 120)->nullable();
            $table->string('translation_model', 120)->nullable();
            $table->string('paid_fallback_model', 120)->nullable();
            $table->boolean('paid_fallback_authorized')->default(false);
            $table->string('paid_fallback_authorized_by')->nullable();
            $table->timestamp('paid_fallback_authorized_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_ai_settings');
        Schema::table('clarto_property_texts', function (Blueprint $table): void {
            $table->dropUnique(['property_id', 'locale', 'status']);
            $table->unique(['property_id', 'locale']);
            $table->dropColumn(['status', 'review_required_at', 'model', 'notes']);
        });
    }
};
