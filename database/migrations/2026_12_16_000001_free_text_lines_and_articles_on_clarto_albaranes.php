<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Albarán lines are free text (concept, quantity, price, VAT %), with no predefined catalogue. Articles are created
 * by hand to reuse a concept: picking one fills the line, which can still be changed. An article may be linked to its
 * product in Factúralo (external_id) once it has been sent there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_billing_articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('reference', 30)->nullable();           // optional; Factúralo product reference when sent
            $table->string('name', 500);
            $table->decimal('unit_price', 12, 2)->default(0);       // before taxes
            $table->decimal('vat_pct', 5, 2)->default(21);
            $table->boolean('requires_conformity')->default(false); // client signs before the albarán is sent
            $table->boolean('active')->default(true);
            $table->string('external_id', 80)->nullable();          // product id in Factúralo
            $table->timestamps();
            $table->unique(['team_id', 'reference']);
        });

        Schema::table('clarto_albaran_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('article_id')->nullable()->after('sort')->index();
            $table->decimal('vat_pct', 5, 2)->default(21)->after('discount_pct');
            $table->boolean('requires_conformity')->default(false)->after('vat_pct');
        });
    }

    public function down(): void
    {
        Schema::table('clarto_albaran_lines', function (Blueprint $table): void {
            $table->dropIndex(['article_id']);
            $table->dropColumn(['article_id', 'vat_pct', 'requires_conformity']);
        });
        Schema::dropIfExists('clarto_billing_articles');
    }
};
